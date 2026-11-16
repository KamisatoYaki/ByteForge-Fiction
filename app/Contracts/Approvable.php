<?php

namespace App\Contracts;

use App\Enums\ApprovalDecision;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphMany;

/**
 * Contract for future ByteForge business entities that participate in
 * the thin workflow-approval foundation (purchase requests, transfers,
 * disposals, etc.).
 *
 * The entity owns its status/lifecycle. WorkflowApproval rows are
 * append-only decision history only.
 */
interface Approvable
{
    /**
     * Append-only approval decision history.
     *
     * @return MorphMany<\App\Models\WorkflowApproval, covariant $this>
     */
    public function workflowApprovals(): MorphMany;

    /**
     * Authenticated requester who submitted this record.
     * Used for self-approval prevention — never trust a client-supplied value.
     */
    public function approvalRequesterId(): ?int;

    /**
     * Whether the entity's current lifecycle state may receive a decision.
     * Entities define this against their own status vocabulary.
     */
    public function isEligibleForApprovalDecision(): bool;

    /**
     * Domain permission key required to decide (e.g. purchase_requests.approve).
     */
    public function approvalAbility(): string;

    /**
     * Model used for FMCS / company access checks.
     * Usually $this when the entity is companyable; may be a parent.
     */
    public function approvalCompanyable(): Model;

    /**
     * Persist the entity status transition for this decision.
     * Called inside the same DB transaction as the approval row insert.
     */
    public function applyApprovalDecision(ApprovalDecision $decision, User $decider): void;
}
