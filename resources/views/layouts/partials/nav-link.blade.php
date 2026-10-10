<a href="{{ $href }}" class="sidebar-link {{ $active ? 'active' : '' }}" @if($active) aria-current="page" @endif>
    <i class="bi bi-{{ $icon }}"></i>
    <span class="flex-grow-1">{{ $label }}</span>
    @if($badge > 0)
        <span class="badge rounded-pill text-bg-danger">{{ $badge }}</span>
    @endif
</a>
