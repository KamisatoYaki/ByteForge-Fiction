<?php

namespace App\Enums;

/**
 * Managerial approval outcomes for ByteForge workflow entities.
 *
 * Only approve and reject are supported in Phase 1. Return-for-revision
 * and multi-step routing are intentionally out of scope.
 */
enum ApprovalDecision: string
{
    case Approved = 'approved';
    case Rejected = 'rejected';

    public function label(): string
    {
        return match ($this) {
            self::Approved => trans('general.approved'),
            self::Rejected => trans('general.rejected'),
        };
    }

    public function isTerminal(): bool
    {
        return true;
    }
}
