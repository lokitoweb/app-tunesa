@extends('layouts.app')

@section('title', 'Incorporación de ' . $employee->first_name)

@section('content')
<nav aria-label="breadcrumb">
    <ol class="breadcrumb small mb-2">
        <li class="breadcrumb-item"><a href="{{ route('onboarding.index') }}">Incorporación</a></li>
        <li class="breadcrumb-item active" aria-current="page">{{ $employee->full_name }}</li>
    </ol>
</nav>
<div class="page-header">
    <div>
        <h1>{{ $employee->full_name }}</h1>
        <p>{{ label('id_document_type', $employee->document_type) }} {{ $employee->document_number }} · {{ $employee->email }}</p>
    </div>
    <div class="d-flex gap-2 flex-wrap">
        <x-status-badge type="onboarding_status" :value="$process->status" class="fs-6 align-self-center" />
        @can('profiles.manage')
            <a href="{{ route('employees.file', $employee->id) }}" class="btn btn-outline-primary"><i class="bi bi-folder2-open me-1"></i>Ver legajo</a>
        @endcan
    </div>
</div>

@if($process->status === 'rejected' && $process->rejection_reason)
    <div class="alert alert-danger"><i class="bi bi-exclamation-octagon me-1"></i><strong>Observación enviada:</strong> {{ $process->rejection_reason }}</div>
@endif
@if($process->isApproved())
    <div class="alert alert-success"><i class="bi bi-check-circle me-1"></i>Aprobada {{ $process->approved_at ? 'el ' . $process->approved_at->format('d/m/Y h:i a') : '' }}{{ $process->reviewer ? ' por ' . $process->reviewer->first_name . ' ' . $process->reviewer->last_name : '' }}. Ya puede marcar.</div>
@endif

<div class="row g-4">
    <div class="col-12 col-lg-8">
        <div class="card border-0">
            <div class="card-header bg-transparent fw-semibold">Etapas</div>
            <ul class="list-group list-group-flush">
                @foreach($stages as $s)
                    <li class="list-group-item d-flex gap-3 align-items-start">
                        <i class="bi fs-4 {{ $s['status'] === 'completed' ? 'bi-check-circle-fill text-success' : ($s['status'] === 'pending' ? 'bi-hourglass-split text-warning' : 'bi-dash-circle text-body-secondary') }}" aria-hidden="true"></i>
                        <div class="flex-grow-1 min-w-0">
                            <div class="d-flex flex-wrap gap-2 align-items-center">
                                <span class="fw-semibold">{{ $s['title'] }}</span>
                                <x-status-badge type="onboarding_stage_status" :value="$s['status']" />
                                @unless($s['required'])<span class="badge rounded-pill text-bg-light border">Opcional</span>@endunless
                            </div>
                            <div class="small text-body-secondary">{{ $s['description'] }}</div>
                            @if($s['missing'])
                                <div class="small mt-1"><span class="text-warning-emphasis fw-semibold">Falta:</span> {{ implode(', ', $s['missing']) }}</div>
                            @endif
                        </div>
                    </li>
                @endforeach
            </ul>
        </div>
    </div>

    <div class="col-12 col-lg-4">
        @if($canAct && ! $process->isApproved())
            <div class="card border-0 mb-3">
                <div class="card-body">
                    <h2 class="h6">Aprobar</h2>
                    <p class="small text-body-secondary">Revisa que los documentos del legajo sean correctos. Al aprobar, el colaborador ya puede marcar.</p>
                    <form method="POST" action="{{ route('onboarding.approve', $employee->id) }}"
                          data-confirm="{{ $employee->first_name }} podrá marcar desde ahora." data-confirm-title="¿Aprobar la incorporación?" data-confirm-button="Aprobar">
                        @csrf
                        <button type="submit" class="btn btn-success w-100"><i class="bi bi-person-check me-1"></i>Aprobar incorporación</button>
                    </form>
                    @if($process->status !== 'submitted')
                        <div class="form-text">Todavía no la envió. Puedes aprobarla igual si ya validaste todo.</div>
                    @endif
                </div>
            </div>
            <div class="card border-0">
                <div class="card-body">
                    <h2 class="h6">Observar</h2>
                    <form method="POST" action="{{ route('onboarding.reject', $employee->id) }}">
                        @csrf
                        <label for="reason" class="form-label small">Qué debe corregir</label>
                        <textarea id="reason" name="reason" rows="3" maxlength="500" class="form-control @error('reason') is-invalid @enderror" placeholder="Ej.: la foto del DNI está borrosa, vuelve a subirla.">{{ old('reason') }}</textarea>
                        @error('reason')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        <button type="submit" class="btn btn-outline-danger w-100 mt-2"><i class="bi bi-person-exclamation me-1"></i>Enviar observación</button>
                    </form>
                </div>
            </div>
        @endif
    </div>
</div>
@endsection
