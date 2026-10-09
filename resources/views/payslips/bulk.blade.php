@extends('layouts.app')

@section('title', 'Carga masiva de boletas')

@section('content')
<div class="page-head">
    <div>
        <h1>Carga masiva de boletas PDF</h1>
        <p>Sube todas las boletas del período a la vez. Cada PDF se asigna al colaborador cuyo <strong>DNI</strong> o <strong>código de empleado</strong> aparece en el nombre del archivo, por ejemplo <code>boleta_45871236.pdf</code> o <code>EMP-003.pdf</code>.</p>
    </div>
    <a href="{{ route('payslips.index') }}" class="btn btn-ghost">← Volver a boletas</a>
</div>

@if ($errors->any())
    <div class="card" style="margin-bottom: 1rem; border-color: rgba(239,68,68,0.3);">
        <div class="card-body" style="color: #fca5a5;">
            @foreach ($errors->all() as $error)<div>{{ $error }}</div>@endforeach
        </div>
    </div>
@endif

<div class="grid-2">
    <form method="POST" action="{{ route('payslips.bulk.store') }}" enctype="multipart/form-data" class="card">
        @csrf
        <div class="card-body">
            <div class="grid-3">
                <div class="form-group">
                    <label class="form-label">Mes</label>
                    <select name="period_month" class="form-control">
                        @foreach(range(1, 12) as $m)
                            <option value="{{ $m }}" @selected(old('period_month', now()->month) == $m)>{{ ucfirst(\Carbon\Carbon::create(null, $m, 1)->locale('es')->isoFormat('MMMM')) }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="form-group">
                    <label class="form-label">Año</label>
                    <input type="number" name="period_year" class="form-control" value="{{ old('period_year', now()->year) }}" min="2020" max="2100">
                </div>
            </div>

            <div class="form-group">
                <label class="form-label">Archivos PDF (máx. 10 MB c/u)</label>
                <input type="file" name="files[]" accept="application/pdf" multiple required class="form-control" id="bulkFiles">
                <div class="text-muted" style="font-size: 12px; margin-top: 6px;" id="bulkCount">Ningún archivo seleccionado.</div>
            </div>

            <div class="form-group day-picker">
                <label><input type="checkbox" name="publish_now" value="1"> Publicar de inmediato en la app móvil</label>
            </div>

            <button class="btn btn-primary">Subir boletas</button>
            <p class="text-muted" style="font-size: 12px; margin-top: 8px;">Las boletas en borrador del mismo período se reemplazan. Las ya publicadas no se tocan.</p>
        </div>
    </form>

    <div class="card">
        <div class="card-header"><div class="card-title">Resultado de la última carga</div></div>
        <div class="card-body">
            @if($result)
                <ul class="result-list">
                    @foreach($result['created'] as $r)<li class="ok">✓ {{ $r['file'] }} → {{ $r['employee'] }}</li>@endforeach
                    @foreach($result['replaced'] as $r)<li class="ok">↻ {{ $r['file'] }} → {{ $r['employee'] }} (reemplazada)</li>@endforeach
                    @foreach($result['skipped'] as $r)<li class="err">✕ {{ $r['file'] }}: {{ $r['reason'] }}</li>@endforeach
                </ul>
            @else
                <p class="text-muted">Aún no hay cargas en esta sesión.</p>
            @endif
        </div>
    </div>
</div>
@endsection

@section('scripts')
<script>
    document.getElementById('bulkFiles').addEventListener('change', function () {
        const n = this.files.length;
        document.getElementById('bulkCount').innerText = n ? `${n} archivo(s) listos para subir.` : 'Ningún archivo seleccionado.';
    });
</script>
@endsection
