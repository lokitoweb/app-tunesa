{{-- Pestañas ejecutivas del módulo de reportes --}}
@php
    $currentTab = $activeTab ?? (request()->routeIs('reports.hours*') ? 'hours' : 'marcaciones');
@endphp
<div class="reports-nav-tabs-container mb-4">
    <div class="reports-nav-tabs" role="tablist" id="moduleMainTabs" aria-label="Secciones de reportería">
        <a
            href="{{ route('reports.index') }}"
            class="reports-nav-tab {{ $currentTab === 'marcaciones' ? 'is-active' : '' }}"
            data-tab="marcaciones"
            data-url="{{ route('reports.index') }}"
            role="tab"
            aria-selected="{{ $currentTab === 'marcaciones' ? 'true' : 'false' }}"
            id="tabMarcaciones"
        >
            <i class="bi bi-list-check fs-6"></i>
            <span>Marcaciones</span>
        </a>
        <a
            href="{{ route('reports.hours') }}"
            class="reports-nav-tab {{ $currentTab === 'hours' ? 'is-active' : '' }}"
            data-tab="hours"
            data-url="{{ route('reports.hours') }}"
            role="tab"
            aria-selected="{{ $currentTab === 'hours' ? 'true' : 'false' }}"
            id="tabHours"
        >
            <i class="bi bi-hourglass-split fs-6"></i>
            <span>Horas y faltas</span>
        </a>
    </div>
</div>
