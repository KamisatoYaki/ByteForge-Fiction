<?php

namespace App\Actions\WorkflowApprovals;

use App\Contracts\Approvable;
use App\Enums\ApprovalDecision;
use App\Exceptions\InvalidApprovalDecision;
use App\Exceptions\SelfApprovalNotAllowed;
use App\Models\Company;
use App\Models\User;
use App\Models\WorkflowApproval;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Single controlled write path for ByteForge managerial approve/reject.
 *
 * Enforces permission (domain *.approve via the entity), FMCS parent access,
 * universal self-approval denial, eligibility, actor stamping, and atomic
 * status transition. Does not modify User::hasAccess() or the permission catalog.
 */
class RecordWorkflowApprovalAction
{
    /**
     * @param  Approvable&Model  $approvable
     *
     * @throws AuthorizationException
     * @throws SelfApprovalNotAllowed
     * @throws InvalidApprovalDecision
     */
    public static function run(
        Approvable $approvable,
        ApprovalDecision $decision,
        ?string $comments = null,
    ): WorkflowApproval {
        if (! $approvable instanceof Model) {
            throw new \InvalidArgumentException('Approvable must be an Eloquent model.');
        }

        $actor = auth()->user();
        if (! $actor instanceof User) {
            throw new AuthorizationException;
        }

        if (! Company::isCurrentUserHasAccess($approvable->approvalCompanyable())) {
            throw new AuthorizationException;
        }

        self::assertAuthorizedToApprove($actor, $approvable->approvalAbility());

        if ($approvable->approvalRequesterId() === null) {
            throw new InvalidApprovalDecision(
                approvable: $approvable,
                decision: $decision,
                reason: 'missing_requester',
            );
        }

        if ((int) $approvable->approvalRequesterId() === (int) $actor->id) {
            throw new SelfApprovalNotAllowed($approvable);
        }

        return DB::transaction(function () use ($approvable, $decision, $comments, $actor) {
            /** @var (Approvable&Model)|null $locked */
            $locked = $approvable->newQuery()
                ->whereKey($approvable->getKey())
                ->lockForUpdate()
                ->first();

            if (! $locked instanceof Approvable) {
                throw new InvalidApprovalDecision(
                    approvable: $approvable,
                    decision: $decision,
                    reason: 'not_found',
                );
            }

            if (! $locked->isEligibleForApprovalDecision()) {
                throw new InvalidApprovalDecision(
                    approvable: $locked,
                    decision: $decision,
                    reason: 'ineligible_or_already_decided',
                );
            }

            if ($locked->approvalRequesterId() === null) {
                throw new InvalidApprovalDecision(
                    approvable: $locked,
                    decision: $decision,
                    reason: 'missing_requester',
                );
            }

            // Re-check self-approval against the locked row so a concurrent
            // requester change cannot bypass the guard.
            if ((int) $locked->approvalRequesterId() === (int) $actor->id) {
                throw new SelfApprovalNotAllowed($locked);
            }

            $approval = new WorkflowApproval;
            $approval->approvable()->associate($locked);
            $approval->decision = $decision;
            $approval->decided_by = $actor->id;
            $approval->decided_at = Carbon::now();
            $approval->comments = $comments;

            if (! $approval->save()) {
                throw new InvalidApprovalDecision(
                    approvable: $locked,
                    decision: $decision,
                    reason: 'approval_validation_failed',
                );
            }

            $locked->applyApprovalDecision($decision, $actor);

            if ($locked->isEligibleForApprovalDecision()) {
                // Entity failed to leave an eligible state — treat as failed
                // transition so the transaction rolls back the approval row.
                throw new InvalidApprovalDecision(
                    approvable: $locked,
                    decision: $decision,
                    reason: 'status_transition_failed',
                );
            }

            return $approval->fresh(['decidedBy']);
        });
    }

    /**
     * Preserve Snipe admin/superuser conventions for permission checks.
     * Explicit user-level -1 is honored for non-admin actors via hasAccess().
     * Self-approval is enforced separately and is never bypassed here.
     */
    private static function assertAuthorizedToApprove(User $actor, string $ability): void
    {
        if ($actor->isSuperUser()) {
            return;
        }

        if ($actor->hasAccess('admin')) {
            return;
        }

        if (! $actor->hasAccess($ability)) {
            throw new AuthorizationException;
        }
    }
}
