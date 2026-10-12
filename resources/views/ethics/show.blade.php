@extends('layouts.app')

@section('title', 'Denuncia ' . $case->public_code)

@section('content')
<nav aria-label="breadcrumb">
    <ol class="breadcrumb small mb-2">
        <li class="breadcrumb-item"><a href="{{ route('ethics.index') }}">Canal ético</a></li>
        <li class="breadcrumb-item active" aria-current="page">{{ $case->public_code }}</li>
    </ol>
</nav>
<div class="page-header">
    <div>
        <h1>{{ $case->subject }}</h1>
        <p><span class="font-monospace">{{ $case->public_code }}</span> · {{ $case->categoryLabel() }} · recibida el {{ $case->reported_on->format('d/m/Y') }}</p>
    </div>
    <x-status-badge type="ethics_status" :value="$case->status" class="fs-6 align-self-center" />
</div>

@if(! $case->identified)
    <div class="alert alert-secondary small"><i class="bi bi-incognito me-1"></i>La denuncia es anónima: el sistema no guarda quién la envió ni desde dónde. Cada vez que alguien abre esta página queda en la bitácora.</div>
@else
    <div class="alert alert-info small d-flex flex-wrap align-items-center justify-content-between gap-2" id="identity">
        <div><i class="bi bi-person-lock me-1"></i>Quien denunció eligió identificarse. Su identidad es reservada: solo Recursos Humanos puede verla, para fines legales, y cada consulta queda en la bitácora.</div>
        @can('ethics.view_identity')
            @unless(session('ethics_identity'))
                <form method="POST" action="{{ route('ethics.identity', $case->id) }}">
                    @csrf
                    <button type="submit" class="btn btn-sm btn-outline-primary"><i class="bi bi-eye me-1"></i>Ver identidad</button>
                </form>
            @endunless
        @endcan
    </div>
    @if($identity = session('ethics_identity'))
        <div class="card border-0 mb-3">
            <div class="card-body small">
                <h2 class="h6"><i class="bi bi-person-vcard me-1"></i>Identidad de quien denunció</h2>
                <dl class="row mb-0">
                    @foreach(['name' => 'Nombre', 'employee_code' => 'Código', 'document' => 'Documento', 'position' => 'Cargo', 'email' => 'Correo', 'phone' => 'Teléfono'] as $k => $label)
                        @if(! empty($identity[$k]))
                            <dt class="col-4 fw-normal text-body-secondary">{{ $label }}</dt><dd class="col-8">{{ $identity[$k] }}</dd>
                        @endif
                    @endforeach
                </dl>
                <div class="text-body-secondary mt-2">No compartas estos datos con jefaturas ni con las personas denunciadas.</div>
            </div>
        </div>
    @endif
@endif

@if($case->isClosed() && $case->resolution)
    <div class="alert alert-success"><i class="bi bi-check-circle me-1"></i><strong>Conclusión enviada al denunciante:</strong> {{ $case->resolution }}</div>
@endif

<div class="row g-4">
    <div class="col-12 col-lg-8">
        <div class="card border-0 mb-4">
            <div class="card-header bg-transparent fw-semibold">Denuncia</div>
            <div class="card-body">
                <p class="mb-0" style="white-space: pre-line">{{ $case->body }}</p>
                @include('ethics._photos', ['photos' => $case->attachments->whereNull('message_id')])
            </div>
        </div>

        <div class="card border-0">
            <div class="card-header bg-transparent fw-semibold">Conversación</div>
            <ul class="list-group list-group-flush">
                @forelse($case->messages as $m)
                    <li class="list-group-item {{ $m->fromReporter() ? '' : 'bg-body-tertiary' }}">
                        <div class="small text-body-secondary mb-1">
                            @if($m->fromReporter())
                                <i class="bi bi-{{ $case->identified ? 'person' : 'incognito' }} me-1"></i>Denunciante
                            @else
                                <i class="bi bi-person-badge me-1"></i>{{ $m->investigator ? $m->investigator->first_name . ' ' . $m->investigator->last_name : 'Investigador' }}
                            @endif
                            · {{ $m->sent_on->format('d/m/Y') }}
                        </div>
                        <div style="white-space: pre-line">{{ $m->body }}</div>
                        @include('ethics._photos', ['photos' => $m->attachments])
                    </li>
                @empty
                    <li class="list-group-item"><div class="empty-state"><i class="bi bi-chat"></i>Aún no hay mensajes.</div></li>
                @endforelse
            </ul>
            @unless($case->isClosed())
                <div class="card-body border-top">
                    <form method="POST" action="{{ route('ethics.reply', $case->id) }}">
                        @csrf
                        <label for="body" class="form-label small">Responder al denunciante</label>
                        <textarea id="body" name="body" rows="3" maxlength="5000" class="form-control @error('body') is-invalid @enderror" placeholder="Lo verá en la app al consultar su denuncia. No incluyas datos de otras personas que no deba conocer.">{{ old('body') }}</textarea>
                        @error('body')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        <div class="d-flex flex-wrap gap-3 align-items-center justify-content-between mt-2">
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" value="1" id="ask_for_info" name="ask_for_info" @checked(old('ask_for_info'))>
                                <label class="form-check-label small" for="ask_for_info">Pedirle más información</label>
                            </div>
                            <button type="submit" class="btn btn-primary"><i class="bi bi-send me-1"></i>Enviar</button>
                        </div>
                    </form>
                </div>
            @endunless
        </div>
    </div>

    <div class="col-12 col-lg-4">
        <div class="card border-0 mb-3">
            <div class="card-body">
                <h2 class="h6">Estado</h2>
                <form method="POST" action="{{ route('ethics.status', $case->id) }}">
                    @csrf
                    @method('PUT')
                    <select name="status" class="form-select mb-2" aria-label="Estado">
                        @foreach(\App\Modules\Ethics\Models\EthicsCase::STATUSES as $key => $text)
                            <option value="{{ $key }}" @selected(old('status', $case->status) === $key)>{{ $text }}</option>
                        @endforeach
                    </select>
                    <label for="resolution" class="form-label small">Conclusión (obligatoria al cerrar)</label>
                    <textarea id="resolution" name="resolution" rows="3" maxlength="5000" class="form-control @error('resolution') is-invalid @enderror" placeholder="Qué se investigó y qué medidas se tomaron.">{{ old('resolution', $case->resolution) }}</textarea>
                    @error('resolution')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    <button type="submit" class="btn btn-outline-primary w-100 mt-2">Guardar estado</button>
                </form>
            </div>
        </div>
        <div class="card border-0">
            <div class="card-body small">
                <div class="text-body-secondary">A cargo</div>
                <div class="fw-semibold">{{ $case->assignee ? $case->assignee->first_name . ' ' . $case->assignee->last_name : 'Sin asignar (se asigna a quien responda primero)' }}</div>
                @if($case->closed_at)
                    <div class="text-body-secondary mt-2">Cerrada</div>
                    <div class="fw-semibold">{{ $case->closed_at->format('d/m/Y H:i') }}</div>
                @endif
            </div>
        </div>
    </div>
</div>
@endsection
