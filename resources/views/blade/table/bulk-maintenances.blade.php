@props([
    'name' => 'maintenance',
    'showComplete' => true,
])

<x-table.bulk-actions
    :name="$name"
    :action_route="route('maintenances.bulk')"
    model_name="maintenance"
>
    @can('complete', \App\Models\Maintenance::class)
        @if ($showComplete)
            <option value="complete">{{ trans('admin/maintenances/form.mark_complete') }}</option>
        @endif
    @endcan
    @can('delete', \App\Models\Maintenance::class)
        <option value="delete">{{ trans('general.bulk_delete') }}</option>
    @endcan
</x-table.bulk-actions>
