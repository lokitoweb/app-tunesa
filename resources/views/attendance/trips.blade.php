@extends('layouts.app')

@section('title', 'Bitácora de viajes')

@section('content')
@php
    $name = fn ($u) => $u ? "{$u->last_name}, {$u->first_name}" : '—';
    $dmy  = fn ($d) => $d ? \Carbon\Carbon::parse($d)->format('d/m/Y') : '—';
    $hm   = fn ($t) => $t ? substr((string) $t, 0, 5) : '';
    $at   = fn ($d, $t) => $d && $t ? \Carbon\Carbon::parse($d)->format('d/m') . ' ' . substr((string) $t, 0, 5) : null;
    $dur  = fn (?int $m) => $m === null ? '—' : minutos_hms($m);
    $personOptions = function (string $field, string $empty) use ($people, $name) {
        $html = '<option value="">' . e($empty) . '</option>';
        foreach ($people as $p) {
            $html .= '<option value="' . $p->id . '"' . ((int) old($field) === $p->id ? ' selected' : '') . '>' . e($name($p) . ($p->position ? ' · ' . $p->position : '')) . '</option>';
        }

        return $html;
    };
    $totals = ['outbound' => $summary->sum('outbound'), 'return' => $summary->sum('return'), 'days' => $summary->sum('days')];
@endphp
<div class="d-flex flex-wrap justify-content-between align-items-start gap-3 mb-4">
    <div>
        <h1 class="h3 fw-bold mb-1 text-body">Bitácora de viajes</h1>
        <p class="text-body-secondary mb-0" style="max-width: 860px;">
            Los conductores y auxiliares (cargos con «Control por viajes») no marcan en la app ni tienen faltas ni tardanzas.
            Registra cada viaje con su ida y su regreso: las horas de conducción las heredan los tres del viaje y cada día con viaje es un día trabajado.
        </p>
    </div>
</div>

<div class="row g-4">
    <div class="col-12 col-xl-4">
        <div class="card border-0 shadow-sm">
            <div class="card-body p-4">
                <div class="d-flex justify-content-between align-items-center mb-3">
                    <h2 class="h6 fw-bold mb-0"><i class="bi bi-signpost-split text-primary me-1"></i>Nuevo viaje</h2>
                    @if($hasTenant)<span class="badge bg-primary-subtle text-primary-emphasis" title="Lo asigna el sistema al registrar">N.º de salida {{ $nextNumber }}</span>@endif
                </div>
                @unless($hasTenant)
                    <div class="alert alert-info small mb-0">Elige una empresa en el selector superior para registrar viajes.</div>
                @else
                    @if($people->isEmpty())
                        <div class="alert alert-warning small">No hay conductores a tu cargo. En Organización › Cargos activa «Control por viajes» en el cargo de Chofer.</div>
                    @endif
                    <form method="POST" action="{{ route('attendance.trips.store') }}" class="d-grid gap-3">
                        @csrf
                        <div>
                            <label for="route" class="form-label small fw-semibold">Ruta</label>
                            <input type="text" id="route" name="route" maxlength="150" value="{{ old('route') }}" placeholder="Ej.: Trujillo – Huamachuco" class="form-control @error('route') is-invalid @enderror" required>
                            @error('route')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        </div>
                        <div>
                            <label for="driver_id" class="form-label small fw-semibold">Conductor 1</label>
                            <select id="driver_id" name="driver_id" class="form-select @error('driver_id') is-invalid @enderror" required>{!! $personOptions('driver_id', 'Elige al conductor…') !!}</select>
                            @error('driver_id')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        </div>
                        <div>
                            <label for="co_driver_id" class="form-label small fw-semibold">Conductor 2 <span class="text-secondary fw-normal">(opcional)</span></label>
                            <select id="co_driver_id" name="co_driver_id" class="form-select @error('co_driver_id') is-invalid @enderror">{!! $personOptions('co_driver_id', 'Sin conductor 2') !!}</select>
                            @error('co_driver_id')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        </div>
                        <div>
                            <label for="helper_id" class="form-label small fw-semibold">Auxiliar de carga <span class="text-secondary fw-normal">(opcional)</span></label>
                            <select id="helper_id" name="helper_id" class="form-select @error('helper_id') is-invalid @enderror">{!! $personOptions('helper_id', 'Sin auxiliar') !!}</select>
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

                        <fieldset class="border rounded-3 p-3">
                            <legend class="float-none w-auto px-2 mb-0 small fw-bold text-primary text-uppercase">Ida</legend>
                            <div class="row g-2">
                                <div class="col-12 small fw-semibold text-secondary">Salida</div>
                                <div class="col-6"><input type="date" name="trip_date" value="{{ old('trip_date', $today) }}" class="form-control @error('trip_date') is-invalid @enderror" aria-label="Fecha de salida de la ida" required></div>
                                <div class="col-6"><input type="time" name="departure_time" value="{{ old('departure_time') }}" class="form-control @error('departure_time') is-invalid @enderror" aria-label="Hora de salida de la ida" required>@error('departure_time')<div class="invalid-feedback">{{ $message }}</div>@enderror</div>
                                <div class="col-12 small fw-semibold text-secondary mt-2">Llegada <span class="fw-normal">(al llegar)</span></div>
                                <div class="col-6"><input type="date" name="arrival_date" value="{{ old('arrival_date') }}" class="form-control @error('arrival_date') is-invalid @enderror" aria-label="Fecha de llegada de la ida">@error('arrival_date')<div class="invalid-feedback">{{ $message }}</div>@enderror</div>
                                <div class="col-6"><input type="time" name="arrival_time" value="{{ old('arrival_time') }}" class="form-control @error('arrival_time') is-invalid @enderror" aria-label="Hora de llegada de la ida">@error('arrival_time')<div class="invalid-feedback">{{ $message }}</div>@enderror</div>
                            </div>
                        </fieldset>

                        <details class="border rounded-3 p-3" @if(old('return_departure_time') || old('return_time')) open @endif>
                            <summary class="small fw-bold text-warning-emphasis text-uppercase">Regreso <span class="fw-normal text-secondary text-lowercase">(si ya salió de regreso)</span></summary>
                            <div class="row g-2 mt-1">
                                <div class="col-12 small fw-semibold text-secondary">Salida</div>
                                <div class="col-6"><input type="date" name="return_departure_date" value="{{ old('return_departure_date') }}" class="form-control @error('return_departure_date') is-invalid @enderror" aria-label="Fecha de salida del regreso">@error('return_departure_date')<div class="invalid-feedback">{{ $message }}</div>@enderror</div>
                                <div class="col-6"><input type="time" name="return_departure_time" value="{{ old('return_departure_time') }}" class="form-control @error('return_departure_time') is-invalid @enderror" aria-label="Hora de salida del regreso">@error('return_departure_time')<div class="invalid-feedback">{{ $message }}</div>@enderror</div>
                                <div class="col-12 small fw-semibold text-secondary mt-2">Llegada</div>
                                <div class="col-6"><input type="date" name="return_date" value="{{ old('return_date') }}" class="form-control @error('return_date') is-invalid @enderror" aria-label="Fecha de llegada del regreso">@error('return_date')<div class="invalid-feedback">{{ $message }}</div>@enderror</div>
                                <div class="col-6"><input type="time" name="return_time" value="{{ old('return_time') }}" class="form-control @error('return_time') is-invalid @enderror" aria-label="Hora de llegada del regreso">@error('return_time')<div class="invalid-feedback">{{ $message }}</div>@enderror</div>
                                <div class="col-12"><input type="text" name="return_route" maxlength="150" value="{{ old('return_route') }}" placeholder="Ruta de regreso (opcional)" class="form-control" aria-label="Ruta de regreso"></div>
                            </div>
                            <div class="form-text small">Al guardar la llegada del regreso queda firmada con tu usuario y la hora.</div>
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
                    <label for="f_driver" class="form-label small fw-semibold mb-1">Conductor o auxiliar</label>
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
                <h2 class="h6 fw-bold mb-0">Horas de conducción y días trabajados del {{ $dmy($from) }} al {{ $dmy($to) }}</h2>
                <small class="text-secondary">Meta del mes: {{ $workDays }} días de trabajo y {{ $restDays }} de descanso (Configuración › Reglas). Las horas de cada viaje las heredan sus tres integrantes.</small>
            </div>
            <div class="table-responsive">
                <table class="table align-middle mb-0">
                    <thead>
                        <tr>
                            <th scope="col" class="ps-4">Colaborador</th>
                            <th scope="col">Cargo</th>
                            <th scope="col" class="text-end">H. conducción ida</th>
                            <th scope="col" class="text-end">H. conducción regreso</th>
                            <th scope="col" class="text-end">Total</th>
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
                                <td class="text-end text-nowrap">{{ minutos_hms($s['outbound']) }}</td>
                                <td class="text-end text-nowrap">{{ minutos_hms($s['return']) }}</td>
                                <td class="text-end text-nowrap fw-semibold">{{ minutos_hms($s['outbound'] + $s['return']) }}</td>
                                <td class="text-end fw-bold">{{ $s['days'] }}</td>
                                <td class="text-end pe-4">
                                    <span class="badge {{ $diff > 0 ? 'bg-warning-subtle text-warning-emphasis' : ($diff === 0 ? 'bg-success-subtle text-success' : 'bg-light text-secondary') }}">
                                        {{ $diff > 0 ? "+{$diff} días sobre la meta" : ($diff === 0 ? 'Meta cumplida' : abs($diff) . ' días por completar') }}
                                    </span>
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="7" class="text-center text-secondary py-4">Sin conductores con «Control por viajes».</td></tr>
                        @endforelse
                    </tbody>
                    @if($summary->isNotEmpty())
                        <tfoot>
                            <tr class="table-light fw-bold">
                                <td class="ps-4" colspan="2">TOTALES</td>
                                <td class="text-end text-nowrap">{{ minutos_hms($totals['outbound']) }}</td>
                                <td class="text-end text-nowrap">{{ minutos_hms($totals['return']) }}</td>
                                <td class="text-end text-nowrap">{{ minutos_hms($totals['outbound'] + $totals['return']) }}</td>
                                <td class="text-end">{{ $totals['days'] }}</td>
                                <td class="pe-4"></td>
                            </tr>
                        </tfoot>
                    @endif
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
                            <th scope="col" class="ps-4">N.º</th>
                            <th scope="col">Salida / ruta</th>
                            <th scope="col">Tripulación</th>
                            <th scope="col">Placa</th>
                            <th scope="col">Ida</th>
                            <th scope="col">Regreso</th>
                            <th scope="col" class="pe-4 text-end"><span class="visually-hidden">Acciones</span></th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($trips as $trip)
                            <tr>
                                <td class="ps-4 fw-bold text-nowrap">{{ $trip->trip_number }}</td>
                                <td>
                                    <span class="text-nowrap">{{ $dmy($trip->trip_date) }}</span>
                                    <div>{{ $trip->route }}</div>
                                    @if($trip->branch)<div class="small text-secondary">{{ $trip->branch->name }}</div>@endif
                                </td>
                                <td class="small">
                                    <div><span class="text-secondary">C1:</span> {{ $name($trip->driver) }}</div>
                                    @if($trip->coDriver)<div><span class="text-secondary">C2:</span> {{ $name($trip->coDriver) }}</div>@endif
                                    @if($trip->helper)<div><span class="text-secondary">Aux.:</span> {{ $name($trip->helper) }}</div>@endif
                                </td>
                                <td class="text-nowrap">{{ $trip->vehicle_plate }}</td>
                                <td class="small text-nowrap">
                                    <div>Sale {{ $at($trip->trip_date, $trip->departure_time) }}</div>
                                    <div>{!! $at($trip->arrival_date, $trip->arrival_time) ? 'Llega ' . e($at($trip->arrival_date, $trip->arrival_time)) : '<span class="text-warning-emphasis">Sin llegada</span>' !!}</div>
                                    <div class="fw-semibold">{{ $dur($trip->outboundMinutes()) }}</div>
                                </td>
                                <td class="small text-nowrap">
                                    @if($trip->return_departure_time)
                                        <div>Sale {{ $at($trip->return_departure_date, $trip->return_departure_time) }}</div>
                                        <div>{!! $trip->return_time ? 'Llega ' . e($at($trip->return_date, $trip->return_time)) : '<span class="text-warning-emphasis">Sin llegada</span>' !!}</div>
                                        <div class="fw-semibold">{{ $dur($trip->returnMinutes()) }}</div>
                                        @if($trip->hasReturned())<div class="text-success"><i class="bi bi-pen me-1"></i>Firmó {{ $name($trip->signer) }}</div>@endif
                                    @else
                                        <span class="text-secondary">Pendiente</span>
                                    @endif
                                </td>
                                <td class="pe-4 text-end">
                                    <div class="d-flex justify-content-end gap-1">
                                        @unless($trip->hasReturned())
                                            <button type="button" class="btn btn-sm btn-outline-warning text-warning-emphasis fw-semibold text-nowrap" data-bs-toggle="collapse" data-bs-target="#complete-{{ $trip->id }}" aria-expanded="false" aria-controls="complete-{{ $trip->id }}">
                                                <i class="bi bi-pencil-square me-1"></i>Completar
                                            </button>
                                        @endunless
                                        <form method="POST" action="{{ route('attendance.trips.destroy', $trip->id) }}" onsubmit="return confirm('¿Eliminar el viaje N.º {{ $trip->trip_number }} de la bitácora?');">
                                            @csrf @method('DELETE')
                                            <button type="submit" class="btn btn-sm btn-outline-danger" title="Eliminar viaje" aria-label="Eliminar viaje"><i class="bi bi-trash"></i></button>
                                        </form>
                                    </div>
                                </td>
                            </tr>
                            @unless($trip->hasReturned())
                                <tr class="collapse" id="complete-{{ $trip->id }}">
                                    <td colspan="7" class="bg-body-tertiary px-4 py-3">
                                        <form method="POST" action="{{ route('attendance.trips.update', $trip->id) }}" class="row g-3 align-items-end">
                                            @csrf @method('PUT')
                                            @foreach(['trip_date' => $trip->trip_date->toDateString(), 'route' => $trip->route, 'driver_id' => $trip->driver_id, 'co_driver_id' => $trip->co_driver_id, 'helper_id' => $trip->helper_id, 'vehicle_plate' => $trip->vehicle_plate, 'departure_time' => $hm($trip->departure_time), 'branch_id' => $trip->branch_id] as $field => $value)
                                                <input type="hidden" name="{{ $field }}" value="{{ $value }}">
                                            @endforeach
                                            <div class="col-12 col-md-4">
                                                <div class="small fw-bold text-primary mb-1">Llegada de la ida</div>
                                                <div class="d-flex gap-1">
                                                    <input type="date" name="arrival_date" value="{{ $trip->arrival_date?->toDateString() ?? $trip->trip_date->toDateString() }}" class="form-control form-control-sm" aria-label="Fecha de llegada de la ida">
                                                    <input type="time" name="arrival_time" value="{{ $hm($trip->arrival_time) }}" class="form-control form-control-sm" aria-label="Hora de llegada de la ida">
                                                </div>
                                            </div>
                                            <div class="col-12 col-md-4">
                                                <div class="small fw-bold text-warning-emphasis mb-1">Salida del regreso</div>
                                                <div class="d-flex gap-1">
                                                    <input type="date" name="return_departure_date" value="{{ $trip->return_departure_date?->toDateString() }}" class="form-control form-control-sm" aria-label="Fecha de salida del regreso">
                                                    <input type="time" name="return_departure_time" value="{{ $hm($trip->return_departure_time) }}" class="form-control form-control-sm" aria-label="Hora de salida del regreso">
                                                </div>
                                            </div>
                                            <div class="col-12 col-md-4">
                                                <div class="small fw-bold text-warning-emphasis mb-1">Llegada del regreso</div>
                                                <div class="d-flex gap-1">
                                                    <input type="date" name="return_date" value="{{ $trip->return_date?->toDateString() }}" class="form-control form-control-sm" aria-label="Fecha de llegada del regreso">
                                                    <input type="time" name="return_time" value="{{ $hm($trip->return_time) }}" class="form-control form-control-sm" aria-label="Hora de llegada del regreso">
                                                </div>
                                            </div>
                                            <div class="col-12 col-md-8">
                                                <input type="text" name="return_route" maxlength="150" value="{{ $trip->return_route }}" class="form-control form-control-sm" placeholder="Ruta de regreso (opcional)" aria-label="Ruta de regreso">
                                            </div>
                                            <div class="col-12 col-md-4 d-grid">
                                                <button type="submit" class="btn btn-sm btn-success text-white"><i class="bi bi-check2 me-1"></i>Guardar viaje N.º {{ $trip->trip_number }}</button>
                                            </div>
                                            <div class="col-12 form-text small mt-1">Completa lo que ya ocurrió; puedes volver después. Con la llegada del regreso el viaje queda firmado con tu usuario.</div>
                                        </form>
                                    </td>
                                </tr>
                            @endunless
                        @empty
                            <tr><td colspan="7" class="text-center text-secondary py-4">Sin viajes en el periodo.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            @if($trips->hasPages())
                <div class="px-4 py-3">{{ $trips->links() }}</div>
            @endif
        </div>

        @if($hasTenant)
            <div class="card border-0 shadow-sm">
                <div class="card-header bg-transparent border-0 pt-3 px-4">
                    <h2 class="h6 fw-bold mb-0"><i class="bi bi-file-earmark-check text-success me-1"></i>Hojas de conducción para firmar</h2>
                    <small class="text-secondary">Sube aquí la hoja ya firmada indicando el periodo que cubre. Para imprimir, descarga en Excel la hoja de conducción de ida y la de regreso, por semana (lunes a domingo, según la fecha de salida del viaje) o de todo el periodo.</small>
                </div>
                <div class="card-body px-4 pt-2">
                    {{-- Primero se sube la hoja firmada (con el periodo que cubre); debajo se descargan las hojas para imprimir --}}
                    <form method="POST" action="{{ route('attendance.trips.sheets.store') }}" enctype="multipart/form-data" class="row g-2 align-items-end">
                        @csrf
                        <div class="col-12 small fw-bold text-success text-uppercase"><i class="bi bi-upload me-1"></i>Subir hoja firmada</div>
                        <div class="col-6 col-md-2">
                            <label for="s_from" class="form-label small fw-semibold mb-1">Desde</label>
                            <input type="date" id="s_from" name="period_from" value="{{ old('period_from', $from) }}" class="form-control form-control-sm @error('period_from') is-invalid @enderror" required>
                        </div>
                        <div class="col-6 col-md-2">
                            <label for="s_to" class="form-label small fw-semibold mb-1">Hasta</label>
                            <input type="date" id="s_to" name="period_to" value="{{ old('period_to', $to) }}" class="form-control form-control-sm @error('period_to') is-invalid @enderror" required>
                            @error('period_to')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        </div>
                        <div class="col-12 col-md-8">
                            <label for="s_file" class="form-label small fw-semibold mb-1">Archivo <span class="text-secondary fw-normal">(PDF o foto, hasta 10 MB)</span></label>
                            <input type="file" id="s_file" name="sheet" accept=".pdf,.jpg,.jpeg,.png,.webp" class="form-control form-control-sm @error('sheet') is-invalid @enderror" required>
                            @error('sheet')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        </div>
                        <div class="col-12 col-md-9">
                            <input type="text" name="notes" maxlength="300" value="{{ old('notes') }}" class="form-control form-control-sm" placeholder="Observación (opcional)" aria-label="Observación">
                        </div>
                        <div class="col-12 col-md-3 d-grid">
                            <button type="submit" class="btn btn-sm btn-success text-white"><i class="bi bi-upload me-1"></i>Subir hoja firmada</button>
                        </div>
                    </form>

                    <div class="small fw-bold text-secondary text-uppercase mt-3 pt-3 border-top mb-2"><i class="bi bi-printer me-1"></i>Descargar para imprimir</div>
                    <div class="d-flex flex-wrap gap-2">
                        @foreach($weeks as $w)
                            <a href="{{ route('attendance.trips.export', array_filter(['from' => $w['from'], 'to' => $w['to'], 'driver_id' => $driverId])) }}" class="btn btn-sm btn-outline-success">
                                <i class="bi bi-file-earmark-excel me-1"></i>{{ $w['label'] }}
                            </a>
                        @endforeach
                        <a href="{{ route('attendance.trips.export', array_filter(['from' => $from, 'to' => $to, 'driver_id' => $driverId])) }}" class="btn btn-sm btn-success text-white">
                            <i class="bi bi-file-earmark-excel me-1"></i>Todo el periodo
                        </a>
                    </div>
                </div>
                <div class="table-responsive">
                    <table class="table align-middle mb-0">
                        <thead>
                            <tr>
                                <th scope="col" class="ps-4">Hojas subidas</th>
                                <th scope="col">Subido por</th>
                                <th scope="col" class="pe-4 text-end"><span class="visually-hidden">Acciones</span></th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse($sheets as $group)
                                <tr class="table-light">
                                    <th scope="colgroup" colspan="3" class="ps-4 small">
                                        <i class="bi bi-calendar-week text-success me-1"></i>Del {{ $dmy($group->first()->period_from) }} al {{ $dmy($group->first()->period_to) }}
                                        <span class="badge bg-success-subtle text-success-emphasis ms-1">{{ $group->count() }} {{ $group->count() === 1 ? 'hoja' : 'hojas' }}</span>
                                    </th>
                                </tr>
                                @foreach($group as $sheet)
                                <tr>
                                    <td class="ps-4">
                                        <i class="bi {{ $sheet->mime_type === 'application/pdf' ? 'bi-file-earmark-pdf text-danger' : 'bi-file-earmark-image text-primary' }} me-1"></i>{{ $sheet->original_name }}
                                        @if($sheet->notes)<div class="small text-secondary">{{ $sheet->notes }}</div>@endif
                                    </td>
                                    <td class="text-nowrap">{{ $name($sheet->uploader) }}<div class="small text-secondary">{{ $sheet->created_at->timezone(tenant_timezone())->format('d/m/Y H:i') }}</div></td>
                                    <td class="pe-4 text-end text-nowrap">
                                        <a href="{{ route('attendance.trips.sheets.download', $sheet->id) }}" class="btn btn-sm btn-outline-primary" title="Descargar hoja firmada" aria-label="Descargar hoja firmada"><i class="bi bi-download"></i></a>
                                        <form method="POST" action="{{ route('attendance.trips.sheets.destroy', $sheet->id) }}" class="d-inline" onsubmit="return confirm('¿Quitar esta hoja firmada de la lista?');">
                                            @csrf @method('DELETE')
                                            <button type="submit" class="btn btn-sm btn-outline-danger" title="Quitar hoja firmada" aria-label="Quitar hoja firmada"><i class="bi bi-trash"></i></button>
                                        </form>
                                    </td>
                                </tr>
                                @endforeach
                            @empty
                                <tr><td colspan="3" class="text-center text-secondary py-4">Aún no hay hojas firmadas de este periodo.</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>
        @endif
    </div>
</div>
@endsection
