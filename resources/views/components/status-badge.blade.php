{{--
    Badge de estado con etiqueta en español y color del diccionario App\Modules\Core\Support\Labels::ENUMS.
    <x-status-badge type="leave_status" :value="$leave->status" />
    <x-status-badge type="branch_status" :value="$branch->is_active" icon="geo-alt" />
    <x-status-badge type="user_role" :value="$u->role" tone="light" />   (tone fuerza el color)
--}}
@props(['type', 'value' => null, 'tone' => null, 'icon' => null])
@php
    $tone  = $tone ?? \App\Modules\Core\Support\Labels::tone($type, $value);
    $label = \App\Modules\Core\Support\Labels::enum($type, $value);
@endphp
<span {{ $attributes->merge(['class' => "badge rounded-2 bg-{$tone}-subtle text-{$tone}-emphasis border border-{$tone}-subtle d-inline-flex align-items-center"]) }} style="gap: 6px;">@if($icon)<i class="bi bi-{{ $icon }}" aria-hidden="true"></i>@else<span class="badge-dot"></span>@endif<span>{{ $label }}</span></span>
