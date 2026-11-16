<?php

namespace App\Policies;

use App\Models\Company;
use App\Models\PurchaseRequest;
use App\Models\User;
use Illuminate\Auth\Access\HandlesAuthorization;

/**
 * Authorization for ByteForge Purchase Requests.
 *
 * Uses dedicated purchase_requests.* keys only (no assets.* fallback).
 * Self-approval is enforced in RecordWorkflowApprovalAction, not here.
 */
final class PurchaseRequestPolicy
{
    use HandlesAuthorization;

    public function before(User $user, string $ability, mixed $item = null): ?bool
    {
        // Draft-only lifecycle gates apply even when admin short-circuits.
        if (in_array($ability, ['update', 'submit', 'delete'], true)
            && $item instanceof PurchaseRequest
            && ! $item->isDraft()
        ) {
            return false;
        }

        // Approve must reach the ability method so FMCS applies.
        // Self-approval remains enforced in RecordWorkflowApprovalAction.
        if ($ability === 'approve') {
            return null;
        }

        if ($user->hasAccess('admin')) {
            return true;
        }

        return null;
    }

    public function viewAny(User $user): bool
    {
        return $this->index($user);
    }

    public function index(User $user): bool
    {
        return $user->hasAccess('purchase_requests.view')
            || $user->hasAccess('purchase_requests.create')
            || $user->hasAccess('purchase_requests.edit')
            || $user->hasAccess('purchase_requests.approve');
    }

    public function view(User $user, ?PurchaseRequest $purchaseRequest = null): bool
    {
        if (! $purchaseRequest instanceof PurchaseRequest) {
            return $user->hasAccess('purchase_requests.view')
                || $user->hasAccess('purchase_requests.approve')
                || $user->hasAccess('purchase_requests.create');
        }

        if (! Company::isCurrentUserHasAccess($purchaseRequest)) {
            return false;
        }

        if ((int) $purchaseRequest->requested_by === (int) $user->id
            && ($user->hasAccess('purchase_requests.create')
                || $user->hasAccess('purchase_requests.edit')
                || $user->hasAccess('purchase_requests.view'))
        ) {
            return true;
        }

        if ($user->hasAccess('purchase_requests.view')) {
            return true;
        }

        if ($user->hasAccess('purchase_requests.approve')
            && $purchaseRequest->isEligibleForApprovalDecision()
        ) {
            return true;
        }

        return false;
    }

    public function create(User $user): bool
    {
        return $user->hasAccess('purchase_requests.create');
    }

    public function update(User $user, PurchaseRequest $purchaseRequest): bool
    {
        if (! $purchaseRequest->isDraft()) {
            return false;
        }

        if (! Company::isCurrentUserHasAccess($purchaseRequest)) {
            return false;
        }

        if ((int) $purchaseRequest->requested_by === (int) $user->id
            && $user->hasAccess('purchase_requests.create')
        ) {
            return true;
        }

        return $user->hasAccess('purchase_requests.edit');
    }

    public function submit(User $user, PurchaseRequest $purchaseRequest): bool
    {
        return $this->update($user, $purchaseRequest);
    }

    public function approve(User $user, mixed $purchaseRequest = null): bool
    {
        if ($purchaseRequest instanceof PurchaseRequest
            && ! Company::isCurrentUserHasAccess($purchaseRequest)
        ) {
            return false;
        }

        if ($user->isSuperUser()) {
            return true;
        }

        if ($user->hasAccess('admin')) {
            return true;
        }

        return $user->hasAccess('purchase_requests.approve');
    }

    public function delete(User $user, PurchaseRequest $purchaseRequest): bool
    {
        if (! $purchaseRequest->isDraft()) {
            return false;
        }

        if (! Company::isCurrentUserHasAccess($purchaseRequest)) {
            return false;
        }

        if ((int) $purchaseRequest->requested_by === (int) $user->id
            && $user->hasAccess('purchase_requests.create')
        ) {
            return true;
        }

        return $user->hasAccess('purchase_requests.edit');
    }
}
