@extends('layouts.app')

@section('title', 'Legajos digitales')

@section('content')
@php
    $tabs = ['pending' => 'Por revisar', 'approved' => 'Aprobados', 'rejected' => 'Rechazados', 'all' => 'Todos'];
    $badge = ['pending' => 'badge-late', 'approved' => 'badge-present', 'rejected' => 'badge-absent', 'superseded' => 'badge-muted'];
@endphp

<div class="page-head">
    <div>
        <h1>Legajos digitales</h1>
        <p>Documentos que sube el personal desde la app (DNI, CV, comprobante de domicilio). Revisa, aprueba o rechaza con motivo.</p>
    </div>
</div>

@if ($errors->any())
    <div class="card" style="margin-bottom: 1rem; border-color: rgba(239,68,68,0.3);"><div class="card-body" style="color: #fca5a5;">{{ $errors->first() }}</div></div>
@endif

<div class="stats-grid" style="margin-bottom: 1.5rem;">
    @foreach (['pending' => 'Por revisar', 'approved' => 'Aprobados', 'rejected' => 'Rechazados'] as $key => $label)
        <div class="stat-card">
            <div class="stat-card-label">{{ $label }}</div>
            <div class="stat-card-value">{{ $counts[$key] ?? 0 }}</div>
        </div>
    @endforeach
    <div class="stat-card">
        <div class="stat-card-label">Vencen en 30 días</div>
        <div class="stat-card-value" style="color: {{ $expiring->count() ? 'var(--warning)' : 'inherit' }}">{{ $expiring->count() }}</div>
    </div>
</div>

<div class="card" style="margin-bottom: 1.5rem;">
    <div class="card-body">
        <form method="GET" class="filter-bar" style="display: flex; gap: 0.75rem; flex-wrap: wrap; align-items: flex-end;">
            <div class="tabs" style="display: flex; gap: 6px;">
                @foreach ($tabs as $key => $label)
                    <a href="{{ request()->fullUrlWithQuery(['status' => $key, 'page' => null]) }}" class="btn btn-sm {{ $status === $key ? 'btn-primary' : 'btn-ghost' }}">{{ $label }}</a>
                @endforeach
            </div>
            <input type="hidden" name="status" value="{{ $status }}">
            <select name="type" class="form-control" style="max-width: 220px;">
                <option value="">Todos los tipos</option>
                @foreach (\App\Models\EmployeeDocument::TYPES as $key => $label)
                    <option value="{{ $key }}" @selected(request('type') === $key)>{{ $label }}</option>
                @endforeach
            </select>
            <input type="text" name="search" value="{{ request('search') }}" placeholder="Nombre, DNI o código" class="form-control" style="max-width: 240px;">
            <button class="btn btn-ghost">Filtrar</button>
        </form>
    </div>
</div>

<div class="card">
    <table class="attendance-live-table">
        <thead>
            <tr>
                <th>Colaborador</th>
                <th>Documento</th>
                <th>Archivo</th>
                <th>Subido</th>
                <th>Estado</th>
                <th style="text-align: right;">Revisión</th>
            </tr>
        </thead>
        <tbody>
        @forelse ($documents as $doc)
            <tr>
                <td>
                    <a href="{{ route('employees.file', $doc->user_id) }}" style="color: #fff; font-weight: 600; text-decoration: none;">{{ $doc->user?->full_name }}</a>
                    <div class="text-muted" style="font-size: 0.78rem;">{{ $doc->user?->employee_code }} · {{ $doc->user?->document_number }}</div>
                @include('partials.tenant-badge', ['tenantId' => $doc->tenant_id])</td>
                <td>
                    {{ \App\Models\EmployeeDocument::TYPES[$doc->type] ?? $doc->type }}
                    @if ($doc->expires_at)<div class="text-muted" style="font-size: 0.78rem;">Vence {{ $doc->expires_at->format('d/m/Y') }}</div>@endif
                </td>
                <td>
                    <a href="{{ route('documents.open', $doc->id) }}" target="_blank" rel="noopener" class="btn btn-ghost btn-sm">{{ $doc->isImage() ? '🖼' : '📄' }} Ver</a>
                    <div class="text-muted mono" style="font-size: 0.7rem;" title="SHA-256 {{ $doc->sha256 }}">{{ number_format($doc->size_bytes / 1024, 0) }} KB · {{ substr($doc->sha256, 0, 10) }}…</div>
                </td>
                <td class="text-muted">{{ $doc->created_at->format('d/m/Y H:i') }}</td>
                <td>
                    <span class="badge {{ $badge[$doc->status] ?? 'badge-muted' }}">{{ \App\Models\EmployeeDocument::STATUSES[$doc->status] }}</span>
                    @if ($doc->rejection_reason)<div class="text-muted" style="font-size: 0.75rem; max-width: 220px;">{{ $doc->rejection_reason }}</div>@endif
                    @if ($doc->reviewer)<div class="text-muted" style="font-size: 0.72rem;">por {{ $doc->reviewer->first_name }}</div>@endif
                </td>
                <td style="text-align: right;">
                    @if ($doc->status === 'pending')
                        <form method="POST" action="{{ route('documents.review', $doc->id) }}" style="display: flex; gap: 6px; justify-content: flex-end; flex-wrap: wrap;">
                            @csrf
                            @if ($doc->type === 'dni')
                                <input type="date" name="expires_at" class="form-control form-control-sm" style="max-width: 150px;" title="Vencimiento del documento (opcional)">
                            @endif
                            <button name="decision" value="approved" class="btn btn-success btn-sm">Aprobar</button>
                            <button type="button" class="btn btn-danger btn-sm" onclick="rejectDocument(this.form)">Rechazar</button>
                            <input type="hidden" name="reason">
                        </form>
                    @else
                        <span class="text-muted" style="font-size: 0.8rem;">{{ $doc->reviewed_at?->format('d/m/Y H:i') }}</span>
                    @endif
                </td>
            </tr>
        @empty
            <tr><td colspan="6" class="text-muted" style="text-align: center; padding: 2.5rem;">No hay documentos en esta bandeja.</td></tr>
        @endforelse
        </tbody>
    </table>
    @if ($documents->hasPages())
        <div style="padding: 1rem 1.5rem; border-top: 1px solid var(--border);">{{ $documents->links() }}</div>
    @endif
</div>

@if ($expiring->count())
<div class="card" style="margin-top: 1.5rem;">
    <div class="card-header"><div class="card-title">Documentos por vencer</div></div>
    <div class="card-body">
        @foreach ($expiring as $doc)
            <div style="display: flex; justify-content: space-between; padding: 6px 0; border-bottom: 1px solid var(--border);">
                <a href="{{ route('employees.file', $doc->user_id) }}" style="color: #fff; text-decoration: none;">{{ $doc->user?->full_name }} · {{ \App\Models\EmployeeDocument::TYPES[$doc->type] }}</a>
                <span class="badge {{ $doc->expires_at->isPast() ? 'badge-absent' : 'badge-late' }}">{{ $doc->expires_at->isPast() ? 'Vencido' : 'Vence' }} {{ $doc->expires_at->format('d/m/Y') }}</span>
            </div>
        @endforeach
    </div>
</div>
@endif
@endsection

@section('scripts')
<script>
    function rejectDocument(form) {
        const reason = prompt('Motivo del rechazo (el colaborador lo verá en la app):');
        if (!reason || reason.trim().length < 5) { if (reason !== null) alert('Escribe un motivo de al menos 5 caracteres.'); return; }
        form.querySelector('[name=reason]').value = reason.trim();
        const decision = document.createElement('input');
        decision.type = 'hidden'; decision.name = 'decision'; decision.value = 'rejected';
        form.appendChild(decision);
        form.submit();
    }
</script>
@endsection
