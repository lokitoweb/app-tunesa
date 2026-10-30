@extends('layouts.app')

@section('title', 'Carga masiva de boletas')

@section('content')
<div class="payslips-page-wrapper">
    <nav aria-label="breadcrumb" class="mb-3">
        <ol class="breadcrumb small mb-0">
            <li class="breadcrumb-item"><a href="{{ route('payslips.index') }}" class="text-decoration-none">Boletas de pago</a></li>
            <li class="breadcrumb-item active" aria-current="page">Carga masiva</li>
        </ol>
    </nav>

    <div class="payslips-header-card shadow-sm p-3 p-md-4 mb-4">
        <div class="d-flex align-items-center justify-content-between flex-wrap gap-3">
            <div class="d-flex align-items-center">
                <div class="payslips-header-icon shadow-sm" style="margin-right: 16px !important;">
                    <i class="bi bi-files"></i>
                </div>
                <div>
                    <h1 class="h4 fw-bold mb-1 text-body">Carga Masiva de Boletas</h1>
                    <p class="text-secondary small mb-0">
                        Sube lotes de boletas en PDF. Cada archivo se vincula automáticamente mediante el <strong>DNI</strong> (ej: <code>boleta_45871236.pdf</code>) o <strong>Código</strong> (ej: <code>EMP-003.pdf</code>).
                    </p>
                </div>
            </div>
            <a href="{{ route('payslips.index') }}" class="btn-executive-back shadow-xs">
                <i class="bi bi-arrow-left" style="margin-right: 8px !important;"></i>
                <span>Volver al listado</span>
            </a>
        </div>
    </div>

    <div class="row g-4">
        {{-- Formulario de carga masiva --}}
        <div class="col-12 col-xl-6">
            <form method="POST" action="{{ route('payslips.bulk.store') }}" enctype="multipart/form-data" class="payslips-form-card shadow-sm border-0 h-100" style="border-radius: 12px; background: var(--bg-surface, #ffffff); overflow: hidden;">
                @csrf
                <div class="card-header border-bottom py-3 px-4 d-flex align-items-center" style="background: var(--bg-surface-elevated, #f8fafc);">
                    <i class="bi bi-cloud-arrow-up-fill text-primary fs-5" style="margin-right: 10px !important;"></i>
                    <h2 class="h6 fw-bold mb-0 text-body">Subir Archivos de Boletas</h2>
                </div>

                <div class="card-body p-4">
                    {{-- Alerta Informativa con Icono con Margen Limpio y Alto Contraste --}}
                    <div class="alert payslip-info-alert border-0 d-flex align-items-start mb-3.5 p-3 rounded-2">
                        <i class="bi bi-info-circle-fill fs-5" style="margin-right: 12px !important; flex-shrink: 0; margin-top: 1px;"></i>
                        <div class="small">
                            <strong>Importante:</strong> Los borradores existentes para el mismo colaborador y periodo se actualizarán con el nuevo archivo. Las boletas ya publicadas se protegen y omiten.
                        </div>
                    </div>

                    <div class="row g-3">
                        <div class="col-6">
                            <label for="period_month" class="form-label fw-semibold text-body small">Mes de emisión <span class="text-danger">*</span></label>
                            <select id="period_month" name="period_month" required class="form-select payslip-input">
                                @foreach(range(1, 12) as $m)
                                    <option value="{{ $m }}" @selected(old('period_month', now()->month) == $m)>
                                        {{ ucfirst(\Carbon\Carbon::create(null, $m, 1)->locale('es')->isoFormat('MMMM')) }}
                                    </option>
                                @endforeach
                            </select>
                        </div>
                        <div class="col-6">
                            <label for="period_year" class="form-label fw-semibold text-body small">Año de emisión <span class="text-danger">*</span></label>
                            <input type="number" id="period_year" name="period_year" class="form-control payslip-input" value="{{ old('period_year', now()->year) }}" min="2020" max="2100" required>
                        </div>

                        <div class="col-12">
                            <label for="bulkFiles" class="form-label fw-semibold text-body small">Archivos PDF <span class="text-danger">*</span></label>
                            <input type="file" name="files[]" accept="application/pdf,.pdf" multiple required class="form-control payslip-input" id="bulkFiles" aria-describedby="bulkCount">
                            <div class="d-flex align-items-center justify-content-between p-2 rounded border mt-2 payslip-file-hint-bar" style="font-size: 0.775rem;">
                                <span class="text-secondary" id="bulkCount"><i class="bi bi-paperclip" style="margin-right: 6px !important;"></i>Ningún archivo seleccionado.</span>
                                <span class="badge bg-secondary-subtle text-secondary">Máx. 10 MB por archivo</span>
                            </div>
                        </div>

                        {{-- Interruptor Ejecutivo: SOLO ICONO SIN TARJETA INTERMEDIA --}}
                        <div class="col-12">
                            <div class="payslip-executive-switch-card p-3 rounded-2 border d-flex align-items-center justify-content-between" id="bulkPageSwitchBox">
                                <div class="d-flex align-items-center">
                                    <i class="bi bi-send-check-fill text-primary fs-4" style="margin-right: 14px !important; flex-shrink: 0;"></i>
                                    <div>
                                        <div class="fw-bold small text-body mb-0.5">Publicar de inmediato todas las boletas cargadas</div>
                                        <div class="text-secondary small" style="font-size: 0.75rem;">Se emitirá notificación push masiva a todos los colaboradores con boleta cargada.</div>
                                    </div>
                                </div>
                                <div class="form-check form-switch m-0 p-0 d-flex align-items-center">
                                    <input class="form-check-input custom-executive-switch m-0" type="checkbox" role="switch" id="publish_now" name="publish_now" value="1">
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="card-footer border-top py-3 px-4" style="background: var(--bg-surface-elevated, #f8fafc);">
                    <button type="submit" class="btn btn-primary w-100 d-inline-flex align-items-center justify-content-center" style="height: 42px; border-radius: 6px; font-weight: 600; font-size: 0.9rem;">
                        <i class="bi bi-upload" style="margin-right: 8px !important;"></i>
                        <span>Procesar y subir boletas masivamente</span>
                    </button>
                </div>
            </form>
        </div>

        {{-- Panel de Resultados de Carga --}}
        <div class="col-12 col-xl-6">
            <div class="payslips-form-card shadow-sm border-0 h-100" style="border-radius: 12px; background: var(--bg-surface, #ffffff); overflow: hidden;">
                <div class="card-header border-bottom py-3 px-4 d-flex align-items-center justify-content-between" style="background: var(--bg-surface-elevated, #f8fafc);">
                    <div class="d-flex align-items-center">
                        <i class="bi bi-clock-history text-secondary fs-5" style="margin-right: 10px !important;"></i>
                        <h2 class="h6 fw-bold mb-0 text-body">Resultado de la Última Carga</h2>
                    </div>
                    @if($result)
                        <span class="badge bg-primary-subtle text-primary border px-2 py-0.5 small">
                            {{ count($result['created'] ?? []) + count($result['replaced'] ?? []) }} procesadas
                        </span>
                    @endif
                </div>

                <div class="card-body p-0">
                    @if($result)
                        <div class="p-3 border-bottom d-flex align-items-center justify-content-around bg-light-subtle text-center">
                            <div>
                                <span class="d-block h5 fw-bold text-success mb-0">{{ count($result['created'] ?? []) }}</span>
                                <span class="small text-muted" style="font-size: 0.75rem;">Nuevas</span>
                            </div>
                            <div class="border-start border-end px-3">
                                <span class="d-block h5 fw-bold text-primary mb-0">{{ count($result['replaced'] ?? []) }}</span>
                                <span class="small text-muted" style="font-size: 0.75rem;">Reemplazadas</span>
                            </div>
                            <div>
                                <span class="d-block h5 fw-bold text-danger mb-0">{{ count($result['skipped'] ?? []) }}</span>
                                <span class="small text-muted" style="font-size: 0.75rem;">Omitidas</span>
                            </div>
                        </div>

                        <ul class="list-group list-group-flush small" style="max-height: 420px; overflow-y: auto;">
                            @foreach($result['created'] ?? [] as $r)
                                <li class="list-group-item d-flex align-items-center text-success py-2.5 px-4">
                                    <i class="bi bi-check-circle-fill flex-shrink-0" style="margin-right: 10px !important;"></i>
                                    <div class="text-truncate">
                                        <strong class="text-body font-monospace">{{ $r['file'] }}</strong>
                                        <span class="text-muted mx-1">→</span>
                                        <span>{{ $r['employee'] }}</span>
                                    </div>
                                </li>
                            @endforeach
                            @foreach($result['replaced'] ?? [] as $r)
                                <li class="list-group-item d-flex align-items-center text-primary py-2.5 px-4">
                                    <i class="bi bi-arrow-repeat flex-shrink-0" style="margin-right: 10px !important;"></i>
                                    <div class="text-truncate">
                                        <strong class="text-body font-monospace">{{ $r['file'] }}</strong>
                                        <span class="text-muted mx-1">→</span>
                                        <span>{{ $r['employee'] }}</span>
                                        <span class="badge bg-primary-subtle text-primary ms-1 small">Reemplazada</span>
                                    </div>
                                </li>
                            @endforeach
                            @foreach($result['skipped'] ?? [] as $r)
                                <li class="list-group-item d-flex align-items-center text-danger py-2.5 px-4">
                                    <i class="bi bi-x-circle-fill flex-shrink-0" style="margin-right: 10px !important;"></i>
                                    <div class="text-truncate">
                                        <strong class="text-body font-monospace">{{ $r['file'] }}</strong>:
                                        <span class="text-secondary">{{ $r['reason'] }}</span>
                                    </div>
                                </li>
                            @endforeach
                        </ul>
                    @else
                        <div class="text-center p-5">
                            <div class="payslips-empty-icon mb-3 shadow-xs">
                                <i class="bi bi-folder2-open"></i>
                            </div>
                            <h3 class="h6 fw-bold text-body mb-1">Aún no hay cargas en esta sesión</h3>
                            <p class="text-secondary small mb-0 mx-auto" style="max-width: 340px;">
                                Los resultados y el desglose de archivos vinculados u omitidos aparecerán aquí inmediatamente después de enviar el formulario.
                            </p>
                        </div>
                    @endif
                </div>
            </div>
        </div>
    </div>
</div>
@endsection

@section('styles')
<style>
.payslips-header-card {
    background: var(--bg-surface, #ffffff);
    border: 1px solid var(--border-color, rgba(0, 0, 0, 0.08));
    border-radius: 12px;
}
.payslips-header-icon {
    width: 48px;
    height: 48px;
    background: linear-gradient(135deg, var(--brand) 0%, #2563eb 100%);
    color: #ffffff;
    border-radius: 10px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 1.35rem;
    flex-shrink: 0;
}
.btn-executive-back {
    height: 38px;
    padding: 0 16px;
    border-radius: 6px;
    display: inline-flex;
    align-items: center;
    font-weight: 600;
    font-size: 0.85rem;
    text-decoration: none;
    color: var(--text-secondary, #475569);
    background: var(--bg-surface-elevated, #f8fafc);
    border: 1px solid var(--border-color, #cbd5e1);
    transition: all 0.15s ease;
}
.btn-executive-back:hover {
    background: var(--bg-surface, #ffffff);
    color: var(--brand);
    border-color: var(--brand);
    transform: translateX(-2px);
}
.payslip-input {
    height: 38px !important;
    border-radius: 6px !important;
    font-size: 0.85rem !important;
    border: 1px solid var(--border-color, #cbd5e1) !important;
    background-color: var(--bg-surface-elevated, #ffffff) !important;
}
.payslip-input:focus {
    border-color: var(--brand) !important;
    box-shadow: 0 0 0 3px rgba(var(--brand-rgb), 0.15) !important;
}
.payslip-info-alert {
    background: rgba(var(--brand-rgb), 0.08) !important;
    color: #0369a1 !important;
    border: 1px solid rgba(var(--brand-rgb), 0.15) !important;
}
.payslip-file-hint-bar {
    background: var(--bg-surface-elevated, #f8fafc);
    border-color: var(--border-color, #e2e8f0) !important;
}
.payslips-empty-icon {
    width: 60px;
    height: 60px;
    border-radius: 50%;
    background: var(--bg-surface-elevated, #f1f5f9);
    color: var(--text-muted, #94a3b8);
    display: inline-flex;
    align-items: center;
    justify-content: center;
    font-size: 1.6rem;
}
[data-bs-theme="dark"] .payslips-header-card,
[data-bs-theme="dark"] .payslips-form-card {
    background: #111622 !important;
    border-color: #1e2638 !important;
}
[data-bs-theme="dark"] .btn-executive-back {
    background: #161f30 !important;
    border-color: #243048 !important;
    color: #94a3b8 !important;
}
[data-bs-theme="dark"] .btn-executive-back:hover {
    background: #1c273e !important;
    color: #38bdf8 !important;
    border-color: #38bdf8 !important;
}
[data-bs-theme="dark"] .payslip-info-alert {
    background: rgba(var(--brand-rgb), 0.12) !important;
    border-color: rgba(56, 189, 248, 0.25) !important;
    color: #e0f2fe !important;
}
[data-bs-theme="dark"] .payslip-info-alert strong {
    color: #38bdf8 !important;
}
[data-bs-theme="dark"] .payslip-file-hint-bar {
    background: #161f30 !important;
    border-color: #243048 !important;
}
[data-bs-theme="dark"] .payslips-empty-icon {
    background: #161f30 !important;
    color: #64748b !important;
}
</style>
@endsection

@section('scripts')
<script>
    document.getElementById('bulkFiles')?.addEventListener('change', function () {
        const n = this.files ? this.files.length : 0;
        const note = document.getElementById('bulkCount');
        if (note) {
            note.innerHTML = n 
                ? `<i class="bi bi-files text-success" style="margin-right: 6px !important;"></i><strong>${n} archivo(s)</strong> listos para subir.` 
                : '<i class="bi bi-paperclip" style="margin-right: 6px !important;"></i>Ningún archivo seleccionado.';
        }
    });

    const bulkSwitch = document.getElementById('publish_now');
    const bulkSwitchBox = document.getElementById('bulkPageSwitchBox');
    if (bulkSwitch && bulkSwitchBox) {
        bulkSwitch.addEventListener('change', function () {
            if (this.checked) {
                bulkSwitchBox.classList.add('is-active');
            } else {
                bulkSwitchBox.classList.remove('is-active');
            }
        });
    }
</script>
@endsection
