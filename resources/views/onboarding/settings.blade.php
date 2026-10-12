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
</div>

<form method="POST" action="{{ route('onboarding.settings.update') }}">
    @csrf
    @method('PUT')
    <div class="card border-0">
        <ul class="list-group list-group-flush">
            @foreach($stages as $stage)
                <li class="list-group-item py-3">
                    <div class="form-check form-switch">
                        <input class="form-check-input" type="checkbox" role="switch" id="req_{{ $stage->key }}" name="required[]" value="{{ $stage->key }}" @checked(old('required') ? in_array($stage->key, old('required', [])) : $stage->is_required)>
                        <label class="form-check-label fw-semibold" for="req_{{ $stage->key }}">{{ $stage->title() }} <span class="small fw-normal text-body-secondary">(obligatoria)</span></label>
                    </div>
                    <div class="small text-body-secondary ms-5">{{ $stage->description() }}</div>

                    @if($stage->key === 'documents')
                        <div class="ms-5 mt-2 small">
                            <span class="text-body-secondary">Documentos que pide:</span> {{ implode(', ', $documents) ?: 'ninguno' }}.
                            @can('company.configure')
                                <a href="{{ route('settings.mobile') }}">Cambiarlos en Configuración de la app</a>
                            @endcan
                        </div>
                    @endif

                    @if($stage->key === 'induction')
                        <div class="ms-5 mt-2" style="max-width: 28rem">
                            <label for="course_id" class="form-label small">Curso de inducción</label>
                            <select id="course_id" name="course_id" class="form-select @error('course_id') is-invalid @enderror">
                                <option value="">Automático: primer curso publicado de categoría Inducción</option>
                                @foreach($courses as $c)
                                    <option value="{{ $c->id }}" @selected(old('course_id', $stage->course_id) == $c->id)>{{ $c->title }}</option>
                                @endforeach
                            </select>
                            @error('course_id')<div class="invalid-feedback">{{ $message }}</div>@enderror
                            @if($courses->isEmpty())
                                <div class="form-text">No hay cursos publicados: la etapa no aplica hasta que publiques uno.</div>
                            @endif
                        </div>
                    @endif
                </li>
            @endforeach
        </ul>
        <div class="card-footer bg-transparent text-end">
            <button type="submit" class="btn btn-primary"><i class="bi bi-check2 me-1"></i>Guardar etapas</button>
        </div>
    </div>
</form>
@endsection
