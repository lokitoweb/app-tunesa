@extends('layouts.app')

@section('title', 'Configuración de la app')

@section('content')
@php
    use App\Models\CompanyConfiguration;
    use App\Models\EmployeeDocument;
    $children = ['mobile_documents_upload' => 'mobile_documents_enabled', 'mobile_payment_info_edit' => 'mobile_payment_info_enabled', 'mobile_uniforms_edit' => 'mobile_uniforms_enabled'];
@endphp

<div class="page-header">
    <div>
        <h1>Configuración de la app móvil</h1>
        <p>Decide qué ve y qué puede editar el personal desde su celular. Los cambios aplican la próxima vez que abren la app.</p>
    </div>
</div>

@if(! $config)
    <div class="card border-0">
        <div class="card-body empty-state">
            <i class="bi bi-buildings"></i>
            <p class="mb-0">La configuración de la app es propia de cada empresa. Elígela en el selector superior para verla o cambiarla.</p>
        </div>
    </div>
@else
<form method="POST" action="{{ route('settings.mobile.update') }}">
    @csrf @method('PUT')

    <div class="row g-3">
        <div class="col-12 col-xl-7">
            <div class="card border-0">
                <div class="card-header border-0 pt-3"><h2 class="h6 fw-semibold mb-0">Módulos y permisos</h2></div>
                <ul class="list-group list-group-flush">
                    @foreach(CompanyConfiguration::FLAGS as $flag => [$label, $desc])
                        <li class="list-group-item py-3 {{ isset($children[$flag]) ? 'ps-5' : '' }}" data-parent="{{ $children[$flag] ?? '' }}">
                            <div class="form-check form-switch form-switch-lg d-flex justify-content-between align-items-center gap-3 mb-0 ps-0">
                                <label class="form-check-label" for="flag_{{ $flag }}">
                                    <span class="d-block fw-semibold">{{ $label }}</span>
                                    <span class="d-block small text-body-secondary">{{ $desc }}</span>
                                </label>
                                <input class="form-check-input flex-shrink-0 ms-0" type="checkbox" role="switch" name="{{ $flag }}" value="1" id="flag_{{ $flag }}" @checked($config->{$flag})>
                            </div>
                        </li>
                    @endforeach
                </ul>
            </div>
        </div>

        <div class="col-12 col-xl-5">
            <div class="card border-0">
                <div class="card-header border-0 pt-3">
                    <h2 class="h6 fw-semibold mb-0">Legajo obligatorio</h2>
                    <small class="text-body-secondary">Documentos que cuentan para el avance del onboarding.</small>
                </div>
                <div class="card-body">
                    @foreach(EmployeeDocument::TYPES as $type => $label)
                        <div class="form-check">
                            <input class="form-check-input" type="checkbox" name="required_documents[]" value="{{ $type }}" id="req_{{ $type }}" @checked(in_array($type, $config->requiredDocuments(), true))>
                            <label class="form-check-label" for="req_{{ $type }}">{{ $label }}</label>
                        </div>
                    @endforeach

                    <label class="form-label mt-3" for="max_upload_mb">Tamaño máximo por archivo</label>
                    <div class="input-group" style="max-width: 160px;">
                        <input type="number" id="max_upload_mb" name="max_upload_mb" class="form-control" min="1" max="20" value="{{ $config->max_upload_mb }}">
                        <span class="input-group-text">MB</span>
                    </div>

                    <button class="btn btn-primary w-100 mt-4">Guardar configuración</button>
                    @if($config->updated_at)
                        <p class="small text-body-secondary mt-2 mb-0">Último cambio: {{ $config->updated_at->format('d/m/Y H:i') }}</p>
                    @endif
                </div>
            </div>
        </div>
    </div>
</form>
@endif
@endsection

@section('scripts')
<script>
    // Un permiso de edición se desactiva si su módulo está apagado
    document.querySelectorAll('[data-parent]').forEach((row) => {
        const parentId = row.dataset.parent;
        if (!parentId) return;
        const parent = document.getElementById('flag_' + parentId);
        const input = row.querySelector('input');
        const sync = () => { row.classList.toggle('opacity-50', !parent.checked); input.disabled = !parent.checked; };
        parent.addEventListener('change', sync);
        sync();
    });
</script>
@endsection
