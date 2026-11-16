<?php

use App\Http\Controllers\PurchaseRequestsController;
use App\Models\PurchaseRequest;
use Illuminate\Support\Facades\Route;
use Tabuna\Breadcrumbs\Trail;

Route::group(['middleware' => ['auth']], function () {
    Route::get('purchase-requests', [PurchaseRequestsController::class, 'index'])
        ->name('purchase-requests.index')
        ->breadcrumbs(fn (Trail $trail) => $trail->parent('home')->push(trans('general.purchase_requests'), route('purchase-requests.index')));

    Route::get('purchase-requests/create', [PurchaseRequestsController::class, 'create'])
        ->name('purchase-requests.create')
        ->breadcrumbs(fn (Trail $trail) => $trail->parent('purchase-requests.index')->push(trans('general.create'), route('purchase-requests.create')));

    Route::post('purchase-requests', [PurchaseRequestsController::class, 'store'])
        ->name('purchase-requests.store');

    Route::get('purchase-requests/{purchase_request}', [PurchaseRequestsController::class, 'show'])
        ->name('purchase-requests.show')
        ->breadcrumbs(fn (Trail $trail, PurchaseRequest $purchaseRequest) => $trail->parent('purchase-requests.index')->push($purchaseRequest->request_number, route('purchase-requests.show', $purchaseRequest)));

    Route::get('purchase-requests/{purchase_request}/edit', [PurchaseRequestsController::class, 'edit'])
        ->name('purchase-requests.edit')
        ->breadcrumbs(fn (Trail $trail, PurchaseRequest $purchaseRequest) => $trail->parent('purchase-requests.show', $purchaseRequest)->push(trans('general.edit'), route('purchase-requests.edit', $purchaseRequest)));

    Route::put('purchase-requests/{purchase_request}', [PurchaseRequestsController::class, 'update'])
        ->name('purchase-requests.update');

    Route::post('purchase-requests/{purchase_request}/submit', [PurchaseRequestsController::class, 'submit'])
        ->name('purchase-requests.submit');

    Route::post('purchase-requests/{purchase_request}/decide', [PurchaseRequestsController::class, 'decide'])
        ->name('purchase-requests.decide');

    Route::delete('purchase-requests/{purchase_request}', [PurchaseRequestsController::class, 'destroy'])
        ->name('purchase-requests.destroy');
});
