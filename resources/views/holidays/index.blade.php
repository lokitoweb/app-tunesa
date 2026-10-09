@extends('layouts.app')

@section('title', 'Feriados')

@section('content')
<div class="page-header">
    <div>
        <h1>Feriados</h1>
        <p>En estas fechas el control de faltas no registra ausencias. Agrega los feriados nacionales y los propios de la empresa.</p>
    </div>
    <form method="POST" action="{{ route('holidays.load-peru') }}">
        @csrf
        <input type="hidden" name="year" value="{{ $year }}">
        <button type="submit" class="btn btn-outline-primary"><i class="bi bi-flag me-1"></i>Cargar feriados nacionales de Perú {{ $year }}</button>
    </form>
</div>

<div class="row g-3">
    <div class="col-12 col-xl-4">
        <div class="card border-0">
            <div class="card-header fw-semibold">Nuevo feriado</div>
            <div class="card-body">
                <form method="POST" action="{{ route('holidays.store') }}">
                    @csrf
                    <div class="mb-3">
                        <label for="date" class="form-label">Fecha <span class="text-danger">*</span></label>
                        <input type="date" id="date" name="date" value="{{ old('date') }}" required class="form-control @error('date') is-invalid @enderror">
                        @error('date')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>
                    <div class="mb-3">
                        <label for="name" class="form-label">Nombre <span class="text-danger">*</span></label>
                        <input type="text" id="name" name="name" value="{{ old('name') }}" required maxlength="150" class="form-control @error('name') is-invalid @enderror" placeholder="Ej.: Aniversario de la empresa">
                    </div>
                    <button type="submit" class="btn btn-primary w-100"><i class="bi bi-plus-lg me-1"></i>Agregar feriado</button>
                </form>
            </div>
        </div>
    </div>

    <div class="col-12 col-xl-8">
        <div class="card border-0">
            <div class="card-header d-flex justify-content-between align-items-center">
                <span class="fw-semibold">Feriados {{ $year }}</span>
                <div class="btn-group btn-group-sm" role="group" aria-label="Año">
                    <a href="{{ route('holidays.index', ['year' => $year - 1]) }}" class="btn btn-outline-secondary" aria-label="Año anterior"><i class="bi bi-chevron-left"></i></a>
                    <span class="btn btn-outline-secondary disabled">{{ $year }}</span>
                    <a href="{{ route('holidays.index', ['year' => $year + 1]) }}" class="btn btn-outline-secondary" aria-label="Año siguiente"><i class="bi bi-chevron-right"></i></a>
                </div>
            </div>
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead>
                        <tr>
                            <th>Fecha</th>
                            <th>Día</th>
                            <th>Feriado</th>
                            <th class="text-end">Acciones</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($holidays as $h)
                            <tr>
                                <td class="text-nowrap">{{ $h->date->format('d/m/Y') }}</td>
                                <td class="text-capitalize small text-body-secondary">{{ $h->date->isoFormat('dddd') }}</td>
                                <td>{{ $h->name }} @include('partials.tenant-badge', ['tenantId' => $h->tenant_id])</td>
                                <td class="text-end">
                                    <form method="POST" action="{{ route('holidays.destroy', $h->id) }}" class="d-inline" onsubmit="return confirm('¿Eliminar el feriado {{ $h->date->format('d/m/Y') }}?')">
                                        @csrf @method('DELETE')
                                        <button type="submit" class="btn btn-sm btn-outline-danger" aria-label="Eliminar feriado"><i class="bi bi-trash"></i></button>
                                    </form>
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="4"><div class="empty-state"><i class="bi bi-calendar-x"></i>No hay feriados registrados en {{ $year }}.</div></td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>
@endsection
