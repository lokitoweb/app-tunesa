{{-- Empresa del registro; solo se pinta en la vista global del Super Admin --}}
@if (($globalView ?? false) && isset($tenantId))
    <span class="tenant-badge" title="Empresa"><i class="bi bi-buildings"></i><span>{{ $tenantNames[$tenantId] ?? 'Empresa #' . $tenantId }}</span></span>
@endif
