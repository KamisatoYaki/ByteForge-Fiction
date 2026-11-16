<?php

namespace App\Actions\PurchaseRequests;

use App\Enums\ActionType;
use App\Models\Actionlog;
use App\Models\PurchaseRequest;
use App\Models\User;
use RuntimeException;

/**
 * Writes a single Actionlog row for a Purchase Request business event.
 *
 * company_id is taken from the Purchase Request itself (never from client input).
 * Failed persistence throws so the enclosing PR business transaction rolls back.
 */
class RecordPurchaseRequestActivityAction
{
    /**
     * @param  array<string, mixed>|null  $meta
     *
     * @throws RuntimeException When Actionlog::logaction() reports a failed save
     */
    public static function run(
        PurchaseRequest $purchaseRequest,
        ActionType $actionType,
        ?User $actor = null,
        ?string $note = null,
        ?array $meta = null,
    ): Actionlog {
        $actorId = $actor?->id ?? auth()->id();

        $log = new Actionlog;
        $log->item_type = PurchaseRequest::class;
        $log->item_id = $purchaseRequest->getKey();
        $log->created_by = $actorId;
        $log->company_id = $purchaseRequest->company_id;
        $log->note = $note;
        $log->action_date = now();

        if ($meta !== null && $meta !== []) {
            $log->log_meta = json_encode($meta);
        }

        // logaction() returns false on failed save without throwing. Convert that
        // into an exception so Create/Update/Submit/Delete/approval transactions
        // cannot commit without their required audit row. DB exceptions already propagate.
        if ($log->logaction($actionType) === false) {
            throw new RuntimeException(
                'Failed to persist Purchase Request Actionlog for action ['.$actionType->value.'].'
            );
        }

        return $log;
    }
}
