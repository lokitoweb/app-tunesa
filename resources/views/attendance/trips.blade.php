@extends('layouts.app')

@section('title', 'Bitácora de viajes')

@section('content')
@php
    $name = fn ($u) => $u ? "{$u->last_name}, {$u->first_name}" : '—';
    $dmy  = fn ($d) => $d ? \Carbon\Carbon::parse($d)->format('d/m/Y') : '—';
    $hm   = fn ($t) => $t ? substr((string) $t, 0, 5) : '—';
@endphp
<div class="d-flex flex-wrap justify-content-between align-items-start gap-3 mb-4">
    <div>
        <h1 class="h3 fw-bold mb-1 text-body">Bitácora de viajes</h1>
        <p class="text-body-secondary mb-0" style="max-width: 820px;">
            Los choferes y ayudantes (cargos con «Control por viajes») no marcan en la app ni tienen faltas ni tardanzas.
            Aquí registras la ida y la vuelta de cada viaje; la vuelta queda firmada con tu usuario. Cada día con viaje es un día trabajado.
        </p>
    </div>
</div>

<div class="row g-4">
    <div class="col-12 col-xl-4">
        <div class="card border-0 shadow-sm">
            <div class="card-body p-4">
                <h2 class="h6 fw-bold mb-3"><i class="bi bi-signpost-split text-primary me-1"></i>Nuevo viaje</h2>
                @unless($hasTenant)
                    <div class="alert alert-info small mb-0">Elige una empresa en el selector superior para registrar viajes.</div>
                @else
                    @if($people->isEmpty())
                        <div class="alert alert-warning small">No hay choferes a tu cargo. En Organización › Cargos activa «Control por viajes» en el cargo de Chofer.</div>
                    @endif
                    <form method="POST" action="{{ route('attendance.trips.store') }}" class="d-grid gap-3">
                        @csrf
                        <div class="fw-semibold small text-secondary text-uppercase">Ida</div>
                        <div class="row g-2">
                            <div class="col-6">
                                <label for="trip_date" class="form-label small fw-semibold">Fecha</label>
                                <input type="date" id="trip_date" name="trip_date" value="{{ old('trip_date', $today) }}" class="form-control @error('trip_date') is-invalid @enderror" required>
                            </div>
                            <div class="col-6">
                                <label for="departure_time" class="form-label small fw-semibold">Hora de salida</label>
                                <input type="time" id="departure_time" name="departure_time" value="{{ old('departure_time') }}" class="form-control @error('departure_time') is-invalid @enderror" required>
                            </div>
                        </div>
                        <div>
                            <label for="route" class="form-label small fw-semibold">Ruta</label>
                            <input type="text" id="route" name="route" maxlength="150" value="{{ old('route') }}" placeholder="Ej.: Trujillo – Huamachuco" class="form-control @error('route') is-invalid @enderror" required>
                            @error('route')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        </div>
                        <div>
                            <label for="driver_id" class="form-label small fw-semibold">Chofer</label>
                            <select id="driver_id" name="driver_id" class="form-select @error('driver_id') is-invalid @enderror" required>
                                <option value="">Elige al chofer…</option>
                                @foreach($people as $p)
                                    <option value="{{ $p->id }}" @selected((int) old('driver_id') === $p->id)>{{ $name($p) }}{{ $p->position ? ' · ' . $p->position : '' }}</option>
                                @endforeach
                            </select>
                            @error('driver_id')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        </div>
                        <div>
                            <label for="helper_id" class="form-label small fw-semibold">Ayudante <span class="text-secondary fw-normal">(opcional)</span></label>
                            <select id="helper_id" name="helper_id" class="form-select @error('helper_id') is-invalid @enderror">
                                <option value="">Sin ayudante</option>
                                @foreach($people as $p)
                                    <option value="{{ $p->id }}" @selected((int) old('helper_id') === $p->id)>{{ $name($p) }}{{ $p->position ? ' · ' . $p->position : '' }}</option>
                                @endforeach
                            </select>
                            @error('helper_id')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        </div>
                        <div class="row g-2">
                            <div class="col-6">
                                <label for="vehicle_plate" class="form-label small fw-semibold">Placa</label>
                                <input type="text" id="vehicle_plate" name="vehicle_plate" maxlength="15" value="{{ old('vehicle_plate') }}" placeholder="Ej.: T1A-234" class="form-control text-uppercase @error('vehicle_plate') is-invalid @enderror" required>
                                @error('vehicle_plate')<div class="invalid-feedback">{{ $message }}</div>@enderror
                            </div>
                            <div class="col-6">
                                <label for="branch_id" class="form-label small fw-semibold">Agencia</label>
                                <select id="branch_id" name="branch_id" class="form-select">
                                    <option value="">—</option>
                                    @foreach($branches as $b)
                                        <option value="{{ $b->id }}" @selected((int) old('branch_id') === $b->id)>{{ $b->name }}</option>
                                    @endforeach
                                </select>
                            </div>
                        </div>
                        <details @if(old('return_time')) open @endif>
                            <summary class="fw-semibold small text-secondary text-uppercase">Vuelta (si ya regresó)</summary>
                            <div class="row g-2 mt-1">
                                <div class="col-6">
                                    <label for="return_date" class="form-label small fw-semibold">Fecha</label>
                                    <input type="date" id="return_date" name="return_date" value="{{ old('return_date') }}" class="form-control @error('return_date') is-invalid @enderror">
                                    @error('return_date')<div class="invalid-feedback">{{ $message }}</div>@enderror
                                </div>
                                <div class="col-6">
                                    <label for="return_time" class="form-label small fw-semibold">Hora</label>
                                    <input type="time" id="return_time" name="return_time" value="{{ old('return_time') }}" class="form-control @error('return_time') is-invalid @enderror">
                                    @error('return_time')<div class="invalid-feedback">{{ $message }}</div>@enderror
                                </div>
                                <div class="col-12">
                                    <label for="return_route" class="form-label small fw-semibold">Ruta de vuelta</label>
                                    <input type="text" id="return_route" name="return_route" maxlength="150" value="{{ old('return_route') }}" placeholder="Ej.: Huamachuco – Trujillo" class="form-control">
                                </div>
                            </div>
                            <div class="form-text small">Al guardar la vuelta queda firmada con tu usuario y la hora.</div>
                        </details>
                        <button type="submit" class="btn btn-primary text-white fw-semibold" @disabled($people->isEmpty())>
                            <i class="bi bi-check2-circle me-1"></i>Registrar viaje
                        </button>
                    </form>
                @endunless
            </div>
        </div>
    </div>

    <div class="col-12 col-xl-8 d-grid gap-4">
        <form method="GET" class="card border-0 shadow-sm">
            <div class="card-body p-3 d-flex flex-wrap align-items-end gap-2">
                <div>
                    <label for="f_from" class="form-label small fw-semibold mb-1">Desde</label>
                    <input type="date" id="f_from" name="from" value="{{ $from }}" class="form-control form-control-sm">
                </div>
                <div>
                    <label for="f_to" class="form-label small fw-semibold mb-1">Hasta</label>
                    <input type="date" id="f_to" name="to" value="{{ $to }}" class="form-control form-control-sm">
                </div>
                <div>
                    <label for="f_driver" class="form-label small fw-semibold mb-1">Chofer o ayudante</label>
                    <select id="f_driver" name="driver_id" class="form-select form-select-sm">
                        <option value="">Todos</option>
                        @foreach($people as $p)
                            <option value="{{ $p->id }}" @selected((int) $driverId === $p->id)>{{ $name($p) }}</option>
                        @endforeach
                    </select>
                </div>
                <button type="submit" class="btn btn-sm btn-primary text-white"><i class="bi bi-funnel me-1"></i>Filtrar</button>
            </div>
        </form>

        <div class="card border-0 shadow-sm">
            <div class="card-header bg-transparent border-0 pt-3 px-4">
                <h2 class="h6 fw-bold mb-0">Días trabajados del {{ $dmy($from) }} al {{ $dmy($to) }}</h2>
                <small class="text-secondary">Meta del mes: {{ $workDays }} días de trabajo y {{ $restDays }} de descanso (Configuración › Reglas).</small>
            </div>
            <div class="table-responsive">
                <table class="table align-middle mb-0">
                    <thead>
                        <tr>
                            <th scope="col" class="ps-4">Colaborador</th>
                            <th scope="col">Cargo</th>
                            <th scope="col" class="text-end">Días trabajados</th>
                            <th scope="col" class="text-end pe-4">Frente a la meta ({{ $workDays }})</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($summary as $s)
                            @php $diff = $s['days'] - $workDays; @endphp
                            <tr>
                                <td class="ps-4 fw-semibold">{{ $name($s['user']) }}</td>
                                <td class="text-secondary">{{ $s['user']->position ?: '—' }}</td>
                                <td class="text-end fw-bold">{{ $s['days'] }}</td>
                                <td class="text-end pe-4">
                                    <span class="badge {{ $diff > 0 ? 'bg-warning-subtle text-warning-emphasis' : ($diff === 0 ? 'bg-success-subtle text-success' : 'bg-light text-secondary') }}">
                                        {{ $diff > 0 ? "+{$diff} días sobre la meta" : ($diff === 0 ? 'Meta cumplida' : abs($diff) . ' días por completar') }}
                                    </span>
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="4" class="text-center text-secondary py-4">Sin choferes con «Control por viajes».</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>

        <div class="card border-0 shadow-sm">
            <div class="card-header bg-transparent border-0 pt-3 px-4">
                <h2 class="h6 fw-bold mb-0">Viajes</h2>
            </div>
            <div class="table-responsive">
                <table class="table align-middle mb-0">
                    <thead>
                        <tr>
                            <th scope="col" class="ps-4">Ida</th>
                            <th scope="col">Ruta</th>
                            <th scope="col">Chofer / ayudante</th>
                            <th scope="col">Placa</th>
                            <th scope="col">Vuelta</th>
                            <th scope="col" class="pe-4 text-end"><span class="visually-hidden">Acciones</span></th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($trips as $trip)
                            <tr>
                                <td class="ps-4 text-nowrap">{{ $dmy($trip->trip_date) }}<div class="small text-secondary">Sale {{ $hm($trip->departure_time) }}</div></td>
                                <td>{{ $trip->route }}@if($trip->branch)<div class="small text-secondary">{{ $trip->branch->name }}</div>@endif</td>
                                <td>{{ $name($trip->driver) }}@if($trip->helper)<div class="small text-secondary">Ayudante: {{ $name($trip->helper) }}</div>@endif</td>
                                <td class="text-nowrap">{{ $trip->vehicle_plate }}</td>
                                <td class="text-nowrap">
                                    @if($trip->hasReturned())
                                        {{ $dmy($trip->return_date) }} {{ $hm($trip->return_time) }}
                                        @if($trip->return_route)<div class="small text-secondary">{{ $trip->return_route }}</div>@endif
                                        <div class="small text-success"><i class="bi bi-pen me-1"></i>Firmó {{ $name($trip->signer) }}</div>
                                    @else
                                        <details>
                                            <summary class="badge bg-warning-subtle text-warning-emphasis" role="button">Registrar vuelta</summary>
                                            <form method="POST" action="{{ route('attendance.trips.update', $trip->id) }}" class="d-grid gap-2 mt-2" style="min-width: 220px;">
                                                @csrf @method('PUT')
                                                @foreach(['trip_date' => $trip->trip_date->toDateString(), 'route' => $trip->route, 'driver_id' => $trip->driver_id, 'helper_id' => $trip->helper_id, 'vehicle_plate' => $trip->vehicle_plate, 'departure_time' => $hm($trip->departure_time), 'branch_id' => $trip->branch_id] as $field => $value)
                                                    <input type="hidden" name="{{ $field }}" value="{{ $value }}">
                                                @endforeach
                                                <input type="date" name="return_date" value="{{ $today }}" class="form-control form-control-sm" aria-label="Fecha de vuelta" required>
                                                <input type="time" name="return_time" class="form-control form-control-sm" aria-label="Hora de vuelta" required>
                                                <input type="text" name="return_route" maxlength="150" class="form-control form-control-sm" placeholder="Ruta de vuelta" aria-label="Ruta de vuelta">
                                                <button type="submit" class="btn btn-sm btn-success text-white"><i class="bi bi-pen me-1"></i>Firmar vuelta</button>
                                            </form>
                                        </details>
                                    @endif
                                </td>
                                <td class="pe-4 text-end">
                                    <form method="POST" action="{{ route('attendance.trips.destroy', $trip->id) }}" onsubmit="return confirm('¿Eliminar este viaje de la bitácora?');">
                                        @csrf @method('DELETE')
                                        <button type="submit" class="btn btn-sm btn-outline-danger" title="Eliminar viaje" aria-label="Eliminar viaje"><i class="bi bi-trash"></i></button>
                                    </form>
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="6" class="text-center text-secondary py-4">Sin viajes en el periodo.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            @if($trips->hasPages())
                <div class="px-4 py-3">{{ $trips->links() }}</div>
            @endif
        </div>
    </div>
</div>
@endsection
