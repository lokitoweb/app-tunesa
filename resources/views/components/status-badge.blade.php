{{--
    Badge de estado con etiqueta en español y color del diccionario App\Support\Labels::ENUMS.
    <x-status-badge type="leave_status" :value="$leave->status" />
    <x-status-badge type="branch_status" :value="$branch->is_active" icon="geo-alt" />
    <x-status-badge type="user_role" :value="$u->role" tone="light" />   (tone fuerza el color)
--}}
@props(['type', 'value' => null, 'tone' => null, 'icon' => null])
@php
    $tone  = $tone ?? \App\Support\Labels::tone($type, $value);
    $label = \App\Support\Labels::enum($type, $value);
@endphp
<span {{ $attributes->merge(['class' => "badge rounded-pill bg-{$tone}-subtle text-{$tone}-emphasis border border-{$tone}-subtle"]) }}>@if($icon)<i class="bi bi-{{ $icon }} me-1" aria-hidden="true"></i>@endif{{ $label }}</span>
