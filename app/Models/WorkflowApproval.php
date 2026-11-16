<?php

namespace App\Models;

use App\Contracts\Approvable;
use App\Enums\ApprovalDecision;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use LogicException;
use Watson\Validating\ValidatingTrait;

/**
 * Append-only managerial approval decision history.
 *
 * Linked polymorphically to an Approvable business entity. Status lives
 * on that entity; this row records the decision actor, outcome, and time.
 *
 * @property ApprovalDecision $decision
 * @mixin IdeHelperWorkflowApproval
 */
class WorkflowApproval extends Model
{
    use HasFactory;
    use ValidatingTrait;

    protected $table = 'workflow_approvals';

    /**
     * Only remarks may be mass-assigned. Actor, timestamp, decision, and
     * polymorphic keys are set server-side by RecordWorkflowApprovalAction.
     *
     * @var list<string>
     */
    protected $fillable = [
        'comments',
    ];

    /**
     * @var array<string, string>
     */
    protected $casts = [
        'decision' => ApprovalDecision::class,
        'decided_at' => 'datetime',
        'decided_by' => 'integer',
        'approvable_id' => 'integer',
    ];

    /**
     * @var array<string, string>
     */
    public $rules = [
        'approvable_type' => 'required|string',
        'approvable_id' => 'required|integer',
        'decision' => 'required|string|in:approved,rejected',
        'decided_by' => 'required|integer',
        'decided_at' => 'required|date',
        'comments' => 'nullable|string',
    ];

    protected static function booted(): void
    {
        static::updating(function (): void {
            throw new LogicException('Workflow approvals are append-only and cannot be updated.');
        });

        static::deleting(function (): void {
            throw new LogicException('Workflow approvals are append-only and cannot be deleted.');
        });
    }

    /**
     * @return MorphTo<Model, $this>
     */
    public function approvable(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function decidedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by');
    }

    /**
     * FMCS access follows the parent Approvable's companyable subject.
     * Approval history must not bypass parent authorization.
     */
    public function isAccessibleToCurrentUser(): bool
    {
        if (! Company::isFullMultipleCompanySupportEnabled()) {
            return true;
        }

        if (auth()->user()?->isSuperUser()) {
            return true;
        }

        $approvable = $this->approvable_type::withoutGlobalScopes()->find($this->approvable_id);

        if (! $approvable instanceof Approvable) {
            return false;
        }

        return Company::isCurrentUserHasAccess($approvable->approvalCompanyable());
    }
}
