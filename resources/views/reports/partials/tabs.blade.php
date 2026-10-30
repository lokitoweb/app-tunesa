{{-- Pestañas ejecutivas del módulo de reportes --}}
@php
    $currentTab = $activeTab ?? (request()->routeIs('reports.hours*') ? 'hours' : 'marcaciones');
@endphp
<div class="mb-3">
    <div class="seg-tabs" role="tablist" id="moduleMainTabs" aria-label="Secciones de reportería">
        <a
            href="{{ route('reports.index') }}"
            class="seg-tab {{ $currentTab === 'marcaciones' ? 'is-active' : '' }}"
            data-tab="marcaciones"
            data-url="{{ route('reports.index') }}"
            role="tab"
            aria-selected="{{ $currentTab === 'marcaciones' ? 'true' : 'false' }}"
            id="tabMarcaciones"
        >
            <i class="bi bi-list-check"></i>
            <span>Marcaciones</span>
        </a>
        <a
            href="{{ route('reports.hours') }}"
            class="seg-tab {{ $currentTab === 'hours' ? 'is-active' : '' }}"
            data-tab="hours"
            data-url="{{ route('reports.hours') }}"
            role="tab"
            aria-selected="{{ $currentTab === 'hours' ? 'true' : 'false' }}"
            id="tabHours"
        >
            <i class="bi bi-hourglass-split"></i>
            <span>Horas y faltas</span>
        </a>
    </div>
</div>
