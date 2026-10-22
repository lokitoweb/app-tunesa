<a href="{{ $href }}" class="sidebar-link {{ $active ? 'active' : '' }}" @if($active) aria-current="page" @endif title="{{ $label }}">
    <i class="bi bi-{{ $icon }}"></i>
    <span class="link-label">{{ $label }}</span>
    @if($badge > 0)
        <span class="badge rounded-pill text-bg-danger sidebar-badge ms-auto">{{ $badge }}</span>
    @endif
</a>
