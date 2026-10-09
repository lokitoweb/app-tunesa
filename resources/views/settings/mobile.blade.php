@extends('layouts.app')

@section('title', 'Configuración de la app')

@section('content')
@php
    use App\Models\CompanyConfiguration;
    use App\Models\EmployeeDocument;
    $children = ['mobile_documents_upload' => 'mobile_documents_enabled', 'mobile_payment_info_edit' => 'mobile_payment_info_enabled', 'mobile_uniforms_edit' => 'mobile_uniforms_enabled'];
@endphp

<div class="page-head">
    <div>
        <h1>Configuración de la app móvil</h1>
        <p>Decide qué ve y qué puede editar el personal desde su celular. Los cambios aplican la próxima vez que abren la app.</p>
    </div>
</div>

@if ($errors->any())
    <div class="card" style="margin-bottom: 1rem; border-color: rgba(239,68,68,0.3);"><div class="card-body" style="color: #fca5a5;">{{ $errors->first() }}</div></div>
@endif

<form method="POST" action="{{ route('settings.mobile.update') }}">
    @csrf @method('PUT')

    <div class="grid-2" style="align-items: start;">
        <div class="card">
            <div class="card-header"><div class="card-title">Módulos y permisos</div></div>
            @foreach (CompanyConfiguration::FLAGS as $flag => [$label, $desc])
                <label class="switch-row {{ isset($children[$flag]) ? 'is-child' : '' }}" data-parent="{{ $children[$flag] ?? '' }}">
                    <div>
                        <div class="switch-title">{{ $label }}</div>
                        <div class="switch-desc">{{ $desc }}</div>
                    </div>
                    <span class="switch">
                        <input type="checkbox" name="{{ $flag }}" value="1" id="flag_{{ $flag }}" @checked($config->{$flag})>
                        <span class="slider"></span>
                    </span>
                </label>
            @endforeach
        </div>

        <div class="card">
            <div class="card-header"><div class="card-title">Legajo obligatorio</div></div>
            <div class="card-body">
                <p class="text-muted" style="margin-bottom: 1rem;">Documentos que cuentan para el avance del onboarding de cada colaborador.</p>
                @foreach (EmployeeDocument::TYPES as $type => $label)
                    <label style="display: flex; gap: 10px; align-items: center; padding: 6px 0; color: var(--text-primary);">
                        <input type="checkbox" name="required_documents[]" value="{{ $type }}" @checked(in_array($type, $config->requiredDocuments(), true))>
                        {{ $label }}
                    </label>
                @endforeach

                <div class="form-group" style="margin-top: 1.25rem;">
                    <label class="form-label" for="max_upload_mb">Tamaño máximo por archivo (MB)</label>
                    <input type="number" id="max_upload_mb" name="max_upload_mb" class="form-control" min="1" max="20" value="{{ $config->max_upload_mb }}" style="max-width: 120px;">
                </div>

                <button class="btn btn-primary" style="margin-top: 1rem; width: 100%;">Guardar configuración</button>
                @if ($config->updated_at)
                    <p class="text-muted" style="font-size: 0.78rem; margin-top: 0.75rem;">Último cambio: {{ $config->updated_at->format('d/m/Y H:i') }}</p>
                @endif
            </div>
        </div>
    </div>
</form>
@endsection

@section('scripts')
<script>
    // Un permiso de edición se desactiva visualmente si su módulo está apagado
    document.querySelectorAll('.switch-row[data-parent]').forEach(row => {
        const parentId = row.dataset.parent;
        if (!parentId) return;
        const parent = document.getElementById('flag_' + parentId);
        const sync = () => { row.style.opacity = parent.checked ? 1 : 0.45; row.querySelector('input').disabled = !parent.checked; };
        parent.addEventListener('change', sync);
        sync();
    });
</script>
@endsection
