@extends('layouts.app')

@section('title', 'Marcación fuera de turno')

@section('content')
<div class="d-flex flex-wrap justify-content-between align-items-start gap-3 mb-4">
    <div>
        <h1 class="h3 fw-bold mb-1 text-body">Marcación fuera de turno</h1>
        <p class="text-body-secondary mb-0" style="max-width: 760px;">
            La app solo deja marcar entrada los días con turno programado. Aquí autorizas a una persona a marcar un día de
            descanso o sin programación (por ejemplo, un refuerzo). Se le avisa en la app y queda en la bitácora.
        </p>
    </div>
    <a href="{{ route('attendance.calendar') }}" class="btn btn-outline-secondary">
        <i class="bi bi-calendar3 me-1"></i>Ver calendario
    </a>
</div>

<div class="row g-4">
    <div class="col-12 col-xl-4">
        <div class="card border-0 shadow-sm">
            <div class="card-body p-4">
                <h2 class="h6 fw-bold mb-3"><i class="bi bi-calendar-plus text-primary me-1"></i>Nueva autorización</h2>
                @unless($hasTenant)
                    <div class="alert alert-info small mb-0">Elige una empresa en el selector superior para autorizar marcaciones.</div>
                @else
                    <form method="POST" action="{{ route('attendance.offshift.store') }}" class="d-grid gap-3">
                        @csrf
                        <div>
                            <label for="user_id" class="form-label fw-semibold">Colaborador</label>
                            <div class="pretty-select">
                                <i class="bi bi-person pretty-select-icon" aria-hidden="true"></i>
                                <select id="user_id" name="user_id" class="form-select @error('user_id') is-invalid @enderror" required>
                                    <option value="">Elige a la persona…</option>
                                    @foreach($employees as $e)
                                        <option value="{{ $e->id }}" @selected((int) old('user_id', $selectedUser) === $e->id)>
                                            {{ $e->last_name }}, {{ $e->first_name }}{{ $e->employee_code ? ' · ' . $e->employee_code : '' }}
                                        </option>
                                    @endforeach
                                </select>
                            </div>
                            @error('user_id')<div class="invalid-feedback d-block">{{ $message }}</div>@enderror
                        </div>
                        <div>
                            <label for="work_date" class="form-label fw-semibold">Día</label>
                            <input type="date" id="work_date" name="work_date" min="{{ $today }}" value="{{ old('work_date', $today) }}"
                                   class="form-control @error('work_date') is-invalid @enderror" required>
                            @error('work_date')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        </div>
                        <div>
                            <label for="reason" class="form-label fw-semibold">Motivo</label>
                            <textarea id="reason" name="reason" rows="3" maxlength="300" required
                                      class="form-control @error('reason') is-invalid @enderror"
                                      placeholder="Ej.: Refuerzo por evento en la sede Trujillo">{{ old('reason') }}</textarea>
                            @error('reason')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        </div>
                        <button type="submit" class="btn btn-primary text-white fw-semibold">
                            <i class="bi bi-check2-circle me-1"></i>Autorizar marcación
                        </button>
                    </form>
                @endunless
            </div>
        </div>
    </div>

    <div class="col-12 col-xl-8">
        <div class="card border-0 shadow-sm">
            <div class="card-header bg-transparent border-0 pt-3 px-4 d-flex flex-wrap justify-content-between align-items-center gap-2">
                <h2 class="h6 fw-bold mb-0">Autorizaciones</h2>
                <div class="btn-group btn-group-sm" role="group" aria-label="Periodo">
                    <a href="{{ route('attendance.offshift.index') }}" class="btn {{ $period === 'upcoming' ? 'btn-primary text-white' : 'btn-outline-secondary' }}">Hoy y próximas</a>
                    <a href="{{ route('attendance.offshift.index', ['period' => 'past']) }}" class="btn {{ $period === 'past' ? 'btn-primary text-white' : 'btn-outline-secondary' }}">Anteriores</a>
                </div>
            </div>
            <div class="table-responsive">
                <table class="table align-middle mb-0">
                    <thead>
                        <tr>
                            <th scope="col" class="ps-4">Colaborador</th>
                            <th scope="col">Día</th>
                            <th scope="col">Motivo</th>
                            <th scope="col">Estado</th>
                            <th scope="col" class="text-end pe-4"><span class="visually-hidden">Acciones</span></th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($authorizations as $a)
                            @php $wasUsed = isset($used[$a->user_id . '|' . $a->work_date->toDateString()]); @endphp
                            <tr>
                                <td class="ps-4">
                                    <div class="fw-semibold">{{ $a->user?->full_name ?? '—' }}</div>
                                    <div class="small text-body-secondary">{{ $a->user?->position ?: ($a->user?->employee_code ?? '') }}</div>
                                </td>
                                <td class="text-nowrap">{{ $a->work_date->format('d/m/Y') }}</td>
                                <td class="small">
                                    {{ $a->reason }}
                                    <div class="text-body-secondary">Por {{ $a->authorizer?->full_name ?? '—' }}</div>
                                </td>
                                <td>
                                    @if($wasUsed)
                                        <span class="badge rounded-pill bg-success-subtle text-success-emphasis border border-success-subtle">Marcó</span>
                                    @elseif($a->work_date->toDateString() < $today)
                                        <span class="badge rounded-pill bg-secondary-subtle text-secondary-emphasis border">No usada</span>
                                    @else
                                        <span class="badge rounded-pill bg-primary-subtle text-primary-emphasis border border-primary-subtle">Vigente</span>
                                    @endif
                                </td>
                                <td class="text-end pe-4">
                                    @if(! $wasUsed && $a->work_date->toDateString() >= $today)
                                        <form method="POST" action="{{ route('attendance.offshift.destroy', $a->id) }}" class="d-inline"
                                              data-confirm="¿Retirar la autorización de {{ $a->user?->full_name }}? Ya no podrá marcar ese día." data-confirm-danger="1">
                                            @csrf @method('DELETE')
                                            <button type="submit" class="btn btn-sm btn-outline-danger" aria-label="Retirar autorización"><i class="bi bi-x-lg"></i></button>
                                        </form>
                                    @endif
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="5" class="text-center text-body-secondary py-5">
                                <i class="bi bi-calendar-check d-block fs-3 mb-2"></i>
                                {{ $period === 'past' ? 'No hay autorizaciones anteriores.' : 'No hay autorizaciones vigentes.' }}
                            </td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            @if($authorizations->hasPages())
                <div class="card-footer bg-transparent border-0 px-4">{{ $authorizations->links() }}</div>
            @endif
        </div>
    </div>
</div>
@endsection
