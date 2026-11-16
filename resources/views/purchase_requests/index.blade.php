@extends('layouts/default')

@section('title')
    {{ trans('general.purchase_requests') }}
    @parent
@stop

@section('header_right')
    @can('create', \App\Models\PurchaseRequest::class)
        <a href="{{ route('purchase-requests.create') }}" class="btn btn-primary pull-right">
            {{ trans('general.create') }}
        </a>
    @endcan
@stop

@section('content')
    <div class="row" style="margin-bottom: 10px;">
        <div class="col-md-12">
            <a href="{{ route('purchase-requests.index') }}" class="btn btn-default btn-sm {{ !request()->has('mine') && !request()->has('pending_approval') ? 'active' : '' }}">
                {{ trans('general.all') }}
            </a>
            <a href="{{ route('purchase-requests.index', ['mine' => 1]) }}" class="btn btn-default btn-sm {{ request()->boolean('mine') ? 'active' : '' }}">
                {{ trans('admin/purchase_requests/general.my_requests') }}
            </a>
            @if (auth()->user()->hasAccess('purchase_requests.approve') || auth()->user()->hasAccess('admin') || auth()->user()->isSuperUser())
                <a href="{{ route('purchase-requests.index', ['pending_approval' => 1, 'status' => 'submitted']) }}" class="btn btn-default btn-sm {{ request()->boolean('pending_approval') ? 'active' : '' }}">
                    {{ trans('admin/purchase_requests/general.pending_approval') }}
                </a>
            @endif
        </div>
    </div>

    <x-container>
        <x-box name="purchaseRequest" sr_only_title>
            <x-slot:table_header>{{ trans('general.purchase_requests') }}</x-slot:table_header>

            <x-table
                name="purchaseRequest"
                api_url="{{ route('api.purchase-requests.index', array_filter([
                    'status' => $status ?? null,
                    'mine' => !empty($mine) ? 1 : null,
                    'pending_approval' => !empty($pending_approval) ? 1 : null,
                ])) }}"
                :presenter="\App\Presenters\PurchaseRequestPresenter::dataTableLayout()"
                export_filename="export-purchase-requests-{{ date('Y-m-d') }}"
            />
        </x-box>
    </x-container>
@stop

@section('moar_scripts')
    @include('partials.bootstrap-table', ['exportFile' => 'purchase-requests-export', 'search' => true])
@stop
