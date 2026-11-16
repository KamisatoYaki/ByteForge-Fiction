<?php

namespace App\Actions\PurchaseRequests;

use App\Exceptions\InvalidPurchaseRequestTransition;
use App\Models\PurchaseRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class UpdatePurchaseRequestAction
{
    /**
     * @param  array{title: string, purpose?: ?string, department_id?: ?int, notes?: ?string, items: list<array<string, mixed>>}  $data
     *
     * @throws InvalidPurchaseRequestTransition
     * @throws ValidationException
     */
    public static function run(PurchaseRequest $purchaseRequest, array $data): PurchaseRequest
    {
        return DB::transaction(function () use ($purchaseRequest, $data) {
            /** @var PurchaseRequest|null $locked */
            $locked = PurchaseRequest::query()
                ->whereKey($purchaseRequest->getKey())
                ->lockForUpdate()
                ->first();

            if (! $locked || ! $locked->isDraft()) {
                throw new InvalidPurchaseRequestTransition(
                    $purchaseRequest,
                    'not_draft',
                    $purchaseRequest->status
                );
            }

            $departmentId = CreatePurchaseRequestAction::normalizeDepartmentId($data['department_id'] ?? null);
            CreatePurchaseRequestAction::assertDepartmentBelongsToCompany(
                $departmentId,
                $locked->company_id !== null ? (int) $locked->company_id : null
            );

            $items = CreatePurchaseRequestAction::normalizeItems($data['items'] ?? []);

            $locked->title = $data['title'] ?? $locked->title;
            $locked->purpose = $data['purpose'] ?? null;
            $locked->department_id = $departmentId;
            $locked->notes = $data['notes'] ?? null;
            // Currency remains the server-stamped value from creation.

            if (! $locked->save()) {
                throw ValidationException::withMessages(
                    $locked->getErrors()->toArray()
                );
            }

            CreatePurchaseRequestAction::syncItems($locked, $items);

            return $locked->fresh(['items', 'requester', 'company', 'department']);
        });
    }
}
