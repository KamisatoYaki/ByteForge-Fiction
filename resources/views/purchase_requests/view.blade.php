@extends('layouts/default')

@section('title')
    {{ $item->request_number }}
    @parent
@stop

@section('header_right')
    @can('update', $item)
        <a href="{{ route('purchase-requests.edit', $item) }}" class="btn btn-warning">{{ trans('general.edit') }}</a>
    @endcan
    @can('submit', $item)
        <form action="{{ route('purchase-requests.submit', $item) }}" method="POST" style="display:inline;">
            @csrf
            <button type="submit" class="btn btn-primary" onclick="return confirm('{{ trans('admin/purchase_requests/general.submit_request') }}?')">
                {{ trans('admin/purchase_requests/general.submit_request') }}
            </button>
        </form>
    @endcan
    @can('delete', $item)
        <form action="{{ route('purchase-requests.destroy', $item) }}" method="POST" style="display:inline;">
            @csrf
            @method('DELETE')
            <button type="submit" class="btn btn-danger" onclick="return confirm('{{ trans('general.delete_confirm', ['item' => trans('general.purchase_request')]) }}')">
                {{ trans('general.delete') }}
            </button>
        </form>
    @endcan
@stop

@section('content')
    <div class="row">
        <div class="col-md-8">
            <div class="box box-default">
                <div class="box-header with-border">
                    <h2 class="box-title">{{ $item->title }}</h2>
                    <span class="label label-{{ $item->status->color() }}">{{ $item->status->label() }}</span>
                </div>
                <div class="box-body">
                    <dl class="dl-horizontal">
                        <dt>{{ trans('admin/purchase_requests/general.request_number') }}</dt>
                        <dd>{{ $item->request_number }}</dd>

                        <dt>{{ trans('admin/purchase_requests/general.requester') }}</dt>
                        <dd>{{ $item->requester->display_name ?? '—' }}</dd>

                        <dt>{{ trans('general.company') }}</dt>
                        <dd>{{ $item->company->name ?? '—' }}</dd>

                        <dt>{{ trans('general.department') }}</dt>
                        <dd>{{ $item->department->name ?? '—' }}</dd>

                        <dt>{{ trans('admin/purchase_requests/general.purpose') }}</dt>
                        <dd>{!! nl2br(e($item->purpose)) !!}</dd>

                        <dt>{{ trans('general.notes') }}</dt>
                        <dd>{!! nl2br(e($item->notes)) ?: '—' !!}</dd>

                        <dt>{{ trans('admin/purchase_requests/general.submitted_at') }}</dt>
                        <dd>{{ $item->submitted_at ? $item->submitted_at->format('Y-m-d H:i') : '—' }}</dd>

                        <dt>{{ trans('admin/purchase_requests/general.estimated_total') }}</dt>
                        <dd>{{ $item->currency }} {{ \App\Helpers\Helper::formatCurrencyOutput($item->estimated_total) }}</dd>
                    </dl>

                    <h4>{{ trans('admin/purchase_requests/general.line_items') }}</h4>
                    <table class="table table-striped">
                        <thead>
                            <tr>
                                <th>{{ trans('admin/purchase_requests/general.item_description') }}</th>
                                <th>{{ trans('general.qty') }}</th>
                                <th>{{ trans('admin/purchase_requests/general.unit_cost') }}</th>
                                <th>{{ trans('admin/purchase_requests/general.line_total') }}</th>
                                <th>{{ trans('admin/purchase_requests/general.specifications') }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($item->items as $line)
                                <tr>
                                    <td>{{ $line->description }}</td>
                                    <td>{{ $line->quantity }}</td>
                                    <td>{{ \App\Helpers\Helper::formatCurrencyOutput($line->estimated_unit_cost) }}</td>
                                    <td>{{ \App\Helpers\Helper::formatCurrencyOutput($line->estimated_line_total) }}</td>
                                    <td>{{ $line->specifications }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <div class="col-md-4">
            @can('approve', $item)
                @if ($item->isEligibleForApprovalDecision())
                    <div class="box box-warning">
                        <div class="box-header with-border">
                            <h3 class="box-title">{{ trans('admin/purchase_requests/general.approve_request') }} / {{ trans('admin/purchase_requests/general.reject_request') }}</h3>
                        </div>
                        <div class="box-body">
                            <form method="POST" action="{{ route('purchase-requests.decide', $item) }}">
                                @csrf
                                <div class="form-group">
                                    <label for="comments">{{ trans('admin/purchase_requests/general.decision_comments') }}</label>
                                    <textarea name="comments" id="comments" class="form-control" rows="3"></textarea>
                                </div>
                                <button type="submit" name="decision" value="approved" class="btn btn-success">
                                    {{ trans('admin/purchase_requests/general.approve_request') }}
                                </button>
                                <button type="submit" name="decision" value="rejected" class="btn btn-danger">
                                    {{ trans('admin/purchase_requests/general.reject_request') }}
                                </button>
                            </form>
                        </div>
                    </div>
                @endif
            @endcan

            <div class="box box-default">
                <div class="box-header with-border">
                    <h3 class="box-title">{{ trans('admin/purchase_requests/general.approval_history') }}</h3>
                </div>
                <div class="box-body">
                    @forelse ($item->workflowApprovals as $approval)
                        <p>
                            <span class="label label-{{ $approval->decision === \App\Enums\ApprovalDecision::Approved ? 'success' : 'danger' }}">
                                {{ $approval->decision->label() }}
                            </span>
                            {{ $approval->decidedBy->display_name ?? '—' }}
                            <br>
                            <small>{{ $approval->decided_at?->format('Y-m-d H:i') }}</small>
                            @if ($approval->comments)
                                <br>{{ $approval->comments }}
                            @endif
                        </p>
                    @empty
                        <p class="text-muted">—</p>
                    @endforelse
                </div>
            </div>
        </div>
    </div>
@stop
