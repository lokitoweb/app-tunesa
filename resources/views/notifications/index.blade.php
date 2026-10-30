@extends('layouts.app')

@section('title', 'Notificaciones')

@section('content')
@php
    $categories = [
        ''           => ['label' => 'Todas las categorías', 'icon' => 'grid'],
        'leave'      => ['label' => 'Permisos & Vacaciones', 'icon' => 'calendar2-check'],
        'attendance' => ['label' => 'Asistencia & Horas Extra', 'icon' => 'clock-history'],
        'payroll'    => ['label' => 'Planilla & Reportes', 'icon' => 'file-earmark-text'],
        'documents'  => ['label' => 'Documentación', 'icon' => 'folder-check'],
        'security'   => ['label' => 'Seguridad', 'icon' => 'shield-lock'],
    ];
@endphp

{{-- Encabezado Ejecutivo de Notificaciones --}}
<div class="d-flex flex-wrap justify-content-between align-items-center gap-3 mb-4">
    <div>
        <div class="d-flex align-items-center gap-2 mb-1">
            <span class="badge rounded-pill bg-primary-subtle text-primary border border-primary-subtle px-2.5 py-1 fw-bold" style="font-size: 0.725rem;">
                <i class="bi bi-bell-fill me-1"></i>Bandeja de Avisos
            </span>
            @if(($counts['unread'] ?? 0) > 0)
                <span class="badge rounded-pill bg-danger-subtle text-danger border border-danger-subtle px-2.5 py-1 fw-bold" style="font-size: 0.725rem;">
                    <i class="bi bi-circle-fill me-1" style="font-size: 0.45rem; vertical-align: middle;"></i>{{ $counts['unread'] }} pendientes
                </span>
            @else
                <span class="badge rounded-pill bg-success-subtle text-success border border-success-subtle px-2.5 py-1 fw-semibold" style="font-size: 0.725rem;">
                    <i class="bi bi-check-circle-fill me-1"></i>Bandeja al día
                </span>
            @endif
        </div>
        <h1 class="h3 fw-bold mb-0 text-body">Notificaciones</h1>
        <p class="text-secondary small mb-0 mt-1">Solicitudes por revisar, decisiones administrativas, alertas operativas y avisos del ERP.</p>
    </div>

    <div class="d-flex align-items-center gap-2 flex-wrap">
        <form method="POST" action="{{ route('notifications.read-all') }}" class="m-0">
            @csrf
            <button type="submit" 
                    class="btn btn-sm btn-outline-primary d-inline-flex align-items-center gap-2 shadow-sm" 
                    style="border-radius: 8px; font-weight: 600; padding: 7px 15px;" 
                    @disabled(($counts['unread'] ?? 0) === 0)>
                <i class="bi bi-check2-all"></i>
                <span>Marcar todas como leídas</span>
            </button>
        </form>
    </div>
</div>

{{-- Barra de Filtrado y Segmentación --}}
<div class="card border-0 shadow-sm mb-4" style="border-radius: 12px; border: 1px solid var(--border-color) !important;">
    <div class="card-body p-3">
        <div class="d-flex flex-wrap justify-content-between align-items-center gap-3">
            {{-- Tabs de Estado (Todas / Sin Leer) --}}
            <div class="d-flex align-items-center gap-1.5 p-1 rounded-3 border" style="background: var(--bg-surface-elevated); border-color: var(--border-color) !important;">
                <a href="{{ route('notifications.index', array_merge(request()->except(['filter', 'page']), ['filter' => 'all'])) }}" 
                   class="btn btn-sm {{ $filter === 'all' ? 'btn-primary fw-bold shadow-sm' : 'btn-ghost text-secondary' }}" 
                   style="border-radius: 6px; padding: 5px 14px; font-size: 0.8rem;">
                    <span>Todas</span>
                    <span class="badge ms-1.5 {{ $filter === 'all' ? 'bg-white text-primary' : 'bg-body-secondary text-secondary' }}" style="font-size: 0.7rem;">
                        {{ $counts['all'] ?? 0 }}
                    </span>
                </a>
                <a href="{{ route('notifications.index', array_merge(request()->except(['filter', 'page']), ['filter' => 'unread'])) }}" 
                   class="btn btn-sm {{ $filter === 'unread' ? 'btn-primary fw-bold shadow-sm' : 'btn-ghost text-secondary' }}" 
                   style="border-radius: 6px; padding: 5px 14px; font-size: 0.8rem;">
                    <span>Sin leer</span>
                    @if(($counts['unread'] ?? 0) > 0)
                        <span class="badge ms-1.5 bg-danger text-white fw-bold" style="font-size: 0.7rem;">
                            {{ $counts['unread'] }}
                        </span>
                    @else
                        <span class="badge ms-1.5 {{ $filter === 'unread' ? 'bg-white text-primary' : 'bg-body-secondary text-secondary' }}" style="font-size: 0.7rem;">
                            0
                        </span>
                    @endif
                </a>
            </div>

            {{-- Filtros y Buscador --}}
            <form method="GET" action="{{ route('notifications.index') }}" class="d-flex flex-wrap align-items-center gap-2 m-0 ms-auto">
                <input type="hidden" name="filter" value="{{ $filter }}">

                {{-- Selector de Categoría --}}
                <div class="input-group input-group-sm" style="width: auto;">
                    <span class="input-group-text bg-surface text-secondary border-end-0" style="border-color: var(--border-color); font-size: 0.8rem;">
                        <i class="bi bi-funnel"></i>
                    </span>
                    <select name="category" class="form-select form-select-sm border-start-0" data-autosubmit style="border-color: var(--border-color); font-size: 0.8rem; min-width: 170px;">
                        @foreach($categories as $key => $cat)
                            <option value="{{ $key }}" @selected($category === $key)>{{ $cat['label'] }}</option>
                        @endforeach
                    </select>
                </div>

                {{-- Buscador por texto --}}
                <div class="input-group input-group-sm" style="width: 220px;">
                    <span class="input-group-text bg-surface text-secondary border-end-0" style="border-color: var(--border-color); font-size: 0.8rem;">
                        <i class="bi bi-search"></i>
                    </span>
                    <input type="text" 
                           name="search" 
                           value="{{ $search ?? '' }}" 
                           class="form-control form-control-sm border-start-0" 
                           placeholder="Buscar avisos..." 
                           style="border-color: var(--border-color); font-size: 0.8rem;">
                </div>

                <button type="submit" class="btn btn-sm btn-secondary" style="border-radius: 6px; font-weight: 600; padding: 5px 12px; font-size: 0.8rem;">
                    Filtrar
                </button>

                @if($category || $search)
                    <a href="{{ route('notifications.index', ['filter' => $filter]) }}" class="btn btn-sm btn-outline-secondary" style="border-radius: 6px; padding: 5px 10px; font-size: 0.8rem;" title="Limpiar filtros">
                        <i class="bi bi-x-lg"></i>
                    </a>
                @endif
            </form>
        </div>
    </div>
</div>

{{-- Lista de Notificaciones --}}
<div class="card border-0 shadow-sm" style="border-radius: 14px; overflow: hidden; border: 1px solid var(--border-color) !important;">
    <div class="list-group list-group-flush">
        @forelse($notifications as $n)
            @php
                $meta = \App\Modules\Core\Controllers\Web\NotificationWebController::categoryMeta($n->data['type'] ?? null);
                $isUnread = is_null($n->read_at);
                $targetUrl = \App\Modules\Core\Controllers\Web\NotificationWebController::targetUrl($n, Auth::user());
            @endphp
            <div class="list-group-item p-3.5 transition-all d-flex align-items-start gap-3" 
                 style="{{ $isUnread ? 'background: rgba(var(--brand-rgb), 0.04); border-left: 3.5px solid var(--accent-cyan);' : 'border-left: 3.5px solid transparent;' }} border-bottom: 1px solid var(--border-color);">
                
                {{-- Icono con color de categoría --}}
                <div class="d-inline-flex align-items-center justify-content-center rounded-3 bg-{{ $meta['color'] }}-subtle text-{{ $meta['color'] }} border border-{{ $meta['color'] }}-subtle shadow-sm flex-shrink-0" 
                     style="width: 44px; height: 44px; margin-top: 2px;">
                    <i class="bi bi-{{ $meta['icon'] }}" style="font-size: 1.25rem;"></i>
                </div>

                {{-- Contenido de la notificación --}}
                <div class="flex-grow-1 min-w-0">
                    <div class="d-flex flex-wrap align-items-center justify-content-between gap-2 mb-1">
                        <div class="d-flex align-items-center gap-2 flex-wrap">
                            <span class="badge bg-{{ $meta['color'] }}-subtle text-{{ $meta['color'] }} px-2 py-0.5 fw-semibold" style="font-size: 0.7rem; border-radius: 4px;">
                                {{ $meta['label'] }}
                            </span>
                            @if($isUnread)
                                <span class="badge rounded-pill bg-primary text-white fw-bold px-2 py-0.5" style="font-size: 0.675rem;">
                                    Nueva
                                </span>
                            @endif
                        </div>
                        <span class="small text-secondary d-flex align-items-center gap-1" title="{{ $n->created_at?->format('d/m/Y h:i a') }}" style="font-size: 0.775rem;">
                            <i class="bi bi-clock"></i>
                            <span>{{ $n->created_at?->diffForHumans() }}</span>
                            <span class="text-muted d-none d-sm-inline">({{ $n->created_at?->format('d/m h:i a') }})</span>
                        </span>
                    </div>

                    <h3 class="h6 fw-bold text-body mb-1" style="font-size: 0.925rem;">
                        {{ $n->data['title'] ?? 'Aviso del ERP' }}
                    </h3>

                    <div class="text-secondary small mb-2" style="line-height: 1.5; font-size: 0.835rem;">
                        {{ $n->data['body'] ?? '' }}
                    </div>

                    {{-- Acciones contextuales --}}
                    <div class="d-flex flex-wrap align-items-center gap-2 pt-1">
                        @if($targetUrl)
                            <a href="{{ route('notifications.read', $n->id) }}" class="btn btn-sm btn-primary d-inline-flex align-items-center gap-1.5 shadow-sm" style="border-radius: 6px; font-weight: 600; font-size: 0.775rem; padding: 4px 12px;">
                                <span>Abrir módulo</span>
                                <i class="bi bi-arrow-right-short" style="font-size: 1.1rem; line-height: 1;"></i>
                            </a>
                        @endif

                        {{-- Alternar Leída/No Leída --}}
                        <form method="POST" action="{{ route('notifications.toggle-read', $n->id) }}" class="m-0">
                            @csrf
                            <button type="submit" class="btn btn-sm btn-outline-secondary d-inline-flex align-items-center gap-1" style="border-radius: 6px; font-weight: 500; font-size: 0.775rem; padding: 4px 10px;" title="{{ $isUnread ? 'Marcar como leída' : 'Marcar como no leída' }}">
                                <i class="bi {{ $isUnread ? 'bi-check2' : 'bi-arrow-counterclockwise' }}"></i>
                                <span>{{ $isUnread ? 'Marcar leída' : 'Marcar no leída' }}</span>
                            </button>
                        </form>

                        {{-- Eliminar Notificación --}}
                        <form method="POST" action="{{ route('notifications.destroy', $n->id) }}" class="m-0 ms-auto" data-confirm="La notificación se quitará de tu bandeja." data-confirm-title="¿Eliminar notificación?" data-confirm-button="Eliminar" data-confirm-danger="1">
                            @csrf
                            @method('DELETE')
                            <button type="submit" class="btn btn-sm btn-link text-muted p-1 hover-danger" title="Eliminar notificación">
                                <i class="bi bi-trash3" style="font-size: 0.85rem;"></i>
                            </button>
                        </form>
                    </div>
                </div>
            </div>
        @empty
            <div class="text-center py-5">
                <div class="d-inline-flex align-items-center justify-content-center rounded-circle bg-body-secondary text-secondary mb-3" style="width: 64px; height: 64px; font-size: 1.85rem;">
                    <i class="bi bi-bell-slash"></i>
                </div>
                <h4 class="h6 fw-bold text-body mb-1">
                    {{ $filter === 'unread' ? 'No tienes notificaciones sin leer' : 'No hay notificaciones en la bandeja' }}
                </h4>
                <p class="text-secondary small mb-3" style="max-width: 420px; margin: 0 auto;">
                    {{ $filter === 'unread' 
                        ? 'Estás completamente al día. Puedes revisar las notificaciones anteriores cambiando a la pestaña "Todas".' 
                        : 'Cuando haya solicitudes de vacaciones, justificaciones de tardanza o boletas emitidas, aparecerán aquí.' }}
                </p>
                @if($category || $search || $filter === 'unread')
                    <a href="{{ route('notifications.index', ['filter' => 'all']) }}" class="btn btn-sm btn-outline-primary" style="border-radius: 8px; font-weight: 600;">
                        Ver todas las notificaciones
                    </a>
                @endif
            </div>
        @endforelse
    </div>

    {{-- Paginación ERP --}}
    @if($notifications->total() > 0)
        <div class="card-footer bg-transparent p-3 d-flex flex-wrap align-items-center justify-content-between gap-3 border-top" style="border-color: var(--border-color) !important;">
            <div class="small text-secondary">
                Mostrando <strong>{{ $notifications->firstItem() ?? 0 }}</strong> a <strong>{{ $notifications->lastItem() ?? 0 }}</strong> de <strong>{{ $notifications->total() }}</strong> notificaciones
            </div>
            <div>
                {{ $notifications->links() }}
            </div>
        </div>
    @endif
</div>
@endsection
