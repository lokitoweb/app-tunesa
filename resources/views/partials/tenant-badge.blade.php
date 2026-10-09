{{-- Empresa del registro; solo se pinta en la vista global del Super Admin --}}
@if (($globalView ?? false) && isset($tenantId))
    <span class="badge rounded-pill text-bg-primary bg-opacity-10 text-primary-emphasis border border-primary-subtle fw-medium tenant-badge" title="Empresa">{{ $tenantNames[$tenantId] ?? 'Empresa #' . $tenantId }}</span>
@endif
