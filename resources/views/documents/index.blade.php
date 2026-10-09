@extends('layouts.app')

@section('title', 'Legajos digitales')

@section('content')
@php
    use App\Models\EmployeeDocument;
    $tabs = ['pending' => 'Por revisar', 'approved' => 'Aprobados', 'rejected' => 'Rechazados', 'all' => 'Todos'];
    $tone = ['pending' => 'warning', 'approved' => 'success', 'rejected' => 'danger', 'superseded' => 'secondary'];
@endphp

<div class="page-header">
    <div>
        <h1>Legajos digitales</h1>
        <p>Documentos que el personal sube desde la app (DNI, CV, comprobante de domicilio). Aprueba o rechaza con motivo.</p>
    </div>
</div>

<div class="row g-3 mb-4">
    @foreach([['pending', 'Por revisar', 'hourglass-split', 'warning'], ['approved', 'Aprobados', 'check-circle', 'success'], ['rejected', 'Rechazados', 'x-circle', 'danger']] as [$key, $label, $icon, $t])
        <div class="col-6 col-lg-3">
            <div class="card border-0 h-100 kpi-card"><div class="card-body d-flex align-items-center gap-3">
                <span class="kpi-icon bg-{{ $t }}-subtle text-{{ $t }}-emphasis"><i class="bi bi-{{ $icon }}"></i></span>
                <div><div class="kpi-label">{{ $label }}</div><div class="fs-4 fw-bold">{{ $counts[$key] ?? 0 }}</div></div>
            </div></div>
        </div>
    @endforeach
    <div class="col-6 col-lg-3">
        <div class="card border-0 h-100 kpi-card"><div class="card-body d-flex align-items-center gap-3">
            <span class="kpi-icon bg-info-subtle text-info-emphasis"><i class="bi bi-calendar-event"></i></span>
            <div><div class="kpi-label">Vencen en 30 días</div><div class="fs-4 fw-bold {{ $expiring->count() ? 'text-warning-emphasis' : '' }}">{{ $expiring->count() }}</div></div>
        </div></div>
    </div>
</div>

<div class="card border-0 mb-4">
    <div class="card-body">
        <ul class="nav nav-pills mb-3 flex-nowrap overflow-auto">
            @foreach($tabs as $key => $label)
                <li class="nav-item">
                    <a class="nav-link text-nowrap {{ $status === $key ? 'active' : '' }}" @if($status === $key) aria-current="page" @endif href="{{ request()->fullUrlWithQuery(['status' => $key, 'page' => null]) }}">{{ $label }}</a>
                </li>
            @endforeach
        </ul>
        <form method="GET" class="row g-2 align-items-end">
            <input type="hidden" name="status" value="{{ $status }}">
            <div class="col-sm-5 col-lg-3">
                <label for="type" class="form-label small">Tipo</label>
                <select id="type" name="type" class="form-select">
                    <option value="">Todos los tipos</option>
                    @foreach(EmployeeDocument::TYPES as $key => $label)
                        <option value="{{ $key }}" @selected(request('type') === $key)>{{ $label }}</option>
                    @endforeach
                </select>
            </div>
            <div class="col-sm-5 col-lg-4">
                <label for="search" class="form-label small">Colaborador</label>
                <input type="search" id="search" name="search" value="{{ request('search') }}" placeholder="Nombre, DNI o código" class="form-control">
            </div>
            <div class="col-sm-2 col-lg-2 d-grid">
                <button class="btn btn-outline-primary"><i class="bi bi-funnel me-1"></i>Filtrar</button>
            </div>
        </form>
    </div>
</div>

<div class="card border-0 mb-4">
    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
            <thead>
                <tr>
                    <th>Colaborador</th>
                    <th>Documento</th>
                    <th>Archivo</th>
                    <th>Subido</th>
                    <th>Estado</th>
                    <th class="text-end">Revisión</th>
                </tr>
            </thead>
            <tbody>
            @forelse($documents as $doc)
                <tr>
                    <td>
                        <a href="{{ route('employees.file', $doc->user_id) }}" class="fw-semibold text-decoration-none">{{ $doc->user?->full_name }}</a>
                        <div class="small text-body-secondary">{{ $doc->user?->employee_code }} · {{ $doc->user?->document_number }}</div>
                    </td>
                    <td>
                        {{ EmployeeDocument::TYPES[$doc->type] ?? $doc->type }}
                        @if($doc->expires_at)<div class="small text-body-secondary">Vence {{ $doc->expires_at->format('d/m/Y') }}</div>@endif
                    </td>
                    <td>
                        <a href="{{ route('documents.open', $doc->id) }}" target="_blank" rel="noopener" class="btn btn-sm btn-outline-secondary">
                            <i class="bi {{ $doc->isImage() ? 'bi-image' : 'bi-file-earmark-pdf' }} me-1"></i>Ver
                        </a>
                        <div class="small text-body-secondary font-monospace" title="SHA-256 {{ $doc->sha256 }}">{{ number_format($doc->size_bytes / 1024, 0) }} KB · {{ substr($doc->sha256, 0, 10) }}…</div>
                    </td>
                    <td class="small text-body-secondary text-nowrap">{{ $doc->created_at->format('d/m/Y H:i') }}</td>
                    <td>
                        @include('partials.badge', ['tone' => $tone[$doc->status] ?? 'secondary', 'label' => EmployeeDocument::STATUSES[$doc->status]])
                        @if($doc->rejection_reason)<div class="small text-body-secondary" style="max-width: 220px;">{{ $doc->rejection_reason }}</div>@endif
                        @if($doc->reviewer)<div class="small text-body-secondary">por {{ $doc->reviewer->first_name }}</div>@endif
                    </td>
                    <td class="text-end">
                        @if($doc->status === 'pending')
                            <form method="POST" action="{{ route('documents.review', $doc->id) }}" class="d-flex gap-2 justify-content-end flex-wrap">
                                @csrf
                                @if($doc->type === 'dni')
                                    <input type="date" name="expires_at" class="form-control form-control-sm" style="max-width: 150px;" title="Vencimiento del documento (opcional)" aria-label="Vencimiento del documento">
                                @endif
                                <button name="decision" value="approved" class="btn btn-sm btn-success"><i class="bi bi-check-lg"></i> Aprobar</button>
                                <button type="button" class="btn btn-sm btn-outline-danger" data-bs-toggle="modal" data-bs-target="#rejectModal"
                                        data-action="{{ route('documents.review', $doc->id) }}" data-label="{{ EmployeeDocument::TYPES[$doc->type] ?? $doc->type }} de {{ $doc->user?->full_name }}">
                                    Rechazar
                                </button>
                            </form>
                        @else
                            <span class="small text-body-secondary">{{ $doc->reviewed_at?->format('d/m/Y H:i') }}</span>
                        @endif
                    </td>
                </tr>
            @empty
                <tr><td colspan="6"><div class="empty-state"><i class="bi bi-inbox"></i>No hay documentos en esta bandeja.</div></td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
    @if($documents->hasPages())
        <div class="card-footer bg-transparent">{{ $documents->links() }}</div>
    @endif
</div>

@if($expiring->count())
    <div class="card border-0">
        <div class="card-header border-0 pt-3"><h2 class="h6 fw-semibold mb-0">Documentos por vencer</h2></div>
        <ul class="list-group list-group-flush">
            @foreach($expiring as $doc)
                <li class="list-group-item d-flex justify-content-between align-items-center">
                    <a href="{{ route('employees.file', $doc->user_id) }}" class="text-decoration-none">{{ $doc->user?->full_name }} · {{ EmployeeDocument::TYPES[$doc->type] }}</a>
                    @include('partials.badge', ['tone' => $doc->expires_at->isPast() ? 'danger' : 'warning', 'label' => ($doc->expires_at->isPast() ? 'Vencido ' : 'Vence ') . $doc->expires_at->format('d/m/Y')])
                </li>
            @endforeach
        </ul>
    </div>
@endif

{{-- Rechazo con motivo --}}
<div class="modal fade" id="rejectModal" tabindex="-1" aria-labelledby="rejectModalTitle" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <form method="POST" class="modal-content" id="rejectForm">
            @csrf
            <input type="hidden" name="decision" value="rejected">
            <div class="modal-header">
                <h2 class="modal-title fs-6" id="rejectModalTitle">Rechazar documento</h2>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button>
            </div>
            <div class="modal-body">
                <p class="small text-body-secondary" id="rejectLabel"></p>
                <label for="rejectReason" class="form-label">Motivo del rechazo</label>
                <textarea id="rejectReason" name="reason" class="form-control" rows="3" minlength="5" maxlength="255" required aria-describedby="rejectHelp"></textarea>
                <div id="rejectHelp" class="form-text">El colaborador lo verá en la app para volver a subirlo.</div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancelar</button>
                <button type="submit" class="btn btn-danger">Rechazar</button>
            </div>
        </form>
    </div>
</div>
@endsection

@section('scripts')
<script>
document.getElementById('rejectModal').addEventListener('show.bs.modal', (e) => {
    const btn = e.relatedTarget;
    document.getElementById('rejectForm').action = btn.dataset.action;
    document.getElementById('rejectLabel').textContent = btn.dataset.label;
    document.getElementById('rejectReason').value = '';
});
</script>
@endsection
