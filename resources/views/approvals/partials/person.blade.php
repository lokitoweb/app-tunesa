{{-- Colaborador de la fila: foto o iniciales, nombre y código --}}
<div class="d-flex align-items-center gap-3">
    @if(!empty($person?->avatar_path))
        <img src="{{ asset('storage/' . $person->avatar_path) }}" alt="{{ $person->first_name }}" class="attendance-user-avatar">
    @else
        <span class="attendance-user-initials">{{ mb_substr($person?->first_name ?? 'U', 0, 1) }}{{ mb_substr($person?->last_name ?? '', 0, 1) }}</span>
    @endif
    <div class="min-w-0">
        <div class="cell-title text-truncate">{{ $person?->full_name }}</div>
        <div class="cell-sub text-truncate">{{ $person?->employee_code ?? '—' }}</div>
        @include('partials.tenant-badge', ['tenantId' => $tenantId])
    </div>
</div>
