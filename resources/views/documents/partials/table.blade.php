<div class="table-responsive">
    <table class="table table-hover align-middle mb-0">
        <thead class="bg-surface-elevated text-body-secondary small text-uppercase">
            <tr>
                <th class="ps-3 py-3">Colaborador</th>
                <th class="py-3">Tipo de Documento</th>
                <th class="py-3">Archivo Adjunto</th>
                <th class="py-3">Fecha de Envío</th>
                <th class="py-3">Estado</th>
                <th class="pe-3 py-3 text-end">Acciones</th>
            </tr>
        </thead>
        <tbody>
        @forelse($documents as $doc)
            <tr>
                <td class="ps-3">
                    <div class="d-flex align-items-center gap-2">
                        <span class="rounded-circle d-inline-flex align-items-center justify-content-center bg-primary text-white fw-bold shadow-sm" style="width: 34px; height: 34px; font-size: 0.75rem;">
                            {{ mb_strtoupper(mb_substr($doc->user?->first_name ?? 'C', 0, 1) . mb_substr($doc->user?->last_name ?? 'O', 0, 1)) }}
                        </span>
                        <div>
                            <a href="{{ route('employees.file', $doc->user_id) }}" class="fw-bold text-body text-decoration-none d-block">
                                {{ $doc->user?->full_name }}
                            </a>
                            <div class="small text-body-secondary">
                                <span class="font-monospace">{{ $doc->user?->employee_code }}</span>
                                @if($doc->user?->document_number)
                                    <span>• DNI: {{ $doc->user?->document_number }}</span>
                                @endif
                            </div>
                            @include('partials.tenant-badge', ['tenantId' => $doc->tenant_id])
                        </div>
                    </div>
                </td>

                <td>
                    <div class="fw-semibold text-body">
                        {{ label('document_type', $doc->type) }}
                    </div>
                    @if($doc->expires_at)
                        <div class="small {{ $doc->expires_at->isPast() ? 'text-danger fw-bold' : ($doc->expires_at->diffInDays(now()) <= 30 ? 'text-warning fw-semibold' : 'text-body-secondary') }}">
                            <i class="bi bi-calendar-event me-1"></i>Vence: {{ $doc->expires_at->format('d/m/Y') }}
                        </div>
                    @endif
                </td>

                <td>
                    <div class="d-flex align-items-center gap-2">
                        <a href="{{ route('documents.open', $doc->id) }}" target="_blank" rel="noopener" class="btn-table-action btn-action-view" title="Abrir y visualizar documento">
                            <i class="bi {{ $doc->isImage() ? 'bi-image-fill' : 'bi-file-earmark-pdf-fill text-danger' }}"></i>
                        </a>
                        <div class="min-w-0">
                            <div class="small text-truncate fw-medium text-body" style="max-width: 180px;" title="{{ $doc->original_name }}">
                                {{ $doc->original_name }}
                            </div>
                            <div class="small text-body-secondary" style="font-size: 0.72rem;">
                                {{ number_format($doc->size_bytes / 1024, 0) }} KB
                            </div>
                        </div>
                    </div>
                </td>

                <td class="small text-body-secondary text-nowrap">
                    <div><i class="bi bi-clock me-1 text-primary"></i>{{ $doc->created_at->format('d/m/Y') }}</div>
                    <div style="font-size: 0.75rem;">{{ $doc->created_at->format('h:i a') }}</div>
                </td>

                <td>
                    <x-status-badge type="document_status" :value="$doc->status" />
                    @if($doc->rejection_reason)
                        <div class="small text-danger mt-1 p-1 px-2 rounded-2 bg-danger-subtle border border-danger-subtle" style="max-width: 200px; font-size: 0.72rem;">
                            <i class="bi bi-info-circle me-1"></i>{{ $doc->rejection_reason }}
                        </div>
                    @endif
                    @if($doc->reviewer)
                        <div class="small text-body-secondary mt-1" style="font-size: 0.72rem;">
                            Por {{ $doc->reviewer->first_name }}
                        </div>
                    @endif
                </td>

                <td class="pe-3 text-end text-nowrap">
                    @if($doc->status === 'pending')
                        <form method="POST" action="{{ route('documents.review', $doc->id) }}" class="d-inline-flex align-items-center gap-2 justify-content-end">
                            @csrf
                            @if($doc->type === 'dni')
                                <input type="date" name="expires_at" class="form-control form-control-sm" style="max-width: 135px; height: 32px; font-size: 0.78rem;" title="Fecha de vencimiento (opcional)" aria-label="Vencimiento del documento">
                            @endif

                            <button name="decision" value="approved" type="submit" class="btn-table-action btn-action-view" title="Aprobar documento">
                                <i class="bi bi-check-lg fs-6 text-success"></i>
                            </button>

                            <button type="button" class="btn-table-action btn-table-delete" data-bs-toggle="modal" data-bs-target="#rejectModal"
                                    data-action="{{ route('documents.review', $doc->id) }}" data-label="{{ label('document_type', $doc->type) }} de {{ $doc->user?->full_name }}" title="Rechazar documento con motivo">
                                <i class="bi bi-x-lg fs-6"></i>
                            </button>
                        </form>
                    @else
                        <span class="small text-body-secondary d-inline-flex align-items-center">
                            <i class="bi bi-check-all me-1 text-success"></i>{{ $doc->reviewed_at?->format('d/m/Y') }}
                        </span>
                    @endif
                </td>
            </tr>
        @empty
            <tr>
                <td colspan="6" class="p-5 text-center">
                    <div class="d-inline-flex align-items-center justify-content-center p-3 rounded-circle bg-primary-subtle text-primary mb-3" style="width: 64px; height: 64px;">
                        <i class="bi bi-folder-check fs-2"></i>
                    </div>
                    <h6 class="fw-bold text-body mb-2">No hay documentos en esta bandeja</h6>
                    <p class="text-body-secondary small mb-0">No se encontraron legajos que coincidan con los filtros seleccionados.</p>
                </td>
            </tr>
        @endforelse
        </tbody>
    </table>
</div>

@if($documents->total() > 0)
    <div class="d-flex flex-column flex-sm-row justify-content-between align-items-center gap-3 p-3 border-top">
        <div class="text-body-secondary small">
            Mostrando
            <span class="fw-bold text-body">{{ $documents->firstItem() ?? 0 }}</span>
            -
            <span class="fw-bold text-body">{{ $documents->lastItem() ?? 0 }}</span>
            de
            <span class="fw-bold text-body">{{ $documents->total() }}</span> documentos
        </div>

        @if($documents->hasPages())
            <div class="d-flex align-items-center gap-1">
                @if($documents->onFirstPage())
                    <button type="button" class="btn btn-sm btn-outline-secondary px-2 py-1" style="border-radius: 6px;" disabled>
                        <i class="bi bi-chevron-left me-1"></i>Anterior
                    </button>
                @else
                    <button type="button" class="btn btn-sm btn-outline-secondary px-2 py-1 ajax-page-btn" style="border-radius: 6px;" data-page="{{ $documents->currentPage() - 1 }}">
                        <i class="bi bi-chevron-left me-1"></i>Anterior
                    </button>
                @endif

                @foreach($documents->getUrlRange(max(1, $documents->currentPage() - 2), min($documents->lastPage(), $documents->currentPage() + 2)) as $page => $url)
                    <button type="button" class="btn btn-sm {{ $page == $documents->currentPage() ? 'btn-primary fw-bold' : 'btn-outline-secondary' }} ajax-page-btn"
                            style="width: 32px; height: 32px; border-radius: 6px; padding: 0;" data-page="{{ $page }}">
                        {{ $page }}
                    </button>
                @endforeach

                @if($documents->hasMorePages())
                    <button type="button" class="btn btn-sm btn-outline-secondary px-2 py-1 ajax-page-btn" style="border-radius: 6px;" data-page="{{ $documents->currentPage() + 1 }}">
                        Siguiente<i class="bi bi-chevron-right ms-1"></i>
                    </button>
                @else
                    <button type="button" class="btn btn-sm btn-outline-secondary px-2 py-1" style="border-radius: 6px;" disabled>
                        Siguiente<i class="bi bi-chevron-right ms-1"></i>
                    </button>
                @endif
            </div>
        @endif
    </div>
@endif
