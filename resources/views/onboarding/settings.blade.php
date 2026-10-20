@extends('layouts.app')

@section('title', 'Etapas de incorporación')

@section('content')
<nav aria-label="breadcrumb">
    <ol class="breadcrumb small mb-2">
        @can('onboarding.review')<li class="breadcrumb-item"><a href="{{ route('onboarding.index') }}">Incorporación</a></li>@endcan
        <li class="breadcrumb-item active" aria-current="page">Etapas</li>
    </ol>
</nav>
<div class="page-header">
    <div>
        <h1>Etapas de incorporación</h1>
        <p>Elige qué debe completar un ingreso nuevo antes de que RRHH apruebe y pueda marcar.</p>
    </div>
@if(!empty($tenants) && count($tenants) > 0)
    <div class="card border-0 mb-3 p-3" style="background: var(--bg-surface); border: 1px solid var(--border-color) !important; border-radius: 10px;">
        <label for="pageTenantSelect" class="form-label small fw-bold text-secondary mb-1 d-flex align-items-center gap-1.5">
            <i class="bi bi-buildings text-primary"></i>
            <span>Empresa a configurar:</span>
        </label>
        <select id="pageTenantSelect" class="form-select form-select-sm" style="max-width: 380px;" onchange="window.location.href = '{{ route('onboarding.settings') }}?tenant_id=' + this.value">
            @foreach($tenants as $t)
                <option value="{{ $t->id }}" @selected($tenantId === $t->id)>{{ $t->company_name }}</option>
            @endforeach
        </select>
    </div>
@endif

<form method="POST" action="{{ route('onboarding.settings.update') }}">
    @csrf
    @method('PUT')
    <input type="hidden" name="tenant_id" value="{{ $tenantId }}">
    
    <div class="d-flex align-items-center justify-content-between mb-3 px-1">
        <span class="small fw-bold text-uppercase tracking-wider text-secondary" style="font-size: 0.775rem; letter-spacing: 0.05em;">
            <i class="bi bi-check2-square text-primary me-2"></i>Requisitos configurables
        </span>
        <span class="small text-secondary" style="font-size: 0.75rem;">
            Activa o desactiva con el interruptor
        </span>
    </div>

    <div class="onb-stages-config-list mb-4">
        @php
            $stageMeta = [
                'personal_data' => ['icon' => 'bi-person-vcard', 'class' => 'badge-icon-personal'],
                'uniforms'      => ['icon' => 'bi-person-standing', 'class' => 'badge-icon-uniforms'],
                'documents'     => ['icon' => 'bi-file-earmark-lock2', 'class' => 'badge-icon-documents'],
                'induction'     => ['icon' => 'bi-mortarboard', 'class' => 'badge-icon-induction'],
            ];
        @endphp

        @foreach($stages as $stage)
            @php
                $meta = $stageMeta[$stage->key] ?? ['icon' => 'bi-check2-circle', 'class' => 'badge-icon-personal'];
            @endphp
            <div class="onb-settings-card">
                <div class="onb-settings-card-header">
                    <div class="d-flex align-items-center gap-3 min-w-0">
                        <div class="onb-stage-badge-icon {{ $meta['class'] }}">
                            <i class="bi {{ $meta['icon'] }}"></i>
                        </div>
                        <div class="min-w-0">
                            <div class="fw-bold text-body" style="font-size: 0.95rem;">
                                {{ $stage->title() }}
                            </div>
                            <span class="badge rounded-pill bg-light text-secondary border mt-1" style="font-size: 0.7rem;">
                                Etapa de incorporación
                            </span>
                        </div>
                    </div>

                    <div class="onb-switch-lg form-check form-switch">
                        <input class="form-check-input" 
                               type="checkbox" 
                               role="switch" 
                               id="req_{{ $stage->key }}" 
                               name="required[]" 
                               value="{{ $stage->key }}" 
                               @checked(old('required') ? in_array($stage->key, old('required', [])) : $stage->is_required)>
                    </div>
                </div>

                <p class="onb-settings-desc">
                    {{ $stage->description() }}
                </p>

                @if($stage->key === 'documents')
                    <div class="onb-settings-extra">
                        <div class="onb-docs-panel">
                            <div class="d-flex align-items-center gap-2 mb-2 text-body fw-semibold">
                                <i class="bi bi-file-earmark-check text-primary fs-6"></i>
                                <span>Documentos exigidos al colaborador:</span>
                            </div>
                            <div class="text-secondary ps-4" style="font-size: 0.825rem; line-height: 1.45;">
                                {{ implode(', ', $documents) ?: 'Ningún documento configurado actualmente.' }}
                            </div>
                            @can('company.configure')
                                <div class="mt-3 ps-4">
                                    <a href="{{ route('settings.mobile') }}" class="btn btn-sm btn-outline-primary d-inline-flex align-items-center gap-2 py-1.5 px-3" style="border-radius: 6px; font-size: 0.775rem; font-weight: 600;">
                                        <i class="bi bi-gear-fill"></i>
                                        <span>Modificar en Configuración de la app</span>
                                    </a>
                                </div>
                            @endcan
                        </div>
                    </div>
                @endif

                @if($stage->key === 'induction')
                    <div class="onb-settings-extra">
                        <div class="onb-induction-panel">
                            <label for="course_id" class="form-label small fw-bold text-secondary mb-2 d-flex align-items-center gap-2">
                                <i class="bi bi-collection-play text-primary fs-6"></i>
                                <span>Curso de inducción asignado:</span>
                            </label>
                            <select id="course_id" name="course_id" class="form-select @error('course_id') is-invalid @enderror" style="max-width: 480px; height: 38px; font-size: 0.8125rem; border-radius: 8px;">
                                <option value="">Automático: primer curso publicado de categoría Inducción</option>
                                @foreach($courses as $c)
                                    <option value="{{ $c->id }}" @selected(old('course_id', $stage->course_id) == $c->id)>{{ $c->title }}</option>
                                @endforeach
                            </select>
                            @error('course_id')<div class="invalid-feedback">{{ $message }}</div>@enderror
                            @if($courses->isEmpty())
                                <div class="d-flex align-items-center gap-2 text-warning-emphasis small mt-2.5 p-2 rounded-2 bg-warning-subtle" style="font-size: 0.775rem; max-width: 480px;">
                                    <i class="bi bi-exclamation-triangle-fill flex-shrink-0" style="margin-right: 8px;"></i>
                                    <span>No hay cursos publicados para esta empresa: la etapa no aplicará hasta que publiques uno.</span>
                                </div>
                            @endif
                        </div>
                    </div>
                @endif
            </div>
        @endforeach
    </div>

    <div class="d-flex align-items-center justify-content-between p-3 rounded-3 border" style="background: var(--bg-surface); border-color: var(--border-color) !important;">
        <a href="{{ route('onboarding.index') }}" class="btn btn-outline-secondary px-4 py-2" style="border-radius: 8px; font-weight: 600; font-size: 0.85rem;">
            Volver a Incorporación
        </a>
        <button type="submit" class="btn btn-primary px-4 py-2 d-inline-flex align-items-center gap-2 shadow-sm" style="border-radius: 8px; font-weight: 600; font-size: 0.85rem; white-space: nowrap;">
            <i class="bi bi-check2-circle fs-6"></i>
            <span>Guardar etapas</span>
        </button>
    </div>
</form>
@endsection
