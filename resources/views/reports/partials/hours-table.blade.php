<div class="card-header border-0 pt-3 pb-2 d-flex justify-content-between align-items-center flex-wrap gap-2">
    <h2 class="h6 fw-semibold mb-0 text-body">Resumen por colaborador</h2>
    <span class="small text-body-secondary">
        {{ \Carbon\Carbon::parse($filters->from)->format('d/m/Y') }} – {{ \Carbon\Carbon::parse($filters->to)->format('d/m/Y') }}
        · <span id="hoursTotalEmployeesBadge" class="fw-semibold text-body">{{ $rows->total() }}</span> colaboradores
    </span>
</div>
@php($rules = app(\App\Modules\Attendance\Services\Reports\AttendanceReportService::class)->rules($filters->tenantId))
@php($hm = fn (int $minutes) => \App\Modules\Attendance\Services\Reports\AttendanceReportService::hm($minutes))
<div class="px-3 pb-2 small text-body-secondary"><i class="bi bi-stopwatch me-1"></i>{{ $rules['tolerance'] }}</div>
<div class="table-responsive">
    <table class="table table-hover align-middle mb-0">
        <thead class="bg-surface-elevated text-body-secondary small text-uppercase">
            <tr>
                <th class="ps-3 py-3">Colaborador</th>
                <th class="py-3">Sucursal</th>
                <th class="py-3 text-end" title="Días laborables según su turno">Prog.</th>
                <th class="py-3 text-end">Trab.</th>
                <th class="py-3 text-end">Horas</th>
                <th class="py-3 text-end">Tardanza</th>
                <th class="py-3 text-end" title="Salidas anticipadas">Sal. antic.</th>
                <th class="py-3 text-end">HE diurnas</th>
                <th class="py-3 text-end">HE nocturnas</th>
                <th class="py-3 text-end">Nocturnas</th>
                <th class="py-3 text-end">Faltas</th>
                <th class="py-3 text-end">Justif.</th>
                <th class="py-3 text-end" title="Días de descanso de su programación">Desc.</th>
                <th class="py-3 text-end" title="Días de vacaciones aprobadas en el periodo">Vacac.</th>
                <th class="py-3 text-end">Permiso</th>
                <th class="pe-3 py-3 text-end" title="Descanso médico">D. méd.</th>
            </tr>
        </thead>
        <tbody>
            @forelse($rows as $r)
                @php($s = $r['summary'])
                <tr>
                    <td class="ps-3">
                        <div class="fw-semibold text-body">{{ $r['name'] }}</div>
                        <div class="small text-body-secondary">{{ $r['employee_code'] ?? '' }}{{ $r['position'] ? ' · ' . $r['position'] : '' }}</div>
                        @include('partials.tenant-badge', ['tenantId' => $r['tenant_id']])
                    </td>
                    <td class="text-body-secondary small">
                        <i class="bi bi-building me-1"></i>{{ $r['branch'] ?? '—' }}
                    </td>
                    <td class="text-end small text-body">{{ $s['scheduled_days'] }}</td>
                    <td class="text-end small text-body">{{ $s['days_worked'] }}</td>
                    <td class="text-end fw-bold text-body small">{{ $hm($s['worked_minutes']) }}</td>
                    <td class="text-end small {{ $s['late_minutes'] > 0 ? 'text-warning-emphasis fw-bold' : 'text-body-secondary' }}">{{ $hm($s['late_minutes']) }}</td>
                    <td class="text-end text-body-secondary small">{{ $hm($s['early_minutes']) }}</td>
                    <td class="text-end small text-body">{{ $hm($s['overtime_day_minutes']) }}</td>
                    <td class="text-end small text-body">{{ $hm($s['overtime_night_minutes']) }}</td>
                    <td class="text-end small text-body">{{ $hm($s['night_minutes']) }}</td>
                    <td class="text-end small {{ $s['absences_unjustified'] > 0 ? 'text-danger fw-bold' : 'text-body-secondary' }}">{{ $s['absences_unjustified'] }}</td>
                    <td class="text-end small text-body-secondary">{{ $s['absences_justified'] }}</td>
                    <td class="text-end small text-body-secondary">{{ $s['rest_days'] }}</td>
                    <td class="text-end small text-body">{{ $s['vacation_days'] }}</td>
                    <td class="text-end small text-body-secondary">{{ $s['permission_days'] }}</td>
                    <td class="pe-3 text-end small text-body-secondary">{{ $s['medical_leave_days'] }}</td>
                </tr>
            @empty
                <tr>
                    <td colspan="16" class="p-5 text-center">
                        <div class="d-inline-flex align-items-center justify-content-center p-3 rounded-circle bg-primary-subtle text-primary mb-3" style="width: 56px; height: 56px;">
                            <i class="bi bi-inbox fs-3"></i>
                        </div>
                        <h6 class="fw-bold text-body mb-1">Sin colaboradores</h6>
                        <span class="text-body-secondary small">No hay colaboradores para los filtros elegidos.</span>
                    </td>
                </tr>
            @endforelse
        </tbody>
    </table>
</div>

<div class="px-3 py-2 small text-body-secondary border-top"><i class="bi bi-calculator me-1"></i>{{ $rules['formula'] }}</div>

@if($rows->total() > 0)
    <div class="table-pagination-footer">
        <div class="table-pagination-info">
            <span>
                Mostrando <strong>{{ $rows->firstItem() ?? 0 }}</strong> a <strong>{{ $rows->lastItem() ?? 0 }}</strong> de <strong>{{ $rows->total() }}</strong> colaboradores
            </span>
            <div class="per-page-selector ms-sm-2">
                <label for="hrsPerPageSelect" class="text-secondary small mb-0">Mostrar:</label>
                <select id="hrsPerPageSelect" class="form-select form-select-sm" aria-label="Cantidad por página">
                    <option value="10" @selected($rows->perPage() == 10)>10</option>
                    <option value="25" @selected($rows->perPage() == 25)>25</option>
                    <option value="50" @selected($rows->perPage() == 50)>50</option>
                    <option value="100" @selected($rows->perPage() == 100)>100</option>
                </select>
            </div>
        </div>

        <div class="d-flex align-items-center gap-2">
            @if($rows->hasPages())
                <nav aria-label="Navegación de páginas">
                    <ul class="pagination pagination-sm mb-0">
                        {{-- Anterior --}}
                        @if($rows->onFirstPage())
                            <li class="page-item disabled" aria-disabled="true">
                                <span class="page-link" aria-label="Anterior">&lsaquo;</span>
                            </li>
                        @else
                            <li class="page-item">
                                <button type="button" class="page-link ajax-hours-page-btn" data-page="{{ $rows->currentPage() - 1 }}" aria-label="Anterior">&lsaquo;</button>
                            </li>
                        @endif

                        {{-- Páginas --}}
                        @foreach($rows->getUrlRange(max(1, $rows->currentPage() - 2), min($rows->lastPage(), $rows->currentPage() + 2)) as $page => $url)
                            <li class="page-item {{ $page == $rows->currentPage() ? 'active' : '' }}">
                                @if($page == $rows->currentPage())
                                    <span class="page-link">{{ $page }}</span>
                                @else
                                    <button type="button" class="page-link ajax-hours-page-btn" data-page="{{ $page }}">{{ $page }}</button>
                                @endif
                            </li>
                        @endforeach

                        {{-- Siguiente --}}
                        @if($rows->hasMorePages())
                            <li class="page-item">
                                <button type="button" class="page-link ajax-hours-page-btn" data-page="{{ $rows->currentPage() + 1 }}" aria-label="Siguiente">&rsaquo;</button>
                            </li>
                        @else
                            <li class="page-item disabled" aria-disabled="true">
                                <span class="page-link" aria-label="Siguiente">&rsaquo;</span>
                            </li>
                        @endif
                    </ul>
                </nav>
            @else
                <nav aria-label="Navegación de páginas">
                    <ul class="pagination pagination-sm mb-0">
                        <li class="page-item disabled" aria-disabled="true">
                            <span class="page-link" aria-label="Anterior">&lsaquo;</span>
                        </li>
                        <li class="page-item active" aria-current="page">
                            <span class="page-link">1</span>
                        </li>
                        <li class="page-item disabled" aria-disabled="true">
                            <span class="page-link" aria-label="Siguiente">&rsaquo;</span>
                        </li>
                    </ul>
                </nav>
            @endif
            <span class="pagination-page-indicator">
                Pág. {{ $rows->currentPage() }} de {{ $rows->lastPage() }}
            </span>
        </div>
    </div>
@endif
