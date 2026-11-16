<?php

namespace App\Exceptions;

use App\Enums\PurchaseRequestStatus;
use App\Models\PurchaseRequest;
use Exception;

class InvalidPurchaseRequestTransition extends Exception
{
    public function __construct(
        public readonly PurchaseRequest $purchaseRequest,
        public readonly string $reason,
        public readonly ?PurchaseRequestStatus $from = null,
    ) {
        parent::__construct(
            "Cannot transition PurchaseRequest #{$purchaseRequest->id} ({$reason})."
        );
    }
}
