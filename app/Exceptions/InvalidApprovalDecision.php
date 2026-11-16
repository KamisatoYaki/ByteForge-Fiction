<?php

namespace App\Exceptions;

use App\Enums\ApprovalDecision;
use Exception;
use Illuminate\Database\Eloquent\Model;

/**
 * Thrown when a workflow approval decision cannot be recorded because the
 * business entity is not eligible (draft, already decided, invalid
 * transition, etc.).
 */
class InvalidApprovalDecision extends Exception
{
    public function __construct(
        public readonly Model $approvable,
        public readonly ?ApprovalDecision $decision = null,
        public readonly string $reason = 'ineligible',
    ) {
        $decisionLabel = $decision?->value ?? 'decision';

        parent::__construct(
            "Cannot record {$decisionLabel} for ".class_basename($approvable)
            ." #{$approvable->getKey()} ({$reason})."
        );
    }
}
