@extends('layouts.app')

@section('title', 'Elegir empresa')

{{--
    Super Admin en la vista global: "¿En qué empresa?" antes de crear o editar.
    Sin $replay elige y abre la pantalla pedida ($next). Con $replay reenvía el
    formulario que se envió, con la empresa elegida (no se pierde lo escrito).
--}}
@section('content')
@php
    $fieldName = static fn (string $key) => collect(explode('.', $key))
        ->map(fn ($part, $i) => $i === 0 ? $part : "[{$part}]")->implode('');
@endphp
<div class="tenant-picker-backdrop-page" aria-hidden="true">
    <i class="bi bi-buildings"></i>
</div>

<div class="modal fade" id="tenantPickerModal" tabindex="-1" data-bs-backdrop="static" data-bs-keyboard="false" aria-labelledby="tenantPickerTitle" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-dialog-scrollable">
        <div class="modal-content border-0 shadow-lg tenant-picker-card">
            @if($replay)
                <form method="POST" action="{{ $replay['action'] }}" id="tenantPickerForm">
                    @csrf
                    @if(strtoupper($replay['method']) !== 'POST')
                        @method($replay['method'])
                    @endif
                    @foreach($replay['fields'] as $key => $value)
                        @if(is_scalar($value) || $value === null)
                            <input type="hidden" name="{{ $fieldName($key) }}" value="{{ is_bool($value) ? (int) $value : $value }}">
                        @endif
                    @endforeach
                    @php($radioName = '_tenant')
            @else
                <form method="POST" action="{{ route('admin.tenant.switch') }}" id="tenantPickerForm">
                    @csrf
                    <input type="hidden" name="next" value="{{ $next }}">
                    @php($radioName = 'tenant_id')
            @endif

                <div class="modal-header border-bottom py-3 px-4">
                    <div class="d-flex align-items-center">
                        <div class="modal-header-icon bg-primary-subtle text-primary" style="margin-right: 14px !important;">
                            <i class="bi bi-buildings-fill"></i>
                        </div>
                        <div>
                            <h5 class="modal-title fw-bold text-body mb-0" id="tenantPickerTitle">¿En qué empresa?</h5>
                            <p class="text-secondary small mb-0">
                                {{ $replay ? 'Elige la empresa del registro y lo guardamos con los datos que ya escribiste.' : 'Elige la empresa en la que vas a crear o editar.' }}
                            </p>
                        </div>
                    </div>
                </div>

                <div class="modal-body p-4">
                    @if($tenants->count() > 6)
                        <div class="tenant-picker-search mb-3">
                            <i class="bi bi-search"></i>
                            <input type="search" class="form-control" id="tenantPickerSearch" placeholder="Buscar empresa o RUC..." autocomplete="off" aria-label="Buscar empresa">
                        </div>
                    @endif

                    <div class="tenant-picker-list" role="radiogroup" aria-labelledby="tenantPickerTitle">
                        @forelse($tenants as $t)
                            <label class="tenant-picker-option" data-label="{{ mb_strtolower($t->company_name . ' ' . $t->ruc) }}">
                                <input type="radio" name="{{ $radioName }}" value="{{ $t->id }}" required @checked($loop->first && $tenants->count() === 1)>
                                <span class="tenant-picker-avatar">{{ mb_strtoupper(mb_substr($t->company_name, 0, 1)) }}</span>
                                <span class="tenant-picker-text">
                                    <span class="tenant-picker-name">{{ $t->company_name }}</span>
                                    @if($t->ruc)
                                        <span class="tenant-picker-sub">RUC {{ $t->ruc }}</span>
                                    @endif
                                </span>
                                <i class="bi bi-check-circle-fill tenant-picker-check"></i>
                            </label>
                        @empty
                            <p class="text-secondary small mb-0">No hay empresas activas. Activa o crea una empresa primero.</p>
                        @endforelse
                    </div>
                    <p class="text-secondary small mt-3 mb-0">
                        <i class="bi bi-info-circle me-1"></i>
                        El panel pasará a administrar esa empresa. Para volver al consolidado elige "Todas las empresas" arriba.
                    </p>
                </div>

                <div class="modal-footer border-top py-2 px-4">
                    <a href="{{ $cancel }}"
                       class="btn btn-outline-secondary" style="height: 38px; border-radius: 6px; font-weight: 600; font-size: 0.85rem;">
                        Cancelar
                    </a>
                    <button type="submit" class="btn btn-primary d-inline-flex align-items-center" style="height: 38px; border-radius: 6px; font-weight: 600; font-size: 0.85rem; padding: 0 1.25rem;" @disabled($tenants->isEmpty())>
                        <span>Continuar</span>
                        <i class="bi bi-arrow-right" style="margin-left: 8px !important;"></i>
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>
@endsection

@push('scripts')
<script>
(() => {
    const el = document.getElementById('tenantPickerModal');
    bootstrap.Modal.getOrCreateInstance(el).show();

    const search = document.getElementById('tenantPickerSearch');
    search?.addEventListener('input', () => {
        const q = search.value.trim().toLowerCase();
        el.querySelectorAll('.tenant-picker-option').forEach((opt) => {
            opt.hidden = q !== '' && !opt.dataset.label.includes(q);
        });
    });
})();
</script>
@endpush
