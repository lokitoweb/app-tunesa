@extends('layouts.app')

@section('title', 'Carga masiva de boletas')

@section('content')
<nav aria-label="breadcrumb">
    <ol class="breadcrumb small mb-2">
        <li class="breadcrumb-item"><a href="{{ route('payslips.index') }}">Boletas</a></li>
        <li class="breadcrumb-item active" aria-current="page">Carga masiva</li>
    </ol>
</nav>
<div class="page-header">
    <div>
        <h1>Carga masiva de boletas</h1>
        <p>Cada PDF se asigna al colaborador cuyo <strong>DNI</strong> o <strong>código</strong> aparece en el nombre del archivo, por ejemplo <code>boleta_45871236.pdf</code> o <code>EMP-003.pdf</code>.</p>
    </div>
</div>

<div class="row g-3">
    <div class="col-12 col-xl-6">
        <form method="POST" action="{{ route('payslips.bulk.store') }}" enctype="multipart/form-data" class="card border-0 h-100">
            @csrf
            <div class="card-body">
                <div class="row g-3">
                    <div class="col-6">
                        <label for="period_month" class="form-label">Mes</label>
                        <select id="period_month" name="period_month" class="form-select">
                            @foreach(range(1, 12) as $m)
                                <option value="{{ $m }}" @selected(old('period_month', now()->month) == $m)>{{ ucfirst(\Carbon\Carbon::create(null, $m, 1)->locale('es')->isoFormat('MMMM')) }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="col-6">
                        <label for="period_year" class="form-label">Año</label>
                        <input type="number" id="period_year" name="period_year" class="form-control" value="{{ old('period_year', now()->year) }}" min="2020" max="2100">
                    </div>
                    <div class="col-12">
                        <label for="bulkFiles" class="form-label">Archivos PDF <span class="text-body-secondary">(máx. 10 MB cada uno)</span></label>
                        <input type="file" name="files[]" accept="application/pdf" multiple required class="form-control" id="bulkFiles" aria-describedby="bulkCount">
                        <div class="form-text" id="bulkCount">Ningún archivo seleccionado.</div>
                    </div>
                    <div class="col-12">
                        <div class="form-check form-switch">
                            <input class="form-check-input" type="checkbox" role="switch" id="publish_now" name="publish_now" value="1">
                            <label class="form-check-label" for="publish_now">Publicar de inmediato en la app</label>
                        </div>
                    </div>
                </div>
            </div>
            <div class="card-footer bg-transparent">
                <button class="btn btn-primary w-100"><i class="bi bi-upload me-1"></i>Subir boletas</button>
                <div class="form-text">Las boletas en borrador del mismo periodo se reemplazan. Las publicadas no se tocan.</div>
            </div>
        </form>
    </div>

    <div class="col-12 col-xl-6">
        <div class="card border-0 h-100">
            <div class="card-header border-0 pt-3"><h2 class="h6 fw-semibold mb-0">Resultado de la última carga</h2></div>
            @if($result)
                <ul class="list-group list-group-flush small">
                    @foreach($result['created'] as $r)<li class="list-group-item text-success"><i class="bi bi-check-circle me-1"></i>{{ $r['file'] }} → {{ $r['employee'] }}</li>@endforeach
                    @foreach($result['replaced'] as $r)<li class="list-group-item text-success"><i class="bi bi-arrow-repeat me-1"></i>{{ $r['file'] }} → {{ $r['employee'] }} (reemplazada)</li>@endforeach
                    @foreach($result['skipped'] as $r)<li class="list-group-item text-danger"><i class="bi bi-x-circle me-1"></i>{{ $r['file'] }}: {{ $r['reason'] }}</li>@endforeach
                </ul>
            @else
                <div class="empty-state">Aún no hay cargas en esta sesión.</div>
            @endif
        </div>
    </div>
</div>
@endsection

@section('scripts')
<script>
    document.getElementById('bulkFiles').addEventListener('change', function () {
        const n = this.files.length;
        document.getElementById('bulkCount').textContent = n ? `${n} archivo(s) listos para subir.` : 'Ningún archivo seleccionado.';
    });
</script>
@endsection
