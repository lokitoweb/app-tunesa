{{-- Empresa del registro; solo se pinta en la vista global del Super Admin --}}
@if (($globalView ?? false) && isset($tenantId))
    <span class="tenant-badge" title="Empresa" style="display: inline-block; margin-top: 3px; font-size: 0.7rem; font-weight: 600; padding: 1px 8px; border-radius: 999px; background: rgba(59, 130, 246, 0.15); color: #93c5fd; border: 1px solid rgba(59, 130, 246, 0.3);">{{ $tenantNames[$tenantId] ?? 'Empresa #' . $tenantId }}</span>
@endif
