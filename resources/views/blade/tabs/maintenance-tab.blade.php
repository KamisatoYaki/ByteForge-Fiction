@props([
    'count' => null,
    'class' => false,
])

@can('view', \App\Models\Maintenance::class)
<x-tabs.nav-item
    :$class
    name="maintenances"
    icon_type="maintenances"
    label="{{ trans('general.maintenances') }}"
    count="{{ $count }}"
    tooltip="{{ trans('general.maintenances') }}"
/>
@endcan