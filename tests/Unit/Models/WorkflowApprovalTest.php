<?php

namespace Tests\Unit\Models;

use App\Enums\ApprovalDecision;
use App\Models\User;
use App\Models\WorkflowApproval;
use LogicException;
use Tests\Support\Concerns\CreatesApprovableTestSubjects;
use Tests\TestCase;

class WorkflowApprovalTest extends TestCase
{
    use CreatesApprovableTestSubjects;

    public function test_belongs_to_deciding_user(): void
    {
        $decider = User::factory()->create();
        $subject = $this->makeApprovableTestSubject([
            'requested_by' => User::factory()->create()->id,
            'status' => 'submitted',
        ]);

        $approval = new WorkflowApproval;
        $approval->approvable()->associate($subject);
        $approval->decision = ApprovalDecision::Approved;
        $approval->decided_by = $decider->id;
        $approval->decided_at = now();
        $approval->save();

        $this->assertTrue($approval->decidedBy->is($decider));
    }

    public function test_approvable_can_retrieve_polymorphic_approval_history(): void
    {
        $decider = User::factory()->create();
        $subject = $this->makeApprovableTestSubject([
            'requested_by' => User::factory()->create()->id,
        ]);

        $approval = new WorkflowApproval;
        $approval->approvable()->associate($subject);
        $approval->decision = ApprovalDecision::Rejected;
        $approval->decided_by = $decider->id;
        $approval->decided_at = now();
        $approval->comments = 'Needs clarification';
        $approval->save();

        $history = $subject->fresh()->workflowApprovals;

        $this->assertCount(1, $history);
        $this->assertTrue($history->first()->is($approval));
        $this->assertSame(ApprovalDecision::Rejected, $history->first()->decision);
        $this->assertSame('Needs clarification', $history->first()->comments);
    }

    public function test_decision_values_are_cast_to_enum(): void
    {
        $approval = new WorkflowApproval;
        $approval->approvable()->associate($this->makeApprovableTestSubject());
        $approval->decision = ApprovalDecision::Approved;
        $approval->decided_by = User::factory()->create()->id;
        $approval->decided_at = now();
        $approval->save();

        $this->assertInstanceOf(ApprovalDecision::class, $approval->fresh()->decision);
        $this->assertSame('approved', $approval->fresh()->decision->value);
    }

    public function test_decided_by_and_decided_at_are_not_mass_assignable(): void
    {
        $forged = User::factory()->create();

        $approval = new WorkflowApproval([
            'comments' => 'hello',
            'decided_by' => $forged->id,
            'decision' => ApprovalDecision::Approved->value,
            'decided_at' => now()->subDay()->toDateTimeString(),
            'approvable_type' => 'Tests\\Support\\Models\\ApprovableTestSubject',
            'approvable_id' => 1,
        ]);

        $this->assertNull($approval->decided_by);
        $this->assertNull($approval->decision);
        $this->assertNull($approval->decided_at);
        $this->assertNull($approval->approvable_id);
        $this->assertSame('hello', $approval->comments);
    }

    public function test_approvals_cannot_be_updated(): void
    {
        $approval = new WorkflowApproval;
        $approval->approvable()->associate($this->makeApprovableTestSubject());
        $approval->decision = ApprovalDecision::Approved;
        $approval->decided_by = User::factory()->create()->id;
        $approval->decided_at = now();
        $approval->save();

        $this->expectException(LogicException::class);
        $approval->comments = 'tamper';
        $approval->save();
    }

    public function test_approvals_cannot_be_deleted(): void
    {
        $approval = new WorkflowApproval;
        $approval->approvable()->associate($this->makeApprovableTestSubject());
        $approval->decision = ApprovalDecision::Approved;
        $approval->decided_by = User::factory()->create()->id;
        $approval->decided_at = now();
        $approval->save();

        $this->expectException(LogicException::class);
        $approval->delete();
    }
}
