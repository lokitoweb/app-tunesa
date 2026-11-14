@extends('layouts.app')

@section('title', 'Acceso a varias empresas')

@section('content')
<header class="page-head">
    <div class="page-head-text">
        <h1 class="page-title">Acceso a varias empresas</h1>
        <p class="page-subtitle">Asigna a supervisores, gerentes y demás roles de gestión otras empresas del grupo para supervisarlas con su mismo rol. Siguen siendo de su empresa: ahí marcan su asistencia y está su legajo.</p>
    </div>
</header>

<div class="alert alert-info d-flex gap-2 small" role="note">
    <i class="bi bi-info-circle mt-1"></i>
    <div>
        En el panel, la persona elige la empresa en el selector de la barra superior. En cada empresa asignada hace lo mismo que su rol permite
        en esa empresa (por ejemplo, un supervisor ve las agencias donde figura como supervisor y las que no tienen supervisor).
        En la app móvil sigue viendo solo su empresa.
    </div>
</div>

@if($people->isEmpty() || $companies->count() < 2)
    <div class="emp-table-card shadow-sm p-5 text-center text-body-secondary">
        <i class="bi bi-diagram-3 fs-3 d-block mb-2"></i>
        {{ $companies->count() < 2 ? 'Se necesitan al menos dos empresas activas.' : 'No hay personas activas con un rol de gestión.' }}
    </div>
@else
<form method="POST" action="{{ route('settings.company-access.update') }}" data-confirm="Se guardará el acceso de las personas marcadas." data-confirm-title="¿Guardar los accesos?" data-confirm-button="Guardar">
    @csrf
    @method('PUT')
    <div class="emp-table-card shadow-sm">
        <div class="d-flex flex-wrap gap-2 align-items-center p-3 border-bottom">
            <div class="search-field flex-grow-1" style="max-width: 360px;">
                <i class="bi bi-search"></i>
                <input type="search" class="form-control" id="accessSearch" placeholder="Buscar por nombre, cargo o rol…" aria-label="Buscar persona">
            </div>
            <select class="form-select w-auto" id="accessCompany" aria-label="Empresa de la persona">
                <option value="">Todas las empresas de origen</option>
                @foreach($companies as $c)
                    <option value="{{ $c->id }}">{{ $c->company_name }}</option>
                @endforeach
            </select>
            <span class="small text-body-secondary ms-auto" id="accessCount">{{ $people->count() }} personas</span>
        </div>
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead class="table-light">
                    <tr>
                        <th scope="col" style="min-width: 240px;">Persona</th>
                        <th scope="col" style="min-width: 150px;">Su empresa</th>
                        @foreach($companies as $c)
                            <th scope="col" class="text-center" style="min-width: 130px;">{{ $c->company_name }}</th>
                        @endforeach
                    </tr>
                </thead>
                <tbody>
                    @foreach($people as $p)
                        @php $granted = $p->extraTenants->pluck('id')->map(fn ($id) => (int) $id)->all(); @endphp
                        <tr data-access-row data-company="{{ $p->tenant_id }}"
                            data-search="{{ mb_strtolower($p->first_name . ' ' . $p->last_name . ' ' . ($p->jobPosition?->name ?? $p->position) . ' ' . label('user_role', $p->role)) }}">
                            <td>
                                <input type="hidden" name="people[]" value="{{ $p->id }}">
                                <div class="fw-semibold">{{ $p->first_name }} {{ $p->last_name }}</div>
                                <div class="small text-body-secondary">
                                    <x-status-badge type="user_role" :value="$p->role" />
                                    @if($p->jobPosition?->name ?? $p->position) <span class="ms-1">{{ $p->jobPosition?->name ?? $p->position }}</span> @endif
                                </div>
                            </td>
                            <td class="small">{{ $p->tenant?->company_name }}</td>
                            @foreach($companies as $c)
                                <td class="text-center">
                                    @if($c->id === (int) $p->tenant_id)
                                        <span class="badge text-bg-light border" title="Es su empresa">Su empresa</span>
                                    @else
                                        <input type="checkbox" class="form-check-input" name="access[{{ $p->id }}][]" value="{{ $c->id }}"
                                               aria-label="{{ $p->first_name }} supervisa {{ $c->company_name }}" @checked(in_array($c->id, $granted, true))>
                                    @endif
                                </td>
                            @endforeach
                        </tr>
                    @endforeach
                    <tr class="d-none" id="accessEmpty"><td colspan="{{ 2 + $companies->count() }}" class="text-center text-body-secondary py-4">Nadie coincide con la búsqueda.</td></tr>
                </tbody>
            </table>
        </div>
        <div class="d-flex justify-content-end gap-2 p-3 border-top">
            <button type="submit" class="btn btn-primary"><i class="bi bi-floppy2-fill me-1"></i>Guardar accesos</button>
        </div>
    </div>
</form>
@endif
@endsection

@section('scripts')
<script>
    (() => {
        const search = document.getElementById('accessSearch');
        const company = document.getElementById('accessCompany');
        if (!search) return;
        const apply = () => {
            const q = search.value.trim().toLocaleLowerCase('es');
            let shown = 0;
            document.querySelectorAll('[data-access-row]').forEach((row) => {
                const ok = (!q || row.dataset.search.includes(q)) && (!company.value || row.dataset.company === company.value);
                row.classList.toggle('d-none', !ok);
                if (ok) shown++;
            });
            document.getElementById('accessEmpty').classList.toggle('d-none', shown > 0);
            document.getElementById('accessCount').textContent = `${shown} ${shown === 1 ? 'persona' : 'personas'}`;
        };
        search.addEventListener('input', apply);
        company.addEventListener('change', apply);
    })();
</script>
@endsection
