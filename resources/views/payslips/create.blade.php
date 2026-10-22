@extends('layouts.app')

@section('title', 'Subir boleta de pago')

@section('content')
<div class="payslips-page-wrapper">
    <nav aria-label="breadcrumb" class="mb-3">
        <ol class="breadcrumb small mb-0">
            <li class="breadcrumb-item"><a href="{{ route('payslips.index') }}" class="text-decoration-none">Boletas de pago</a></li>
            <li class="breadcrumb-item active" aria-current="page">Nueva boleta</li>
        </ol>
    </nav>

    <div class="payslips-header-card shadow-sm p-3 p-md-4 mb-4" style="max-width: 820px;">
        <div class="d-flex align-items-center justify-content-between flex-wrap gap-3">
            <div class="d-flex align-items-center">
                <div class="payslips-header-icon shadow-sm" style="margin-right: 16px !important;">
                    <i class="bi bi-cloud-arrow-up-fill"></i>
                </div>
                <div>
                    <h1 class="h4 fw-bold mb-1 text-body">Subir Boleta de Pago</h1>
                    <p class="text-secondary small mb-0">
                        Adjunta la boleta firmada en PDF para que el colaborador pueda visualizarla y descargarla desde su aplicación móvil.
                    </p>
                </div>
            </div>
            <a href="{{ route('payslips.index') }}" class="btn-executive-back shadow-xs">
                <i class="bi bi-arrow-left" style="margin-right: 8px !important;"></i>
                <span>Volver al listado</span>
            </a>
        </div>
    </div>

    <form method="POST" action="{{ route('payslips.store') }}" enctype="multipart/form-data" class="payslips-form-card shadow-sm border-0" style="max-width: 820px; border-radius: 12px; background: var(--bg-surface, #ffffff); overflow: hidden;">
        @csrf
        <div class="card-body p-4">
            <div class="row g-3">
                <div class="col-12">
                    <label for="user_id" class="form-label fw-semibold text-body small">Colaborador <span class="text-danger">*</span></label>
                    <select id="user_id" name="user_id" required class="form-select payslip-input">
                        <option value="">Selecciona un colaborador...</option>
                        @foreach($employees as $e)
                            <option value="{{ $e->id }}" @selected(old('user_id') == $e->id)>
                                {{ $e->first_name }} {{ $e->last_name }} (DNI {{ $e->document_number }} · {{ $e->position ?? 'Operativo' }})
                            </option>
                        @endforeach
                    </select>
                </div>
                <div class="col-12">
                    <label for="title" class="form-label fw-semibold text-body small">Título de la boleta <span class="text-danger">*</span></label>
                    <input type="text" id="title" name="title" value="{{ old('title', 'Boleta de pago mensual') }}" required class="form-control payslip-input">
                </div>
                <div class="col-6">
                    <label for="period_month" class="form-label fw-semibold text-body small">Mes <span class="text-danger">*</span></label>
                    <select id="period_month" name="period_month" required class="form-select payslip-input">
                        @foreach(range(1, 12) as $m)
                            <option value="{{ $m }}" @selected(old('period_month', date('n')) == $m)>
                                {{ ucfirst(\Carbon\Carbon::create(null, $m, 1)->locale('es')->isoFormat('MMMM')) }}
                            </option>
                        @endforeach
                    </select>
                </div>
                <div class="col-6">
                    <label for="period_year" class="form-label fw-semibold text-body small">Año <span class="text-danger">*</span></label>
                    <input type="number" id="period_year" name="period_year" value="{{ old('period_year', date('Y')) }}" min="2020" max="2035" required class="form-control payslip-input">
                </div>

                <div class="col-12">
                    <div class="p-3 rounded border" style="background: var(--bg-surface-elevated, #f8fafc);">
                        <div class="fw-bold small text-body mb-2">Desglose de Remuneración (Soles)</div>
                        <div class="row g-2">
                            <div class="col-md-4">
                                <label for="grossSalary" class="form-label small text-secondary">Sueldo Bruto <span class="text-danger">*</span></label>
                                <div class="input-group">
                                    <span class="input-group-text small">S/</span>
                                    <input type="number" step="0.01" min="0" id="grossSalary" name="gross_salary" value="{{ old('gross_salary', '0.00') }}" required class="form-control payslip-input font-monospace">
                                </div>
                            </div>
                            <div class="col-md-4">
                                <label for="deductions" class="form-label small text-secondary">Descuentos <span class="text-danger">*</span></label>
                                <div class="input-group">
                                    <span class="input-group-text small">S/</span>
                                    <input type="number" step="0.01" min="0" id="deductions" name="deductions" value="{{ old('deductions', '0.00') }}" required class="form-control payslip-input font-monospace text-danger">
                                </div>
                            </div>
                            <div class="col-md-4">
                                <label for="netSalary" class="form-label small text-secondary">Neto a Pagar <span class="text-danger">*</span></label>
                                <div class="input-group">
                                    <span class="input-group-text small">S/</span>
                                    <input type="number" step="0.01" min="0" id="netSalary" name="net_salary" value="{{ old('net_salary', '0.00') }}" required class="form-control payslip-input font-monospace fw-bold text-success">
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="col-12">
                    <label for="pdf_file" class="form-label fw-semibold text-body small">Archivo PDF <span class="text-danger">*</span></label>
                    <input type="file" id="pdf_file" name="pdf_file" accept=".pdf,application/pdf" required class="form-control payslip-input">
                    <div class="d-flex align-items-center justify-content-between p-2 rounded bg-light-subtle border mt-2" style="font-size: 0.775rem;">
                        <span class="text-secondary"><i class="bi bi-file-earmark-pdf me-1"></i>Documento PDF oficial firmado</span>
                        <span class="badge bg-secondary-subtle text-secondary">Máximo 10 MB</span>
                    </div>
                </div>

                <div class="col-12">
                    <div class="payslip-executive-switch-card p-3 rounded-2 border d-flex align-items-center justify-content-between" id="createPublishSwitchBox">
                        <div class="d-flex align-items-center">
                            <i class="bi bi-phone-vibrate-fill text-primary fs-4" style="margin-right: 14px !important; flex-shrink: 0;"></i>
                            <div>
                                <div class="fw-bold small text-body mb-0.5">Publicación Inmediata en App Móvil</div>
                                <div class="text-secondary small" style="font-size: 0.75rem;">El colaborador recibirá notificación push y podrá ver su boleta al instante.</div>
                            </div>
                        </div>
                        <div class="form-check form-switch m-0 p-0 d-flex align-items-center">
                            <input class="form-check-input custom-executive-switch m-0" type="checkbox" role="switch" id="publish_now" name="publish_now" value="1" checked>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <div class="card-footer border-top py-3 px-4 d-flex justify-content-end" style="background: var(--bg-surface-elevated, #f8fafc); gap: 10px;">
            <a href="{{ route('payslips.index') }}" class="btn btn-outline-secondary" style="height: 38px; border-radius: 6px; font-weight: 600; font-size: 0.85rem;">
                Cancelar
            </a>
            <button type="submit" class="btn btn-primary d-inline-flex align-items-center" style="height: 38px; border-radius: 6px; font-weight: 600; font-size: 0.85rem; padding: 0 1.25rem;">
                <i class="bi bi-cloud-arrow-up-fill" style="margin-right: 8px !important;"></i>
                <span>Subir boleta</span>
            </button>
        </div>
    </form>
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
    background: linear-gradient(135deg, #0284c7 0%, #2563eb 100%);
    color: #ffffff;
    border-radius: 10px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 1.35rem;
    flex-shrink: 0;
}
.payslip-input {
    height: 38px !important;
    border-radius: 6px !important;
    font-size: 0.85rem !important;
    border: 1px solid var(--border-color, #cbd5e1) !important;
    background-color: var(--bg-surface-elevated, #ffffff) !important;
}
.payslip-input:focus {
    border-color: #0284c7 !important;
    box-shadow: 0 0 0 3px rgba(2, 132, 199, 0.15) !important;
}
[data-bs-theme="dark"] .payslips-header-card,
[data-bs-theme="dark"] .payslips-form-card {
    background: #111622 !important;
    border-color: #1e2638 !important;
}
</style>
@endsection

@section('scripts')
<script>
    const gross = document.getElementById('grossSalary');
    const ded = document.getElementById('deductions');
    const net = document.getElementById('netSalary');
    const calc = () => { net.value = Math.max(0, (parseFloat(gross.value) || 0) - (parseFloat(ded.value) || 0)).toFixed(2); };
    gross?.addEventListener('input', calc);
    ded?.addEventListener('input', calc);
</script>
@endsection
