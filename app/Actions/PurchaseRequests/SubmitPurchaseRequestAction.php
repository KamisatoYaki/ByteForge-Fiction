<?php

namespace App\Actions\PurchaseRequests;

use App\Enums\ActionType;
use App\Enums\PurchaseRequestStatus;
use App\Exceptions\InvalidPurchaseRequestTransition;
use App\Helpers\PurchaseRequestMoney;
use App\Models\PurchaseRequest;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class SubmitPurchaseRequestAction
{
    /**
     * @throws InvalidPurchaseRequestTransition
     * @throws ValidationException
     */
    public static function run(PurchaseRequest $purchaseRequest): PurchaseRequest
    {
        return DB::transaction(function () use ($purchaseRequest) {
            /** @var PurchaseRequest|null $locked */
            $locked = PurchaseRequest::query()
                ->whereKey($purchaseRequest->getKey())
                ->lockForUpdate()
                ->first();

            if (! $locked) {
                throw new InvalidPurchaseRequestTransition($purchaseRequest, 'not_found');
            }

            if (! $locked->isDraft()) {
                throw new InvalidPurchaseRequestTransition(
                    $locked,
                    'duplicate_or_invalid_submit',
                    $locked->status
                );
            }

            if (trim((string) $locked->title) === '') {
                throw ValidationException::withMessages([
                    'title' => [trans('validation.required', ['attribute' => trans('admin/purchase_requests/general.title')])],
                ]);
            }

            if (trim((string) $locked->purpose) === '') {
                throw ValidationException::withMessages([
                    'purpose' => [trans('validation.required', ['attribute' => trans('admin/purchase_requests/general.purpose')])],
                ]);
            }

            $locked->load('items');
            if ($locked->items->isEmpty()) {
                throw ValidationException::withMessages([
                    'items' => [trans('admin/purchase_requests/message.items_required')],
                ]);
            }

            if (CreatePurchaseRequestAction::requiresCompanySelection(auth()->user())
                && $locked->company_id === null
            ) {
                throw ValidationException::withMessages([
                    'company_id' => [trans('validation.fmcs_company', ['attribute' => trans('general.company')])],
                ]);
            }

            $locked->estimated_total = PurchaseRequestMoney::sum(
                $locked->items->pluck('estimated_line_total')
            );
            $locked->status = PurchaseRequestStatus::Submitted;
            $locked->submitted_at = Carbon::now(config('app.timezone'));

            if (! $locked->save()) {
                throw ValidationException::withMessages(
                    $locked->getErrors()->toArray()
                );
            }

            $locked = $locked->fresh(['items', 'requester', 'company', 'department']);

            RecordPurchaseRequestActivityAction::run(
                $locked,
                ActionType::Submitted,
                auth()->user(),
                note: $locked->request_number,
                meta: [
                    'request_number' => $locked->request_number,
                    'estimated_total' => PurchaseRequestMoney::normalize($locked->estimated_total),
                    'items_count' => $locked->items->count(),
                    'submitted_at' => optional($locked->submitted_at)?->toDateTimeString(),
                ],
            );

            return $locked;
        });
    }
}
