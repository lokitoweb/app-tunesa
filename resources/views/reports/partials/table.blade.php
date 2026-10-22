<div class="table-responsive">
    <table class="table table-hover align-middle mb-0">
        <thead class="bg-surface-elevated text-body-secondary small text-uppercase">
            <tr>
                <th class="ps-3 py-3">Fecha</th>
                <th class="py-3">Colaborador</th>
                <th class="py-3">Sucursal</th>
                <th class="py-3">Entrada</th>
                <th class="py-3">Salida</th>
                <th class="py-3 text-end">Retardo</th>
                <th class="py-3 text-end">Horas</th>
                <th class="py-3 text-end">Extra</th>
                <th class="py-3 text-center">Geocerca</th>
                <th class="pe-3 py-3 text-center">Estado</th>
            </tr>
        </thead>
        <tbody>
            @forelse($attendances as $att)
                <tr>
                    <td class="ps-3 text-nowrap fw-semibold">
                        <i class="bi bi-calendar3 me-1 text-primary"></i>
                        {{ $att->work_date ? $att->work_date->format('d/m/Y') : '—' }}
                    </td>
                    <td>
                        <div class="d-flex align-items-center gap-2">
                            <span class="rounded-circle d-inline-flex align-items-center justify-content-center bg-primary text-white fw-bold shadow-sm" style="width: 32px; height: 32px; font-size: 0.75rem;">
                                {{ mb_strtoupper(mb_substr($att->user->first_name ?? 'C', 0, 1) . mb_substr($att->user->last_name ?? 'O', 0, 1)) }}
                            </span>
                            <div>
                                <div class="fw-bold text-body">{{ $att->user->first_name ?? 'N/A' }} {{ $att->user->last_name ?? '' }}</div>
                                @include('partials.tenant-badge', ['tenantId' => $att->tenant_id])
                            </div>
                        </div>
                    </td>
                    <td class="text-body-secondary small">
                        <i class="bi bi-building me-1"></i>{{ $att->branch->name ?? '—' }}
                    </td>
                    <td class="small">
                        @if($att->clock_in_at)
                            <span class="badge bg-success-subtle text-success border border-success-subtle px-2 py-1">
                                <i class="bi bi-box-arrow-in-right me-1"></i>{{ $att->clock_in_at->format('h:i a') }}
                            </span>
                        @else
                            <span class="text-body-secondary">—</span>
                        @endif
                    </td>
                    <td class="small">
                        @if($att->clock_out_at)
                            <span class="badge bg-primary-subtle text-primary border border-primary-subtle px-2 py-1">
                                <i class="bi bi-box-arrow-left me-1"></i>{{ $att->clock_out_at->format('h:i a') }}
                            </span>
                        @else
                            <span class="text-body-secondary">—</span>
                        @endif
                    </td>
                    <td class="text-end small {{ $att->late_minutes > 0 ? 'text-warning-emphasis fw-bold' : 'text-body-secondary' }}">
                        {{ $att->late_minutes > 0 ? ($att->late_minutes . ' min') : '0 min' }}
                    </td>
                    <td class="text-end fw-bold text-body small">
                        {{ number_format((float) ($att->worked_hours ?? 0), 2) }}
                    </td>
                    <td class="text-end small {{ ($att->overtime_hours ?? 0) > 0 ? 'text-primary fw-bold' : 'text-body-secondary' }}">
                        {{ number_format((float) ($att->overtime_hours ?? 0), 2) }}
                    </td>
                    <td class="text-center">
                        @if($att->clock_in_within_geofence)
                            <span class="badge bg-success-subtle text-success border border-success-subtle px-2 py-1" title="Marcación dentro del rango permitido">
                                <i class="bi bi-geo-alt-fill me-1"></i>Válida
                            </span>
                        @else
                            <span class="badge bg-danger-subtle text-danger border border-danger-subtle px-2 py-1" title="Marcación fuera de geocerca">
                                <i class="bi bi-geo-alt-fill me-1"></i>Fuera
                            </span>
                        @endif
                    </td>
                    <td class="pe-3 text-center">
                        @include('partials.attendance-status', ['status' => $att->status])
                    </td>
                </tr>
            @empty
                <tr>
                    <td colspan="10" class="p-5 text-center">
                        <div class="d-inline-flex align-items-center justify-content-center p-3 rounded-circle bg-primary-subtle text-primary mb-3" style="width: 56px; height: 56px;">
                            <i class="bi bi-calendar-x fs-3"></i>
                        </div>
                        <h6 class="fw-bold text-body mb-1">Sin asistencias registradas</h6>
                        <span class="text-body-secondary small">No hay registros de jornada para el periodo y filtros seleccionados.</span>
                    </td>
                </tr>
            @endforelse
        </tbody>
    </table>
</div>

@if($attendances->total() > 0)
    <div class="table-pagination-footer">
        <div class="table-pagination-info">
            <span>
                Mostrando <strong>{{ $attendances->firstItem() ?? 0 }}</strong> a <strong>{{ $attendances->lastItem() ?? 0 }}</strong> de <strong>{{ $attendances->total() }}</strong> marcaciones
            </span>
            <div class="per-page-selector ms-sm-2">
                <label for="repPerPageSelect" class="text-secondary small mb-0">Mostrar:</label>
                <select id="repPerPageSelect" class="form-select form-select-sm" aria-label="Cantidad por página">
                    <option value="10" @selected($attendances->perPage() == 10)>10</option>
                    <option value="25" @selected($attendances->perPage() == 25)>25</option>
                    <option value="50" @selected($attendances->perPage() == 50)>50</option>
                    <option value="100" @selected($attendances->perPage() == 100)>100</option>
                </select>
            </div>
        </div>

        <div class="d-flex align-items-center gap-2">
            @if($attendances->hasPages())
                <nav aria-label="Navegación de páginas">
                    <ul class="pagination pagination-sm mb-0">
                        {{-- Anterior --}}
                        @if($attendances->onFirstPage())
                            <li class="page-item disabled" aria-disabled="true">
                                <span class="page-link" aria-label="Anterior">&lsaquo;</span>
                            </li>
                        @else
                            <li class="page-item">
                                <button type="button" class="page-link ajax-report-page-btn" data-page="{{ $attendances->currentPage() - 1 }}" aria-label="Anterior">&lsaquo;</button>
                            </li>
                        @endif

                        {{-- Páginas --}}
                        @foreach($attendances->getUrlRange(max(1, $attendances->currentPage() - 2), min($attendances->lastPage(), $attendances->currentPage() + 2)) as $page => $url)
                            <li class="page-item {{ $page == $attendances->currentPage() ? 'active' : '' }}">
                                @if($page == $attendances->currentPage())
                                    <span class="page-link">{{ $page }}</span>
                                @else
                                    <button type="button" class="page-link ajax-report-page-btn" data-page="{{ $page }}">{{ $page }}</button>
                                @endif
                            </li>
                        @endforeach

                        {{-- Siguiente --}}
                        @if($attendances->hasMorePages())
                            <li class="page-item">
                                <button type="button" class="page-link ajax-report-page-btn" data-page="{{ $attendances->currentPage() + 1 }}" aria-label="Siguiente">&rsaquo;</button>
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
                Pág. {{ $attendances->currentPage() }} de {{ $attendances->lastPage() }}
            </span>
        </div>
    </div>
@endif
