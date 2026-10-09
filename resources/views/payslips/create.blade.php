@extends('layouts.app')

@section('title', 'Subir boleta')

@section('content')
<nav aria-label="breadcrumb">
    <ol class="breadcrumb small mb-2">
        <li class="breadcrumb-item"><a href="{{ route('payslips.index') }}">Boletas</a></li>
        <li class="breadcrumb-item active" aria-current="page">Nueva</li>
    </ol>
</nav>
<div class="page-header">
    <div>
        <h1>Subir boleta de pago</h1>
        <p>Adjunta la boleta firmada en PDF para que el colaborador la vea en su app.</p>
    </div>
</div>

<form method="POST" action="{{ route('payslips.store') }}" enctype="multipart/form-data" class="card border-0" style="max-width: 760px;">
    @csrf
    <div class="card-body">
        <div class="row g-3">
            <div class="col-12">
                <label for="user_id" class="form-label">Colaborador <span class="text-danger">*</span></label>
                <select id="user_id" name="user_id" required class="form-select">
                    <option value="">Elige un colaborador</option>
                    @foreach($employees as $e)
                        <option value="{{ $e->id }}" @selected(old('user_id') == $e->id)>{{ $e->first_name }} {{ $e->last_name }} (DNI {{ $e->document_number }} · {{ $e->position ?? 'Operativo' }})</option>
                    @endforeach
                </select>
            </div>
            <div class="col-12">
                <label for="title" class="form-label">Título <span class="text-danger">*</span></label>
                <input type="text" id="title" name="title" value="{{ old('title', 'Boleta de pago mensual') }}" required class="form-control">
            </div>
            <div class="col-6">
                <label for="period_month" class="form-label">Mes <span class="text-danger">*</span></label>
                <select id="period_month" name="period_month" required class="form-select">
                    @foreach(range(1, 12) as $m)
                        <option value="{{ $m }}" @selected(old('period_month', date('n')) == $m)>{{ ucfirst(\Carbon\Carbon::create(null, $m, 1)->isoFormat('MMMM')) }}</option>
                    @endforeach
                </select>
            </div>
            <div class="col-6">
                <label for="period_year" class="form-label">Año <span class="text-danger">*</span></label>
                <input type="number" id="period_year" name="period_year" value="{{ old('period_year', date('Y')) }}" required class="form-control">
            </div>

            <div class="col-12"><h2 class="h6 fw-semibold mt-2 mb-0">Montos del periodo</h2></div>
            <div class="col-md-4">
                <label for="grossSalary" class="form-label">Sueldo bruto <span class="text-danger">*</span></label>
                <div class="input-group"><span class="input-group-text">S/</span>
                    <input type="number" step="0.01" min="0" id="grossSalary" name="gross_salary" value="{{ old('gross_salary', '0.00') }}" required class="form-control">
                </div>
            </div>
            <div class="col-md-4">
                <label for="deductions" class="form-label">Descuentos <span class="text-danger">*</span></label>
                <div class="input-group"><span class="input-group-text">S/</span>
                    <input type="number" step="0.01" min="0" id="deductions" name="deductions" value="{{ old('deductions', '0.00') }}" required class="form-control">
                </div>
            </div>
            <div class="col-md-4">
                <label for="netSalary" class="form-label">Neto a pagar <span class="text-danger">*</span></label>
                <div class="input-group"><span class="input-group-text">S/</span>
                    <input type="number" step="0.01" min="0" id="netSalary" name="net_salary" value="{{ old('net_salary', '0.00') }}" required class="form-control">
                </div>
            </div>

            <div class="col-12">
                <label for="pdf_file" class="form-label">Archivo PDF <span class="text-danger">*</span></label>
                <input type="file" id="pdf_file" name="pdf_file" accept=".pdf,application/pdf" required class="form-control" aria-describedby="pdf_help">
                <div id="pdf_help" class="form-text">Máximo 10 MB.</div>
            </div>
            <div class="col-12">
                <div class="form-check form-switch">
                    <input class="form-check-input" type="checkbox" role="switch" id="publish_now" name="publish_now" value="1" checked>
                    <label class="form-check-label" for="publish_now">Publicar de inmediato en la app</label>
                </div>
            </div>
        </div>
    </div>
    <div class="card-footer bg-transparent d-flex justify-content-end gap-2">
        <a href="{{ route('payslips.index') }}" class="btn btn-outline-secondary">Cancelar</a>
        <button type="submit" class="btn btn-primary">Subir boleta</button>
    </div>
</form>
@endsection

@section('scripts')
<script>
    // El neto se calcula al escribir bruto o descuentos
    const gross = document.getElementById('grossSalary');
    const ded = document.getElementById('deductions');
    const net = document.getElementById('netSalary');
    const calc = () => { net.value = Math.max(0, (parseFloat(gross.value) || 0) - (parseFloat(ded.value) || 0)).toFixed(2); };
    gross.addEventListener('input', calc);
    ded.addEventListener('input', calc);
</script>
@endsection
