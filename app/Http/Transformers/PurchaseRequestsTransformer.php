<?php

namespace App\Http\Transformers;

use App\Helpers\Helper;
use App\Models\PurchaseRequest;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Gate;

class PurchaseRequestsTransformer
{
    public function transformPurchaseRequests(Collection $purchaseRequests, int $total): array
    {
        $array = [];
        foreach ($purchaseRequests as $purchaseRequest) {
            $array[] = $this->transformPurchaseRequest($purchaseRequest);
        }

        return (new DatatablesTransformer)->transformDatatables($array, $total);
    }

    public function transformPurchaseRequest(PurchaseRequest $purchaseRequest): array
    {
        return [
            'id' => (int) $purchaseRequest->id,
            'request_number' => e($purchaseRequest->request_number),
            'title' => e($purchaseRequest->title),
            'purpose' => e($purchaseRequest->purpose),
            'status' => $purchaseRequest->status?->value,
            'status_label' => '<span class="label label-'.$purchaseRequest->status?->color().'">'
                .e($purchaseRequest->status?->label()).'</span>',
            'estimated_total' => Helper::formatCurrencyOutput($purchaseRequest->estimated_total),
            'currency' => e($purchaseRequest->currency),
            'requester' => ($purchaseRequest->requester) ? [
                'id' => (int) $purchaseRequest->requester->id,
                'name' => e($purchaseRequest->requester->display_name),
            ] : null,
            'department' => ($purchaseRequest->department) ? [
                'id' => (int) $purchaseRequest->department->id,
                'name' => e($purchaseRequest->department->name),
            ] : null,
            'company' => ($purchaseRequest->company) ? [
                'id' => (int) $purchaseRequest->company->id,
                'name' => e($purchaseRequest->company->name),
            ] : null,
            'submitted_at' => Helper::getFormattedDateObject($purchaseRequest->submitted_at, 'datetime'),
            'created_at' => Helper::getFormattedDateObject($purchaseRequest->created_at, 'datetime'),
            'updated_at' => Helper::getFormattedDateObject($purchaseRequest->updated_at, 'datetime'),
            'available_actions' => [
                'view' => Gate::allows('view', $purchaseRequest),
                'update' => Gate::allows('update', $purchaseRequest),
                'delete' => Gate::allows('delete', $purchaseRequest),
                'submit' => Gate::allows('submit', $purchaseRequest),
                'approve' => Gate::allows('approve', $purchaseRequest) && $purchaseRequest->isEligibleForApprovalDecision(),
            ],
        ];
    }
}
