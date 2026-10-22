@php
    $monthNames = [
        1 => 'Enero', 2 => 'Febrero', 3 => 'Marzo', 4 => 'Abril',
        5 => 'Mayo', 6 => 'Junio', 7 => 'Julio', 8 => 'Agosto',
        9 => 'Septiembre', 10 => 'Octubre', 11 => 'Noviembre', 12 => 'Diciembre'
    ];
    $currentMonthName = $monthNames[$currentMonth] ?? 'Mes ' . $currentMonth;
@endphp

<div class="payslips-table-card shadow-sm mb-4">
    <div class="payslips-table-header p-3 border-bottom d-flex align-items-center justify-content-between flex-wrap gap-2">
        <div class="d-flex align-items-center">
            <i class="bi bi-file-earmark-ruled text-primary fs-5" style="margin-right: 10px !important;"></i>
            <h2 class="h6 fw-bold mb-0 text-body" style="margin-right: 10px !important;">Registro de Boletas de Pago</h2>
            <span class="badge rounded-pill bg-light text-secondary border px-2 py-0.5 small" id="tableRecordsBadge">
                {{ $payslips->total() }} {{ $payslips->total() === 1 ? 'boleta' : 'boletas' }}
            </span>
        </div>
        <div class="text-muted small">
            Mostrando periodo: <strong class="text-body" id="tableHeaderPeriodLabel">{{ $currentMonthName }} {{ $currentYear }}</strong>
        </div>
    </div>

    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0 payslips-table">
            <thead>
                <tr>
                    <th style="min-width: 240px;">Colaborador</th>
                    <th style="min-width: 200px;">Boleta / Archivo</th>
                    <th class="text-end" style="min-width: 120px;">Sueldo Bruto</th>
                    <th class="text-end" style="min-width: 120px;">Descuentos</th>
                    <th class="text-end" style="min-width: 130px;">Neto a Pagar</th>
                    <th style="min-width: 110px;">Estado</th>
                    <th style="min-width: 150px;">Lectura en App</th>
                    <th class="text-end" style="min-width: 170px;">Acciones</th>
                </tr>
            </thead>
            <tbody>
                @forelse($payslips as $p)
                    @php
                        $emp = $p->employee;
                        $initials = $emp ? strtoupper(substr($emp->first_name ?? 'E', 0, 1) . substr($emp->last_name ?? 'M', 0, 1)) : 'BP';
                        $fileSizeKb = $p->file_size ? round($p->file_size / 1024, 1) . ' KB' : 'PDF';
                    @endphp
                    <tr>
                        {{-- Colaborador --}}
                        <td>
                            <div class="d-flex align-items-center" style="gap: 12px;">
                                <div class="payslip-avatar-circle shadow-xs">
                                    {{ $initials }}
                                </div>
                                <div class="min-w-0">
                                    <div class="fw-bold text-body text-truncate" style="max-width: 230px;">
                                        {{ $emp?->first_name }} {{ $emp?->last_name }}
                                    </div>
                                    <div class="small text-secondary text-truncate" style="font-size: 0.75rem;">
                                        {{ $emp?->employee_code ?? 'EMP' }} · DNI {{ $emp?->document_number ?? 'S/N' }}
                                    </div>
                                    @include('partials.tenant-badge', ['tenantId' => $p->tenant_id])
                                </div>
                            </div>
                        </td>

                        {{-- Boleta --}}
                        <td>
                            <div class="fw-semibold text-body text-truncate" style="max-width: 220px;" title="{{ $p->title }}">
                                {{ $p->title }}
                            </div>
                            <div class="small text-secondary d-flex align-items-center gap-1.5" style="font-size: 0.75rem;">
                                <span class="badge bg-light text-secondary border px-1.5 py-0.5" style="font-size: 0.7rem;">
                                    <i class="bi bi-file-earmark-pdf-fill text-danger" style="margin-right: 4px;"></i>{{ $fileSizeKb }}
                                </span>
                                <span>{{ $p->period_label }}</span>
                            </div>
                        </td>

                        {{-- Sueldo Bruto --}}
                        <td class="text-end font-monospace text-nowrap">
                            S/ {{ number_format((float) $p->gross_salary, 2) }}
                        </td>

                        {{-- Descuentos --}}
                        <td class="text-end font-monospace text-nowrap text-danger">
                            − S/ {{ number_format((float) $p->deductions, 2) }}
                        </td>

                        {{-- Neto a Pagar --}}
                        <td class="text-end font-monospace text-nowrap fw-bold text-body" style="font-size: 0.95rem;">
                            S/ {{ number_format((float) $p->net_salary, 2) }}
                        </td>

                        {{-- Estado --}}
                        <td>
                            @if($p->status === 'published')
                                <span class="badge bg-success-subtle text-success border border-success-subtle px-2 py-1">
                                    <span class="badge-dot bg-success" style="margin-right: 6px;"></span>Publicada
                                </span>
                            @elseif($p->status === 'draft')
                                <span class="badge bg-secondary-subtle text-secondary border border-secondary-subtle px-2 py-1">
                                    <span class="badge-dot bg-secondary" style="margin-right: 6px;"></span>Borrador
                                </span>
                            @else
                                <span class="badge bg-danger-subtle text-danger border border-danger-subtle px-2 py-1">
                                    <span class="badge-dot bg-danger" style="margin-right: 6px;"></span>Anulada
                                </span>
                            @endif
                        </td>

                        {{-- Lectura en App --}}
                        <td>
                            @if($p->read_at)
                                <div class="d-inline-flex align-items-center text-success small fw-semibold" title="Abierta por el colaborador">
                                    <i class="bi bi-check2-all fs-6" style="margin-right: 6px;"></i>
                                    <span>{{ $p->read_at->format('d/m/Y H:i') }}</span>
                                </div>
                            @else
                                <div class="d-inline-flex align-items-center text-muted small">
                                    <i class="bi bi-clock" style="margin-right: 6px;"></i>
                                    <span>No leída aún</span>
                                </div>
                            @endif
                        </td>

                        {{-- Acciones --}}
                        <td class="text-end text-nowrap">
                            <div class="d-inline-flex align-items-center" style="gap: 6px;">
                                {{-- Botón Publicar (si es borrador) --}}
                                @if($p->status === 'draft')
                                    <button type="button" 
                                            class="btn btn-sm btn-action-btn btn-action-publish" 
                                            title="Publicar en app"
                                            onclick="openConfirmPublishModal({{ $p->id }}, '{{ addslashes($emp?->first_name . ' ' . $emp?->last_name) }}', '{{ addslashes($p->title) }}')">
                                        <i class="bi bi-send-check"></i>
                                    </button>
                                    <form id="form-publish-{{ $p->id }}" method="POST" action="{{ route('payslips.publish', $p->id) }}" class="d-none">
                                        @csrf
                                    </form>
                                @endif

                                {{-- Vista Previa en Modal / Link directo --}}
                                <button type="button" 
                                        class="btn btn-sm btn-action-btn btn-action-view" 
                                        title="Ver boleta en PDF"
                                        onclick="openPdfPreviewModal('{{ route('payslips.view', $p->id) }}', '{{ route('payslips.download', $p->id) }}', '{{ addslashes($p->title) }}', '{{ addslashes($emp?->first_name . ' ' . $emp?->last_name) }}')">
                                    <i class="bi bi-eye"></i>
                                </button>
                                <a href="{{ route('payslips.view', $p->id) }}" target="_blank" rel="noopener" class="d-none" aria-label="Ver boleta"></a>

                                {{-- Descargar PDF --}}
                                <a href="{{ route('payslips.download', $p->id) }}" class="btn btn-sm btn-action-btn btn-action-download" title="Descargar PDF" aria-label="Descargar PDF">
                                    <i class="bi bi-download"></i>
                                </a>

                                {{-- Anular (si está publicada) o Eliminar (si es borrador/anulada) --}}
                                @if($p->status === 'published')
                                    <button type="button" 
                                            class="btn btn-sm btn-action-btn btn-action-annul" 
                                            title="Anular boleta"
                                            onclick="openConfirmAnnulModal({{ $p->id }}, '{{ addslashes($emp?->first_name . ' ' . $emp?->last_name) }}')">
                                        <i class="bi bi-slash-circle"></i>
                                    </button>
                                    <form id="form-annul-{{ $p->id }}" method="POST" action="{{ route('payslips.annul', $p->id) }}" class="d-none">
                                        @csrf
                                    </form>
                                @else
                                    <button type="button" 
                                            class="btn btn-sm btn-action-btn btn-action-delete" 
                                            title="Eliminar boleta borrador"
                                            onclick="openConfirmDeleteModal({{ $p->id }}, '{{ addslashes($emp?->first_name . ' ' . $emp?->last_name) }}')">
                                        <i class="bi bi-trash"></i>
                                    </button>
                                    <form id="form-delete-{{ $p->id }}" method="POST" action="{{ route('payslips.destroy', $p->id) }}" class="d-none">
                                        @csrf @method('DELETE')
                                    </form>
                                @endif
                            </div>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="8" class="p-0 border-0">
                            <div class="payslips-empty-state text-center p-5">
                                <div class="payslips-empty-icon mb-3 shadow-xs">
                                    <i class="bi bi-file-earmark-x"></i>
                                </div>
                                <h3 class="h5 fw-bold text-body mb-2">No hay boletas registradas para este periodo</h3>
                                <p class="text-secondary small mb-4 mx-auto" style="max-width: 440px;">
                                    Aún no se han emitido boletas de pago para el mes de <strong class="text-body">{{ $currentMonthName }} {{ $currentYear }}</strong> o los filtros aplicados no arrojaron resultados.
                                </p>
                                <div class="d-flex align-items-center justify-content-center flex-wrap" style="gap: 12px;">
                                    <button type="button" 
                                            class="btn btn-primary d-inline-flex align-items-center"
                                            style="height: 38px; border-radius: 6px; font-weight: 600; font-size: 0.85rem; padding: 0 1.25rem;"
                                            data-bs-toggle="modal" 
                                            data-bs-target="#uploadSinglePayslipModal">
                                        <i class="bi bi-cloud-arrow-up-fill" style="margin-right: 8px;"></i>
                                        <span>Subir boleta individual</span>
                                    </button>
                                    <button type="button" 
                                            class="btn btn-outline-primary d-inline-flex align-items-center"
                                            style="height: 38px; border-radius: 6px; font-weight: 600; font-size: 0.85rem; padding: 0 1.25rem;"
                                            data-bs-toggle="modal" 
                                            data-bs-target="#bulkUploadPayslipModal">
                                        <i class="bi bi-files" style="margin-right: 8px;"></i>
                                        <span>Carga masiva</span>
                                    </button>
                                </div>
                            </div>
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    @if($payslips->hasPages())
        <div class="card-footer bg-transparent p-3 border-top d-flex align-items-center justify-content-between flex-wrap gap-2">
            <div class="small text-muted">
                Mostrando {{ $payslips->firstItem() }} a {{ $payslips->lastItem() }} de {{ $payslips->total() }} registros
            </div>
            <div class="payslip-ajax-pagination">
                {{ $payslips->links() }}
            </div>
        </div>
    @endif
</div>
