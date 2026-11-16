<?php

namespace Tests\Feature\WorkflowApprovals;

use App\Actions\WorkflowApprovals\RecordWorkflowApprovalAction;
use App\Enums\ApprovalDecision;
use App\Exceptions\InvalidApprovalDecision;
use App\Exceptions\SelfApprovalNotAllowed;
use App\Models\Company;
use App\Models\User;
use App\Models\WorkflowApproval;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\Support\Concerns\CreatesApprovableTestSubjects;
use Tests\Support\Models\ApprovableTestSubject;
use Tests\TestCase;

class RecordWorkflowApprovalActionTest extends TestCase
{
    use CreatesApprovableTestSubjects;

    public function test_user_with_approve_permission_can_decide(): void
    {
        $requester = User::factory()->create();
        $approver = User::factory()->approvePurchaseRequests()->create();
        $subject = $this->makeApprovableTestSubject(['requested_by' => $requester->id]);

        $this->actingAs($approver);

        $approval = RecordWorkflowApprovalAction::run($subject, ApprovalDecision::Approved, 'Looks good');

        $this->assertSame(ApprovalDecision::Approved, $approval->decision);
        $this->assertSame($approver->id, $approval->decided_by);
        $this->assertSame('Looks good', $approval->comments);
        $this->assertSame('approved', $subject->fresh()->status);
    }

    public function test_user_without_approve_permission_is_denied(): void
    {
        $requester = User::factory()->create();
        $actor = User::factory()->create();
        $subject = $this->makeApprovableTestSubject(['requested_by' => $requester->id]);

        $this->actingAs($actor);

        $this->expectException(AuthorizationException::class);
        RecordWorkflowApprovalAction::run($subject, ApprovalDecision::Approved);
    }

    public function test_direct_user_deny_is_respected(): void
    {
        $requester = User::factory()->create();
        $actor = User::factory()->denyApprovePurchaseRequests()->create();
        $subject = $this->makeApprovableTestSubject(['requested_by' => $requester->id]);

        $this->actingAs($actor);

        $this->expectException(AuthorizationException::class);
        RecordWorkflowApprovalAction::run($subject, ApprovalDecision::Rejected);
    }

    public function test_administrator_can_approve_another_users_request(): void
    {
        $requester = User::factory()->create();
        $admin = User::factory()->admin()->create();
        $subject = $this->makeApprovableTestSubject(['requested_by' => $requester->id]);

        $this->actingAs($admin);

        $approval = RecordWorkflowApprovalAction::run($subject, ApprovalDecision::Approved);

        $this->assertSame($admin->id, $approval->decided_by);
        $this->assertSame('approved', $subject->fresh()->status);
    }

    public function test_administrator_cannot_approve_own_request(): void
    {
        $admin = User::factory()->admin()->create();
        $subject = $this->makeApprovableTestSubject(['requested_by' => $admin->id]);

        $this->actingAs($admin);

        $this->expectException(SelfApprovalNotAllowed::class);
        RecordWorkflowApprovalAction::run($subject, ApprovalDecision::Approved);
    }

    public function test_superuser_cannot_approve_own_request(): void
    {
        $superuser = User::factory()->superuser()->create();
        $subject = $this->makeApprovableTestSubject(['requested_by' => $superuser->id]);

        $this->actingAs($superuser);

        $this->expectException(SelfApprovalNotAllowed::class);
        RecordWorkflowApprovalAction::run($subject, ApprovalDecision::Rejected);
    }

    public function test_requester_cannot_approve_or_reject_own_request(): void
    {
        $requester = User::factory()->approvePurchaseRequests()->create();
        $subject = $this->makeApprovableTestSubject(['requested_by' => $requester->id]);

        $this->actingAs($requester);

        try {
            RecordWorkflowApprovalAction::run($subject, ApprovalDecision::Approved);
            $this->fail('Expected SelfApprovalNotAllowed for approve.');
        } catch (SelfApprovalNotAllowed) {
            // expected
        }

        $this->expectException(SelfApprovalNotAllowed::class);
        RecordWorkflowApprovalAction::run($subject, ApprovalDecision::Rejected);
    }

    public function test_decision_records_authenticated_user_id(): void
    {
        $requester = User::factory()->create();
        $approver = User::factory()->approvePurchaseRequests()->create();
        $subject = $this->makeApprovableTestSubject(['requested_by' => $requester->id]);

        $this->actingAs($approver);

        $approval = RecordWorkflowApprovalAction::run($subject, ApprovalDecision::Approved);

        $this->assertSame($approver->id, $approval->decided_by);
        $this->assertNotSame($requester->id, $approval->decided_by);
    }

    public function test_client_cannot_forge_decided_by_through_action(): void
    {
        $requester = User::factory()->create();
        $approver = User::factory()->approvePurchaseRequests()->create();
        $forged = User::factory()->create();
        $subject = $this->makeApprovableTestSubject(['requested_by' => $requester->id]);

        $this->actingAs($approver);

        $approval = RecordWorkflowApprovalAction::run($subject, ApprovalDecision::Approved);

        $this->assertSame($approver->id, $approval->decided_by);
        $this->assertNotSame($forged->id, $approval->decided_by);

        $params = array_map(
            static fn (\ReflectionParameter $param) => $param->getName(),
            (new \ReflectionMethod(RecordWorkflowApprovalAction::class, 'run'))->getParameters()
        );

        $this->assertSame(['approvable', 'decision', 'comments'], $params);
        $this->assertNotContains('decided_by', $params);
        $this->assertNotContains('decided_at', $params);
        $this->assertNotContains('actor', $params);
    }

    public function test_client_cannot_forge_decided_at(): void
    {
        Carbon::setTestNow('2026-11-16 12:00:00');

        $requester = User::factory()->create();
        $approver = User::factory()->approvePurchaseRequests()->create();
        $subject = $this->makeApprovableTestSubject(['requested_by' => $requester->id]);

        $this->actingAs($approver);

        $approval = RecordWorkflowApprovalAction::run($subject, ApprovalDecision::Approved);

        $this->assertTrue($approval->decided_at->equalTo(Carbon::parse('2026-11-16 12:00:00')));

        Carbon::setTestNow();
    }

    public function test_duplicate_decisions_are_rejected(): void
    {
        $requester = User::factory()->create();
        $approver = User::factory()->approvePurchaseRequests()->create();
        $subject = $this->makeApprovableTestSubject(['requested_by' => $requester->id]);

        $this->actingAs($approver);
        RecordWorkflowApprovalAction::run($subject, ApprovalDecision::Approved);

        $this->expectException(InvalidApprovalDecision::class);
        RecordWorkflowApprovalAction::run($subject->fresh(), ApprovalDecision::Approved);
    }

    public function test_draft_records_cannot_be_decided(): void
    {
        $requester = User::factory()->create();
        $approver = User::factory()->approvePurchaseRequests()->create();
        $subject = $this->makeApprovableTestSubject([
            'requested_by' => $requester->id,
            'status' => 'draft',
        ]);

        $this->actingAs($approver);

        $this->expectException(InvalidApprovalDecision::class);
        RecordWorkflowApprovalAction::run($subject, ApprovalDecision::Approved);
    }

    public function test_rejected_records_cannot_be_silently_reopened(): void
    {
        $requester = User::factory()->create();
        $approver = User::factory()->approvePurchaseRequests()->create();
        $subject = $this->makeApprovableTestSubject(['requested_by' => $requester->id]);

        $this->actingAs($approver);
        RecordWorkflowApprovalAction::run($subject, ApprovalDecision::Rejected);

        $this->assertSame('rejected', $subject->fresh()->status);

        $this->expectException(InvalidApprovalDecision::class);
        RecordWorkflowApprovalAction::run($subject->fresh(), ApprovalDecision::Approved);
    }

    public function test_failed_status_transition_leaves_no_approval_row(): void
    {
        $requester = User::factory()->create();
        $approver = User::factory()->approvePurchaseRequests()->create();
        $subject = $this->makeApprovableTestSubject([
            'requested_by' => $requester->id,
            'fail_status_transition' => true,
        ]);

        $this->actingAs($approver);

        try {
            RecordWorkflowApprovalAction::run($subject, ApprovalDecision::Approved);
            $this->fail('Expected transition failure.');
        } catch (\RuntimeException $e) {
            $this->assertSame('Simulated status transition failure.', $e->getMessage());
        }

        $this->assertSame(0, WorkflowApproval::query()->count());
        $this->assertSame('submitted', $subject->fresh()->status);
    }

    public function test_action_runs_inside_a_database_transaction(): void
    {
        $requester = User::factory()->create();
        $approver = User::factory()->approvePurchaseRequests()->create();
        $subject = $this->makeApprovableTestSubject(['requested_by' => $requester->id]);
        $sawNestedTransaction = false;

        $this->actingAs($approver);

        ApprovableTestSubject::saving(function () use (&$sawNestedTransaction) {
            $sawNestedTransaction = DB::transactionLevel() > 0;
        });

        RecordWorkflowApprovalAction::run($subject, ApprovalDecision::Approved);

        $this->assertTrue($sawNestedTransaction, 'Status transition must run inside the action transaction.');
    }

    public function test_user_cannot_decide_outside_authorized_company_scope(): void
    {
        [$companyA, $companyB] = Company::factory()->count(2)->create();
        $requester = User::factory()->create();
        $approver = User::factory()->forCompany($companyB)->approvePurchaseRequests()->create();
        $subject = $this->makeApprovableTestSubject([
            'requested_by' => $requester->id,
            'company_id' => $companyA->id,
        ]);

        $this->settings->enableMultipleFullCompanySupport();
        $this->actingAs($approver);

        $this->expectException(AuthorizationException::class);
        RecordWorkflowApprovalAction::run($subject, ApprovalDecision::Approved);
    }

    public function test_approval_history_access_follows_parent_company_authorization(): void
    {
        [$companyA, $companyB] = Company::factory()->count(2)->create();
        $requester = User::factory()->create();
        $approver = User::factory()->forCompany($companyA)->approvePurchaseRequests()->create();
        $outsider = User::factory()->forCompany($companyB)->approvePurchaseRequests()->create();
        $subject = $this->makeApprovableTestSubject([
            'requested_by' => $requester->id,
            'company_id' => $companyA->id,
        ]);

        $this->settings->enableMultipleFullCompanySupport();
        $this->actingAs($approver);

        $approval = RecordWorkflowApprovalAction::run($subject, ApprovalDecision::Approved);

        $this->actingAs($approver);
        $this->assertTrue($approval->isAccessibleToCurrentUser());

        $this->actingAs($outsider);
        $this->assertFalse($approval->fresh()->isAccessibleToCurrentUser());
    }

    public function test_rejection_decision_updates_status(): void
    {
        $requester = User::factory()->create();
        $approver = User::factory()->approvePurchaseRequests()->create();
        $subject = $this->makeApprovableTestSubject(['requested_by' => $requester->id]);

        $this->actingAs($approver);

        $approval = RecordWorkflowApprovalAction::run($subject, ApprovalDecision::Rejected, 'Insufficient detail');

        $this->assertSame(ApprovalDecision::Rejected, $approval->decision);
        $this->assertSame('rejected', $subject->fresh()->status);
        $this->assertSame(1, $subject->workflowApprovals()->count());
    }
}
