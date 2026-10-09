@extends('layouts.app')

@section('title', 'Cargar Boleta de Pago PDF')

@section('content')
<div style="margin-bottom: 1.5rem;">
    <a href="{{ route('payslips.index') }}" style="color: #818cf8; font-size: 0.85rem; text-decoration: none; display: inline-flex; align-items: center; gap: 4px; margin-bottom: 0.5rem;">
        ← Volver a boletas de pago
    </a>
    <h1 style="font-size: 1.75rem; font-weight: 800; color: #fff;">Cargar Nueva Boleta de Pago</h1>
    <p style="color: var(--text-secondary); font-size: 0.9rem;">Adjunta el comprobante firmado en formato PDF para ponerlo a disposición del colaborador en su app móvil.</p>
</div>

@if ($errors->any())
    <div style="background: rgba(239, 68, 68, 0.15); border: 1px solid rgba(239, 68, 68, 0.3); border-radius: 12px; padding: 1rem 1.25rem; color: #fca5a5; font-size: 0.88rem; margin-bottom: 1.5rem;">
        <ul style="margin: 0; padding-left: 1.25rem;">
            @foreach ($errors->all() as $error)
                <li>{{ $error }}</li>
            @endforeach
        </ul>
    </div>
@endif

<form method="POST" action="{{ route('payslips.store') }}" enctype="multipart/form-data">
    @csrf

    <div style="max-width: 720px; background: var(--bg-surface); border: 1px solid var(--border); border-radius: var(--radius-lg); padding: 2rem;">
        <div style="margin-bottom: 1.25rem;">
            <label style="display: block; font-size: 0.85rem; color: #94a3b8; margin-bottom: 0.4rem;">Colaborador Destinatario *</label>
            <select name="user_id" required class="form-control" style="width: 100%; background: #1a1c26; border: 1px solid var(--border); color: #fff; padding: 0.7rem; border-radius: 8px;">
                <option value="">-- Seleccionar Colaborador --</option>
                @foreach($employees as $e)
                    <option value="{{ $e->id }}" {{ old('user_id') == $e->id ? 'selected' : '' }}>
                        {{ $e->first_name }} {{ $e->last_name }} (DNI: {{ $e->document_number }} - {{ $e->position ?? 'Operativo' }})
                    </option>
                @endforeach
            </select>
        </div>

        <div style="margin-bottom: 1.25rem;">
            <label style="display: block; font-size: 0.85rem; color: #94a3b8; margin-bottom: 0.4rem;">Título / Descripción de la Boleta *</label>
            <input type="text" name="title" value="{{ old('title', 'Boleta de Pago — Mensual') }}" required class="form-control" style="width: 100%; background: #1a1c26; border: 1px solid var(--border); color: #fff; padding: 0.7rem; border-radius: 8px;">
        </div>

        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 1rem; margin-bottom: 1.25rem;">
            <div>
                <label style="display: block; font-size: 0.85rem; color: #94a3b8; margin-bottom: 0.4rem;">Mes del Periodo *</label>
                <select name="period_month" required class="form-control" style="width: 100%; background: #1a1c26; border: 1px solid var(--border); color: #fff; padding: 0.7rem; border-radius: 8px;">
                    @foreach(range(1, 12) as $m)
                        @php $name = \Carbon\Carbon::create(null, $m, 1)->isoFormat('MMMM'); @endphp
                        <option value="{{ $m }}" {{ old('period_month', date('n')) == $m ? 'selected' : '' }}>{{ ucfirst($name) }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label style="display: block; font-size: 0.85rem; color: #94a3b8; margin-bottom: 0.4rem;">Año *</label>
                <input type="number" name="period_year" value="{{ old('period_year', date('Y')) }}" required class="form-control" style="width: 100%; background: #1a1c26; border: 1px solid var(--border); color: #fff; padding: 0.7rem; border-radius: 8px;">
            </div>
        </div>

        <!-- DESGLOSE ECONÓMICO -->
        <div style="background: #1a1c26; border-radius: 12px; padding: 1.25rem; margin-bottom: 1.5rem; border: 1px solid rgba(255,255,255,0.04);">
            <div style="font-weight: 700; color: #fff; font-size: 0.95rem; margin-bottom: 1rem;">Desglose Salarial del Periodo</div>

            <div style="display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 1rem;">
                <div>
                    <label style="display: block; font-size: 0.8rem; color: #94a3b8; margin-bottom: 0.3rem;">Sueldo Bruto *</label>
                    <input type="number" step="0.01" id="grossSalary" name="gross_salary" value="{{ old('gross_salary', '0.00') }}" required class="form-control" style="width: 100%; background: #111218; border: 1px solid var(--border); color: #fff; padding: 0.6rem; border-radius: 8px;">
                </div>
                <div>
                    <label style="display: block; font-size: 0.8rem; color: #94a3b8; margin-bottom: 0.3rem;">Deducciones / AFP *</label>
                    <input type="number" step="0.01" id="deductions" name="deductions" value="{{ old('deductions', '0.00') }}" required class="form-control" style="width: 100%; background: #111218; border: 1px solid var(--border); color: #fca5a5; padding: 0.6rem; border-radius: 8px;">
                </div>
                <div>
                    <label style="display: block; font-size: 0.8rem; color: #94a3b8; margin-bottom: 0.3rem;">Neto a Pagar *</label>
                    <input type="number" step="0.01" id="netSalary" name="net_salary" value="{{ old('net_salary', '0.00') }}" required class="form-control" style="width: 100%; background: #111218; border: 1px solid #10b981; color: #6ee7b7; font-weight: 700; padding: 0.6rem; border-radius: 8px;">
                </div>
            </div>
        </div>

        <!-- ARCHIVO PDF -->
        <div style="margin-bottom: 1.5rem;">
            <label style="display: block; font-size: 0.85rem; color: #94a3b8; margin-bottom: 0.4rem;">Archivo Boleta en Formato PDF * (Máximo 10MB)</label>
            <input type="file" name="pdf_file" accept=".pdf,application/pdf" required class="form-control" style="width: 100%; background: #1a1c26; border: 1px solid var(--border); color: #fff; padding: 0.6rem; border-radius: 8px;">
        </div>

        <div style="margin-bottom: 1.5rem;">
            <label style="display: flex; align-items: center; gap: 8px; font-size: 0.88rem; color: #fff; cursor: pointer;">
                <input type="checkbox" name="publish_now" value="1" checked style="accent-color: #10b981; width: 18px; height: 18px;">
                Publicar de inmediato (El colaborador recibirá notificación y podrá verla en su app)
            </label>
        </div>

        <div style="display: flex; gap: 10px;">
            <button type="submit" style="flex: 1; padding: 0.85rem; background: linear-gradient(135deg, #6366f1 0%, #4f46e5 100%); border: none; border-radius: 10px; color: #fff; font-weight: 700; cursor: pointer; box-shadow: 0 4px 15px rgba(99,102,241,0.35);">
                Subir y Guardar Boleta
            </button>
            <a href="{{ route('payslips.index') }}" style="padding: 0.85rem 1.25rem; background: rgba(255,255,255,0.05); border: 1px solid var(--border); border-radius: 10px; color: #cbd5e1; text-decoration: none; font-weight: 600; text-align: center;">
                Cancelar
            </a>
        </div>
    </div>
</form>
@endsection

@section('scripts')
<script>
    // Cálculo automático del sueldo neto al escribir
    const gross = document.getElementById('grossSalary');
    const ded = document.getElementById('deductions');
    const net = document.getElementById('netSalary');

    function calculateNet() {
        const g = parseFloat(gross.value) || 0;
        const d = parseFloat(ded.value) || 0;
        net.value = Math.max(0, (g - d)).toFixed(2);
    }

    gross.addEventListener('input', calculateNet);
    ded.addEventListener('input', calculateNet);
</script>
@endsection
