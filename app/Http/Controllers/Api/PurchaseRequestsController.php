<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Transformers\PurchaseRequestsTransformer;
use App\Models\PurchaseRequest;
use Illuminate\Http\Request;

class PurchaseRequestsController extends Controller
{
    public function index(Request $request): array
    {
        $this->authorize('index', PurchaseRequest::class);

        $user = auth()->user();

        $purchaseRequests = PurchaseRequest::query()
            ->with(['requester', 'company', 'department'])
            ->select('purchase_requests.*');

        // Own / company / approver visibility on top of CompanyableTrait FMCS scope.
        if (! $user->isSuperUser()
            && ! $user->hasAccess('admin')
            && ! $user->hasAccess('purchase_requests.view')
        ) {
            $purchaseRequests->where(function ($query) use ($user) {
                $query->where('requested_by', $user->id);
                if ($user->hasAccess('purchase_requests.approve')) {
                    $query->orWhere('status', 'submitted');
                }
            });
        }

        if ($request->boolean('mine')) {
            $purchaseRequests->where('requested_by', $user->id);
        }

        if ($request->boolean('pending_approval')) {
            $purchaseRequests->where('status', 'submitted');
        }

        if ($request->filled('status')) {
            $purchaseRequests->where('status', $request->input('status'));
        }

        if ($request->filled('search')) {
            $purchaseRequests->TextSearch($request->input('search'));
        }

        $allowedColumns = [
            'id',
            'request_number',
            'title',
            'status',
            'estimated_total',
            'created_at',
            'submitted_at',
            'company_id',
            'department_id',
            'requested_by',
        ];

        $sort = in_array($request->input('sort'), $allowedColumns, true)
            ? $request->input('sort')
            : 'created_at';
        $order = $request->input('order') === 'asc' ? 'asc' : 'desc';

        $total = (clone $purchaseRequests)->count();
        $offset = (($request->input('offset') > $total) ? $total : $request->input('offset', 0));

        $rows = $purchaseRequests
            ->orderBy($sort, $order)
            ->skip($offset)
            ->take($request->input('limit', 50))
            ->get();

        return (new PurchaseRequestsTransformer)->transformPurchaseRequests($rows, $total);
    }
}
