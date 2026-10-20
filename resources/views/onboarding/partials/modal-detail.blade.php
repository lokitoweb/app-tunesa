<div class="modal-header">
    <div class="d-flex align-items-center gap-3 min-w-0">
        @if(!empty($employee->avatar_path))
            <img src="{{ asset('storage/' . $employee->avatar_path) }}" alt="{{ $employee->first_name }}" class="attendance-user-avatar" style="width: 44px; height: 44px;">
        @else
            <span class="attendance-user-initials" style="width: 44px; height: 44px; font-size: 0.95rem;">
                {{ mb_substr($employee->first_name ?? 'U', 0, 1) }}{{ mb_substr($employee->last_name ?? '', 0, 1) }}
            </span>
        @endif
        <div class="min-w-0">
            <div class="d-flex align-items-center gap-2 flex-wrap">
                <h2 class="modal-title fs-5 fw-bold text-body mb-0" id="onbModalTitle">{{ $employee->full_name }}</h2>
                <x-status-badge type="onboarding_status" :value="$process->status" />
            </div>
            <div class="small text-secondary text-truncate mt-0.5">
                {{ label('id_document_type', $employee->document_type) }} {{ $employee->document_number }} · {{ $employee->email }}
                @if($employee->branches->isNotEmpty())
                    · {{ $employee->branches->firstWhere('pivot.is_primary', true)?->name ?? $employee->branches->first()->name }}
                @endif
            </div>
        </div>
    </div>
    <div class="ms-auto d-flex align-items-center gap-2">
        @can('profiles.manage')
            <a href="{{ route('employees.file', $employee->id) }}" class="btn btn-sm btn-outline-primary d-inline-flex align-items-center gap-2 shadow-sm" style="border-radius: 6px; font-weight: 600; font-size: 0.775rem; padding: 6px 13px;" target="_blank">
                <i class="bi bi-folder2-open"></i>
                <span>Ver legajo</span>
            </a>
        @endcan
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar" style="font-size: 0.75rem;"></button>
    </div>
</div>

<div class="modal-body">
    @if($process->status === 'rejected' && $process->rejection_reason)
        <div class="alert alert-danger d-flex align-items-start gap-2 mb-3" style="border-radius: 8px;">
            <i class="bi bi-exclamation-octagon-fill fs-5 flex-shrink-0 mt-0.5"></i>
            <div>
                <strong>Observación enviada:</strong> {{ $process->rejection_reason }}
            </div>
        </div>
    @endif

    @if($process->isApproved())
        <div class="alert alert-success d-flex align-items-start gap-2 mb-3" style="border-radius: 8px;">
            <i class="bi bi-check-circle-fill fs-5 flex-shrink-0 mt-0.5"></i>
            <div>
                Aprobada {{ $process->approved_at ? 'el ' . $process->approved_at->format('d/m/Y h:i a') : '' }}{{ $process->reviewer ? ' por ' . $process->reviewer->first_name . ' ' . $process->reviewer->last_name : '' }}. El colaborador ya puede marcar asistencia.
            </div>
        </div>
    @endif

    {{-- Checklist de Etapas --}}
    <div class="onb-modal-section-card">
        <div class="onb-modal-section-title">
            <span class="d-inline-flex align-items-center">
                <i class="bi bi-list-check text-primary fs-6" style="margin-right: 8px;"></i>
                <span>Etapas de incorporación</span>
            </span>
            @php
                $totalStages = count($stages);
                $completedStages = collect($stages)->where('status', 'completed')->count();
            @endphp
            <span class="badge rounded-pill bg-secondary-subtle text-secondary-emphasis" style="font-size: 0.725rem;">
                {{ $completedStages }} de {{ $totalStages }} completadas
            </span>
        </div>
        <div class="onb-stages-list">
            @foreach($stages as $s)
                <div class="onb-stage-item">
                    <div class="onb-stage-icon-wrap">
                        <i class="bi {{ $s['status'] === 'completed' ? 'bi-check-circle-fill text-success' : ($s['status'] === 'pending' ? 'bi-hourglass-split text-warning' : 'bi-dash-circle text-body-secondary') }}"></i>
                    </div>
                    <div class="flex-grow-1 min-w-0">
                        <div class="d-flex align-items-center gap-2 flex-wrap">
                            <span class="fw-semibold text-body" style="font-size: 0.85rem;">{{ $s['title'] }}</span>
                            <x-status-badge type="onboarding_stage_status" :value="$s['status']" />
                            @unless($s['required'])
                                <span class="badge rounded-pill bg-light text-secondary border" style="font-size: 0.675rem;">Opcional</span>
                            @endunless
                        </div>
                        <div class="small text-secondary mt-0.5" style="font-size: 0.775rem;">{{ $s['description'] }}</div>
                        @if(!empty($s['missing']))
                            <div class="onb-stage-missing text-warning-emphasis">
                                <i class="bi bi-exclamation-triangle-fill text-warning" style="margin-right: 8px;"></i>
                                <div>
                                    <strong>Falta:</strong> {{ implode(', ', $s['missing']) }}
                                </div>
                            </div>
                        @endif
                    </div>
                </div>
            @endforeach
        </div>
    </div>

    {{-- Acciones de Decisión para RRHH --}}
    @if($canAct && !$process->isApproved())
        <div class="onb-modal-section-card mb-0">
            <div class="onb-modal-section-title">
                <span class="d-inline-flex align-items-center">
                    <i class="bi bi-shield-check text-success fs-6" style="margin-right: 8px;"></i>
                    <span>Decisión de RRHH</span>
                </span>
            </div>
            <div class="row g-3">
                <div class="col-12 col-md-6">
                    <div class="onb-action-approve-box h-100 d-flex flex-column justify-content-between">
                        <div>
                            <div class="fw-bold text-success mb-1.5 d-flex align-items-center" style="font-size: 0.875rem;">
                                <i class="bi bi-check2-circle fs-6" style="margin-right: 8px;"></i>
                                <span>Aprobar incorporación</span>
                            </div>
                            <p class="small text-secondary mb-3" style="font-size: 0.775rem; line-height: 1.45;">
                                Habilita al colaborador para comenzar a registrar su asistencia con geolocalización.
                            </p>
                        </div>
                        <form method="POST" action="{{ route('onboarding.approve', $employee->id) }}" onsubmit="return confirm('¿Aprobar la incorporación de {{ e($employee->first_name) }}? Podrá marcar desde ahora.');">
                            @csrf
                            <button type="submit" class="btn btn-sm btn-success w-100 d-inline-flex align-items-center justify-content-center gap-2 shadow-sm" style="border-radius: 6px; font-weight: 600; padding: 8px 16px;">
                                <i class="bi bi-person-check-fill fs-6"></i>
                                <span>Aprobar incorporación</span>
                            </button>
                        </form>
                    </div>
                </div>
                <div class="col-12 col-md-6">
                    <div class="onb-action-reject-box h-100 d-flex flex-column justify-content-between">
                        <form method="POST" action="{{ route('onboarding.reject', $employee->id) }}">
                            @csrf
                            <div class="fw-bold text-danger mb-1.5 d-flex align-items-center" style="font-size: 0.875rem;">
                                <i class="bi bi-person-exclamation fs-6" style="margin-right: 8px;"></i>
                                <span>Observar / Solicitar corrección</span>
                            </div>
                            <label for="modalRejectReason" class="form-label small text-secondary mb-1" style="font-size: 0.75rem;">Qué debe corregir el colaborador:</label>
                            <textarea id="modalRejectReason" name="reason" rows="2" maxlength="500" class="form-control form-control-sm mb-2" placeholder="Ej.: la foto del DNI está borrosa, vuelve a subirla." required style="font-size: 0.775rem; border-radius: 6px; resize: none;"></textarea>
                            <button type="submit" class="btn btn-sm btn-outline-danger w-100 d-inline-flex align-items-center justify-content-center gap-2 shadow-sm" style="border-radius: 6px; font-weight: 600; padding: 7px 16px;">
                                <i class="bi bi-send-exclamation fs-6"></i>
                                <span>Enviar observación</span>
                            </button>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    @endif
</div>

<div class="modal-footer">
    <button type="button" class="btn btn-outline-secondary px-4 py-2" data-bs-dismiss="modal" style="border-radius: 8px; font-weight: 600; font-size: 0.825rem;">Cerrar</button>
</div>
