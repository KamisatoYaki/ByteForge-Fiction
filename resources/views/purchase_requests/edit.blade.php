@extends('layouts/default')

@section('title')
    @if ($item->id)
        {{ trans('general.update') }} {{ trans('general.purchase_request') }}
    @else
        {{ trans('general.create') }} {{ trans('general.purchase_request') }}
    @endif
    @parent
@stop

@section('content')
    <div class="row">
        <div class="col-md-10 col-md-offset-1">
            <form
                class="form-horizontal"
                method="POST"
                action="{{ $item->id ? route('purchase-requests.update', $item) : route('purchase-requests.store') }}"
                id="purchase-request-form"
            >
                @csrf
                @if ($item->id)
                    @method('PUT')
                @endif

                <div class="box box-default">
                    <div class="box-header with-border">
                        <h2 class="box-title">
                            @if ($item->id)
                                {{ $item->request_number }}
                            @else
                                {{ trans('general.purchase_request') }}
                            @endif
                        </h2>
                        <div class="box-tools pull-right">
                            <button type="submit" class="btn btn-primary">{{ trans('general.save') }}</button>
                        </div>
                    </div>
                    <div class="box-body">
                        <x-form.error :name="'form'" />

                        <div class="form-group{{ $errors->has('title') ? ' has-error' : '' }}">
                            <label class="col-md-3 control-label" for="title">{{ trans('admin/purchase_requests/general.title') }}</label>
                            <div class="col-md-7">
                                <input type="text" class="form-control" name="title" id="title" value="{{ old('title', $item->title) }}" required>
                                <x-form.error name="title" />
                            </div>
                        </div>

                        <div class="form-group{{ $errors->has('purpose') ? ' has-error' : '' }}">
                            <label class="col-md-3 control-label" for="purpose">{{ trans('admin/purchase_requests/general.purpose') }}</label>
                            <div class="col-md-7">
                                <textarea class="form-control" name="purpose" id="purpose" rows="4">{{ old('purpose', $item->purpose) }}</textarea>
                                <x-form.error name="purpose" />
                            </div>
                        </div>

                        @if (! $item->id)
                            @include('partials.forms.edit.company-select', [
                                'translated_name' => trans('general.company'),
                                'fieldname' => 'company_id',
                            ])
                        @else
                            <div class="form-group">
                                <label class="col-md-3 control-label">{{ trans('general.company') }}</label>
                                <div class="col-md-7">
                                    <p class="form-control-static">{{ $item->company->name ?? '—' }}</p>
                                </div>
                            </div>
                        @endif

                        @include('partials.forms.edit.department-select', [
                            'translated_name' => trans('general.department'),
                            'fieldname' => 'department_id',
                        ])

                        <div class="form-group{{ $errors->has('notes') ? ' has-error' : '' }}">
                            <label class="col-md-3 control-label" for="notes">{{ trans('general.notes') }}</label>
                            <div class="col-md-7">
                                <textarea class="form-control" name="notes" id="notes" rows="2">{{ old('notes', $item->notes) }}</textarea>
                                <x-form.error name="notes" />
                            </div>
                        </div>

                        <hr>
                        <h4>{{ trans('admin/purchase_requests/general.line_items') }}</h4>
                        <x-form.error name="items" />

                        <table class="table table-bordered" id="pr-items-table">
                            <thead>
                                <tr>
                                    <th>{{ trans('admin/purchase_requests/general.item_description') }}</th>
                                    <th style="width: 90px;">{{ trans('general.qty') }}</th>
                                    <th style="width: 140px;">{{ trans('admin/purchase_requests/general.unit_cost') }}</th>
                                    <th style="width: 140px;">{{ trans('admin/purchase_requests/general.line_total') }}</th>
                                    <th>{{ trans('admin/purchase_requests/general.specifications') }}</th>
                                    <th style="width: 50px;"></th>
                                </tr>
                            </thead>
                            <tbody>
                                @php
                                    $formItems = old('items', $items);
                                @endphp
                                @foreach ($formItems as $index => $line)
                                    <tr class="pr-item-row">
                                        <td>
                                            <input type="text" class="form-control" name="items[{{ $index }}][description]" value="{{ $line['description'] ?? '' }}" required>
                                        </td>
                                        <td>
                                            <input type="number" min="1" step="1" class="form-control pr-qty" name="items[{{ $index }}][quantity]" value="{{ $line['quantity'] ?? 1 }}" required>
                                        </td>
                                        <td>
                                            <input type="number" min="0" step="0.0001" class="form-control pr-unit-cost" name="items[{{ $index }}][estimated_unit_cost]" value="{{ $line['estimated_unit_cost'] ?? '0.0000' }}" required>
                                        </td>
                                        <td>
                                            <input type="text" class="form-control pr-line-total" value="" readonly>
                                        </td>
                                        <td>
                                            <input type="text" class="form-control" name="items[{{ $index }}][specifications]" value="{{ $line['specifications'] ?? '' }}">
                                        </td>
                                        <td>
                                            <button type="button" class="btn btn-danger btn-sm pr-remove-row">&times;</button>
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                            <tfoot>
                                <tr>
                                    <td colspan="3" class="text-right"><strong>{{ trans('admin/purchase_requests/general.estimated_total') }}</strong></td>
                                    <td><strong id="pr-estimated-total">0.0000</strong></td>
                                    <td colspan="2"></td>
                                </tr>
                            </tfoot>
                        </table>

                        <button type="button" class="btn btn-default" id="pr-add-row">
                            {{ trans('admin/purchase_requests/general.add_line_item') }}
                        </button>
                    </div>
                    <div class="box-footer text-right">
                        <a href="{{ $item->id ? route('purchase-requests.show', $item) : route('purchase-requests.index') }}" class="btn btn-default">{{ trans('button.cancel') }}</a>
                        <button type="submit" class="btn btn-primary">{{ trans('general.save') }}</button>
                    </div>
                </div>
            </form>
        </div>
    </div>
@stop

@section('moar_scripts')
<script>
(function ($) {
    function recalcRow($row) {
        var qty = parseFloat($row.find('.pr-qty').val()) || 0;
        var unit = parseFloat($row.find('.pr-unit-cost').val()) || 0;
        var total = (qty * unit).toFixed(4);
        $row.find('.pr-line-total').val(total);
        return parseFloat(total) || 0;
    }

    function recalcAll() {
        var sum = 0;
        $('#pr-items-table tbody tr').each(function () {
            sum += recalcRow($(this));
        });
        $('#pr-estimated-total').text(sum.toFixed(4));
    }

    function reindexRows() {
        $('#pr-items-table tbody tr').each(function (index) {
            $(this).find('input[name]').each(function () {
                var name = $(this).attr('name');
                if (!name) return;
                $(this).attr('name', name.replace(/items\[\d+]/, 'items[' + index + ']'));
            });
        });
    }

    $('#pr-add-row').on('click', function () {
        var index = $('#pr-items-table tbody tr').length;
        var row = '<tr class="pr-item-row">' +
            '<td><input type="text" class="form-control" name="items[' + index + '][description]" required></td>' +
            '<td><input type="number" min="1" step="1" class="form-control pr-qty" name="items[' + index + '][quantity]" value="1" required></td>' +
            '<td><input type="number" min="0" step="0.0001" class="form-control pr-unit-cost" name="items[' + index + '][estimated_unit_cost]" value="0.0000" required></td>' +
            '<td><input type="text" class="form-control pr-line-total" value="" readonly></td>' +
            '<td><input type="text" class="form-control" name="items[' + index + '][specifications]"></td>' +
            '<td><button type="button" class="btn btn-danger btn-sm pr-remove-row">&times;</button></td>' +
            '</tr>';
        $('#pr-items-table tbody').append(row);
        recalcAll();
    });

    $(document).on('click', '.pr-remove-row', function () {
        if ($('#pr-items-table tbody tr').length === 1) {
            return;
        }
        $(this).closest('tr').remove();
        reindexRows();
        recalcAll();
    });

    $(document).on('input', '.pr-qty, .pr-unit-cost', recalcAll);
    recalcAll();
})(jQuery);
</script>
@stop
