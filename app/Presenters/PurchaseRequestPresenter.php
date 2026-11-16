<?php

namespace App\Presenters;

class PurchaseRequestPresenter extends Presenter
{
    public static function dataTableLayout(): array
    {
        return [
            [
                'field' => 'id',
                'searchable' => false,
                'sortable' => true,
                'switchable' => true,
                'title' => trans('general.id'),
                'visible' => false,
            ],
            [
                'field' => 'request_number',
                'searchable' => true,
                'sortable' => true,
                'switchable' => false,
                'title' => trans('admin/purchase_requests/general.request_number'),
                'visible' => true,
                'formatter' => 'purchaseRequestsLinkFormatter',
            ],
            [
                'field' => 'title',
                'searchable' => true,
                'sortable' => true,
                'title' => trans('admin/purchase_requests/general.title'),
                'visible' => true,
                'formatter' => 'purchaseRequestsLinkFormatter',
            ],
            [
                'field' => 'requester',
                'searchable' => true,
                'sortable' => true,
                'title' => trans('admin/purchase_requests/general.requester'),
                'visible' => true,
                'formatter' => 'usersLinkObjFormatter',
            ],
            [
                'field' => 'department',
                'searchable' => true,
                'sortable' => true,
                'title' => trans('general.department'),
                'visible' => true,
                'formatter' => 'departmentsLinkObjFormatter',
            ],
            [
                'field' => 'company',
                'searchable' => true,
                'sortable' => true,
                'title' => trans('general.company'),
                'visible' => true,
                'formatter' => 'companiesLinkObjFormatter',
            ],
            [
                'field' => 'created_at',
                'searchable' => true,
                'sortable' => true,
                'title' => trans('general.date'),
                'visible' => true,
                'formatter' => 'dateDisplayFormatter',
            ],
            [
                'field' => 'estimated_total',
                'searchable' => false,
                'sortable' => true,
                'title' => trans('admin/purchase_requests/general.estimated_total'),
                'visible' => true,
            ],
            [
                'field' => 'status_label',
                'searchable' => false,
                'sortable' => true,
                'title' => trans('general.status'),
                'visible' => true,
            ],
            [
                'field' => 'actions',
                'searchable' => false,
                'sortable' => false,
                'switchable' => false,
                'title' => trans('table.actions'),
                'visible' => true,
                'formatter' => 'purchaseRequestsActionsFormatter',
            ],
        ];
    }

    public function name(): string
    {
        return (string) ($this->model->request_number.' — '.$this->model->title);
    }

    public function nameUrl(): string
    {
        if (auth()->user()?->can('view', $this->model)) {
            return '<a href="'.route('purchase-requests.show', $this->id).'">'.e($this->name()).'</a>';
        }

        return e($this->name());
    }
}
