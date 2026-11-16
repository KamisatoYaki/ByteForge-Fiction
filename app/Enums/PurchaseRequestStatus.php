<?php

namespace App\Enums;

/**
 * Lifecycle for ByteForge PurchaseRequest records.
 *
 *   draft      Editable working copy.
 *   submitted  Locked; awaiting approve/reject.
 *   approved   Terminal after managerial approval.
 *   rejected   Terminal; requester must create a new PR.
 */
enum PurchaseRequestStatus: string
{
    case Draft = 'draft';
    case Submitted = 'submitted';
    case Approved = 'approved';
    case Rejected = 'rejected';

    public function label(): string
    {
        return match ($this) {
            self::Draft => trans('admin/purchase_requests/general.status_draft'),
            self::Submitted => trans('admin/purchase_requests/general.status_submitted'),
            self::Approved => trans('general.approved'),
            self::Rejected => trans('general.rejected'),
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Draft => 'default',
            self::Submitted => 'warning',
            self::Approved => 'success',
            self::Rejected => 'danger',
        };
    }

    public function isEditable(): bool
    {
        return $this === self::Draft;
    }

    public function isTerminal(): bool
    {
        return in_array($this, [self::Approved, self::Rejected], true);
    }

    public function isEligibleForApproval(): bool
    {
        return $this === self::Submitted;
    }
}
