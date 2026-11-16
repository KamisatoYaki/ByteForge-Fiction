<?php

namespace Tests\Support\Models;

use App\Contracts\Approvable;
use App\Enums\ApprovalDecision;
use App\Models\Traits\HasWorkflowApprovals;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

/**
 * Test-only Approvable fixture for the workflow approval foundation.
 * Not a production business module.
 */
class ApprovableTestSubject extends Model implements Approvable
{
    use HasWorkflowApprovals;

    protected $table = 'approvable_test_subjects';

    protected $fillable = [
        'status',
        'requested_by',
        'company_id',
        'approval_ability',
        'fail_status_transition',
    ];

    protected $casts = [
        'requested_by' => 'integer',
        'company_id' => 'integer',
        'fail_status_transition' => 'boolean',
    ];

    public function approvalRequesterId(): ?int
    {
        return $this->requested_by;
    }

    public function isEligibleForApprovalDecision(): bool
    {
        return $this->status === 'submitted';
    }

    public function approvalAbility(): string
    {
        return $this->approval_ability ?: 'purchase_requests.approve';
    }

    public function approvalCompanyable(): Model
    {
        return $this;
    }

    public function applyApprovalDecision(ApprovalDecision $decision, User $decider): void
    {
        if ($this->fail_status_transition) {
            throw new \RuntimeException('Simulated status transition failure.');
        }

        $this->status = match ($decision) {
            ApprovalDecision::Approved => 'approved',
            ApprovalDecision::Rejected => 'rejected',
        };
        $this->save();
    }
}
