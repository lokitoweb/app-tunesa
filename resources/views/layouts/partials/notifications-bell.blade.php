{{-- Campana de notificaciones del topbar: no leídas + últimas 8 --}}
@php
    $bellUser    = Auth::user();
    $bellUnread  = $bellUser->unreadNotifications()->count();
    $bellLatest  = $bellUser->notifications()->limit(8)->get();
@endphp
<div class="dropdown">
    <button type="button" class="btn btn-light border btn-sm position-relative" data-bs-toggle="dropdown" data-bs-auto-close="outside" aria-expanded="false"
            aria-label="Notificaciones{{ $bellUnread ? " ({$bellUnread} sin leer)" : '' }}" title="Notificaciones">
        <i class="bi bi-bell"></i>
        @if($bellUnread > 0)
            <span class="position-absolute top-0 start-100 translate-middle badge rounded-pill text-bg-danger notif-count">{{ $bellUnread > 99 ? '99+' : $bellUnread }}</span>
        @endif
    </button>
    <div class="dropdown-menu dropdown-menu-end p-0 notif-menu shadow">
        <div class="d-flex justify-content-between align-items-center px-3 py-2 border-bottom">
            <span class="fw-semibold small">Notificaciones</span>
            @if($bellUnread > 0)
                <form method="POST" action="{{ route('notifications.read-all') }}" class="m-0">
                    @csrf
                    <button type="submit" class="btn btn-link btn-sm p-0 text-decoration-none">Marcar todas como leídas</button>
                </form>
            @endif
        </div>
        <div class="notif-list">
            @forelse($bellLatest as $n)
                <form method="POST" action="{{ route('notifications.read', $n->id) }}" class="m-0">
                    @csrf
                    <button type="submit" class="dropdown-item d-flex gap-2 py-2 text-wrap {{ $n->read_at ? '' : 'notif-unread' }}">
                        <i class="bi bi-{{ \App\Modules\Core\Controllers\Web\NotificationWebController::icon($n->data['type'] ?? null) }} text-body-secondary mt-1"></i>
                        <span class="min-w-0">
                            <span class="d-block small fw-semibold">{{ $n->data['title'] ?? 'Notificación' }}</span>
                            <span class="d-block small text-body-secondary">{{ \Illuminate\Support\Str::limit($n->data['body'] ?? '', 110) }}</span>
                            <span class="d-block text-body-tertiary" style="font-size: .72rem;">{{ $n->created_at?->diffForHumans() }}</span>
                        </span>
                    </button>
                </form>
            @empty
                <div class="px-3 py-4 text-center small text-body-secondary"><i class="bi bi-bell-slash d-block fs-4 mb-1 opacity-50"></i>No tienes notificaciones.</div>
            @endforelse
        </div>
        <a href="{{ route('notifications.index') }}" class="d-block text-center small py-2 border-top text-decoration-none">Ver todas</a>
    </div>
</div>
