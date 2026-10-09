@extends('layouts.app')

@section('title', 'Notificaciones')

@section('content')
<div class="page-header">
    <div>
        <h1>Notificaciones</h1>
        <p>Solicitudes por aprobar, decisiones y avisos de la plataforma.</p>
    </div>
    <form method="POST" action="{{ route('notifications.read-all') }}">
        @csrf
        <button type="submit" class="btn btn-outline-primary"><i class="bi bi-check2-all me-1"></i>Marcar todas como leídas</button>
    </form>
</div>

<ul class="nav nav-pills mb-3">
    <li class="nav-item"><a class="nav-link {{ $filter === 'all' ? 'active' : '' }}" href="{{ route('notifications.index') }}">Todas</a></li>
    <li class="nav-item"><a class="nav-link {{ $filter === 'unread' ? 'active' : '' }}" href="{{ route('notifications.index', ['filter' => 'unread']) }}">Sin leer</a></li>
</ul>

<div class="card border-0">
    <div class="list-group list-group-flush">
        @forelse($notifications as $n)
            <div class="list-group-item d-flex gap-3 align-items-start py-3 {{ $n->read_at ? '' : 'notif-unread' }}">
                <span class="kpi-icon bg-primary-subtle text-primary-emphasis flex-shrink-0"><i class="bi bi-{{ \App\Http\Controllers\Web\NotificationWebController::icon($n->data['type'] ?? null) }}"></i></span>
                <div class="flex-grow-1 min-w-0">
                    <div class="d-flex flex-wrap justify-content-between gap-2">
                        <span class="fw-semibold">{{ $n->data['title'] ?? 'Notificación' }}</span>
                        <span class="small text-body-secondary" title="{{ $n->created_at?->format('d/m/Y H:i') }}">{{ $n->created_at?->diffForHumans() }}</span>
                    </div>
                    <div class="small text-body-secondary">{{ $n->data['body'] ?? '' }}</div>
                </div>
                @if(! $n->read_at || \App\Http\Controllers\Web\NotificationWebController::targetUrl($n, Auth::user()))
                    <form method="POST" action="{{ route('notifications.read', $n->id) }}" class="m-0 flex-shrink-0">
                        @csrf
                        <button type="submit" class="btn btn-sm btn-outline-secondary">
                            {{ \App\Http\Controllers\Web\NotificationWebController::targetUrl($n, Auth::user()) ? 'Abrir' : 'Marcar leída' }}
                        </button>
                    </form>
                @endif
            </div>
        @empty
            <div class="empty-state"><i class="bi bi-bell-slash"></i>No tienes notificaciones{{ $filter === 'unread' ? ' sin leer' : '' }}.</div>
        @endforelse
    </div>
    @if($notifications->hasPages())
        <div class="card-footer bg-transparent">{{ $notifications->links() }}</div>
    @endif
</div>
@endsection
