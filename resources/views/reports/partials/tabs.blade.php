{{-- Pestañas del módulo de reportes --}}
<ul class="nav nav-tabs mb-4">
    <li class="nav-item">
        <a class="nav-link {{ request()->routeIs('reports.index') ? 'active' : '' }}" href="{{ route('reports.index') }}" @if(request()->routeIs('reports.index')) aria-current="page" @endif>
            <i class="bi bi-list-check me-1"></i> Marcaciones
        </a>
    </li>
    <li class="nav-item">
        <a class="nav-link {{ request()->routeIs('reports.hours') ? 'active' : '' }}" href="{{ route('reports.hours') }}" @if(request()->routeIs('reports.hours')) aria-current="page" @endif>
            <i class="bi bi-hourglass-split me-1"></i> Horas y faltas
        </a>
    </li>
</ul>
