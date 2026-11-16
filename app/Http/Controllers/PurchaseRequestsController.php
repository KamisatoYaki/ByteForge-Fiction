<?php

namespace App\Http\Controllers;

use App\Actions\PurchaseRequests\CreatePurchaseRequestAction;
use App\Actions\PurchaseRequests\SubmitPurchaseRequestAction;
use App\Actions\PurchaseRequests\UpdatePurchaseRequestAction;
use App\Actions\WorkflowApprovals\RecordWorkflowApprovalAction;
use App\Enums\ApprovalDecision;
use App\Exceptions\InvalidApprovalDecision;
use App\Exceptions\InvalidPurchaseRequestTransition;
use App\Exceptions\SelfApprovalNotAllowed;
use App\Models\PurchaseRequest;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class PurchaseRequestsController extends Controller
{
    public function index(Request $request): View
    {
        $this->authorize('index', PurchaseRequest::class);

        return view('purchase_requests.index', [
            'status' => $request->input('status'),
            'mine' => $request->boolean('mine'),
            'pending_approval' => $request->boolean('pending_approval'),
        ]);
    }

    public function create(): View
    {
        $this->authorize('create', PurchaseRequest::class);

        return view('purchase_requests.edit', [
            'item' => new PurchaseRequest,
            'items' => [[]],
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $this->authorize('create', PurchaseRequest::class);

        try {
            $purchaseRequest = CreatePurchaseRequestAction::run(
                auth()->user(),
                $this->payloadFromRequest($request)
            );
        } catch (ValidationException $e) {
            return redirect()->back()->withInput()->withErrors($e->errors());
        }

        return redirect()
            ->route('purchase-requests.show', $purchaseRequest)
            ->with('success', trans('admin/purchase_requests/message.create.success'));
    }

    public function show(PurchaseRequest $purchaseRequest): View
    {
        $this->authorize('view', $purchaseRequest);

        $purchaseRequest->load([
            'items',
            'requester',
            'company',
            'department',
            'workflowApprovals.decidedBy',
        ]);

        return view('purchase_requests.view', [
            'item' => $purchaseRequest,
        ]);
    }

    public function edit(PurchaseRequest $purchaseRequest): View|RedirectResponse
    {
        $this->authorize('update', $purchaseRequest);

        if (! $purchaseRequest->isDraft()) {
            return redirect()
                ->route('purchase-requests.show', $purchaseRequest)
                ->with('error', trans('admin/purchase_requests/message.edit.not_draft'));
        }

        $purchaseRequest->load('items');

        return view('purchase_requests.edit', [
            'item' => $purchaseRequest,
            'items' => $purchaseRequest->items->map(fn ($item) => [
                'description' => $item->description,
                'quantity' => $item->quantity,
                'estimated_unit_cost' => $item->estimated_unit_cost,
                'specifications' => $item->specifications,
                'category_id' => $item->category_id,
            ])->all() ?: [[]],
        ]);
    }

    public function update(Request $request, PurchaseRequest $purchaseRequest): RedirectResponse
    {
        $this->authorize('update', $purchaseRequest);

        try {
            UpdatePurchaseRequestAction::run(
                $purchaseRequest,
                $this->payloadFromRequest($request)
            );
        } catch (InvalidPurchaseRequestTransition) {
            return redirect()
                ->route('purchase-requests.show', $purchaseRequest)
                ->with('error', trans('admin/purchase_requests/message.edit.not_draft'));
        } catch (ValidationException $e) {
            return redirect()->back()->withInput()->withErrors($e->errors());
        }

        return redirect()
            ->route('purchase-requests.show', $purchaseRequest)
            ->with('success', trans('admin/purchase_requests/message.update.success'));
    }

    public function submit(PurchaseRequest $purchaseRequest): RedirectResponse
    {
        $this->authorize('submit', $purchaseRequest);

        try {
            SubmitPurchaseRequestAction::run($purchaseRequest);
        } catch (InvalidPurchaseRequestTransition) {
            return redirect()
                ->route('purchase-requests.show', $purchaseRequest)
                ->with('error', trans('admin/purchase_requests/message.submit.invalid'));
        } catch (ValidationException $e) {
            return redirect()->back()->withInput()->withErrors($e->errors());
        }

        return redirect()
            ->route('purchase-requests.show', $purchaseRequest)
            ->with('success', trans('admin/purchase_requests/message.submit.success'));
    }

    public function decide(Request $request, PurchaseRequest $purchaseRequest): RedirectResponse
    {
        $this->authorize('approve', $purchaseRequest);

        $validated = $request->validate([
            'decision' => 'required|in:approved,rejected',
            'comments' => 'nullable|string|max:65535',
        ]);

        try {
            RecordWorkflowApprovalAction::run(
                $purchaseRequest,
                ApprovalDecision::from($validated['decision']),
                $validated['comments'] ?? null
            );
        } catch (SelfApprovalNotAllowed) {
            return redirect()
                ->route('purchase-requests.show', $purchaseRequest)
                ->with('error', trans('admin/purchase_requests/message.approve.self'));
        } catch (InvalidApprovalDecision) {
            return redirect()
                ->route('purchase-requests.show', $purchaseRequest)
                ->with('error', trans('admin/purchase_requests/message.approve.invalid'));
        } catch (AuthorizationException) {
            return redirect()
                ->route('purchase-requests.show', $purchaseRequest)
                ->with('error', trans('general.insufficient_permissions'));
        }

        return redirect()
            ->route('purchase-requests.show', $purchaseRequest)
            ->with('success', trans('admin/purchase_requests/message.approve.success'));
    }

    public function destroy(PurchaseRequest $purchaseRequest): RedirectResponse
    {
        $this->authorize('delete', $purchaseRequest);

        if (! $purchaseRequest->isDraft()) {
            return redirect()
                ->route('purchase-requests.show', $purchaseRequest)
                ->with('error', trans('admin/purchase_requests/message.delete.not_draft'));
        }

        $purchaseRequest->items()->delete();
        $purchaseRequest->delete();

        return redirect()
            ->route('purchase-requests.index')
            ->with('success', trans('admin/purchase_requests/message.delete.success'));
    }

    /**
     * @return array{title: mixed, purpose: mixed, department_id: mixed, notes: mixed, company_id: mixed, items: list<array<string, mixed>>}
     */
    private function payloadFromRequest(Request $request): array
    {
        $items = $request->input('items', []);
        if (! is_array($items)) {
            $items = [];
        }

        // Drop blank trailing rows from dynamic forms.
        $items = array_values(array_filter($items, function ($item) {
            if (! is_array($item)) {
                return false;
            }

            return trim((string) ($item['description'] ?? '')) !== '';
        }));

        return [
            'title' => $request->input('title'),
            'purpose' => $request->input('purpose'),
            'department_id' => $request->input('department_id') ?: null,
            'notes' => $request->input('notes'),
            'company_id' => $request->input('company_id'),
            'items' => $items,
        ];
    }
}
