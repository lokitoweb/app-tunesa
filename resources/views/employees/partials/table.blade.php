<div class="emp-table-wrapper" id="empTableWrapper">
    <div class="table-responsive emp-table-responsive">
        <table class="emp-table" id="empDataTable">
            <thead>
                <tr>
                    <th style="min-width: 220px; width: 28%;">Colaborador</th>
                    <th style="min-width: 130px; width: 14%;">Código / Doc</th>
                    <th style="min-width: 100px; width: 12%;">Rol</th>
                    <th style="min-width: 140px; width: 16%;">Sucursales</th>
                    <th style="min-width: 110px; width: 12%;">Turno</th>
                    <th style="min-width: 90px; width: 8%;">Estado</th>
                    <th style="min-width: 100px; width: 10%;" class="text-end">Acciones</th>
                </tr>
            </thead>
            <tbody>
                @forelse($employees as $emp)
                    @php
                        $activeShift = $emp->employeeShifts->where('is_active', true)->first();
                        $initials = mb_strtoupper(mb_substr($emp->first_name, 0, 1) . mb_substr($emp->last_name, 0, 1));
                        $primaryBranch = $emp->branches->firstWhere('pivot.is_primary', true) ?? $emp->branches->first();
                        $extraBranchesCount = $emp->branches->count() - ($primaryBranch ? 1 : 0);
                        $allBranchNames = $emp->branches->pluck('name')->implode(', ');
                    @endphp
                    <tr>
                        {{-- Colaborador --}}
                        <td>
                            <div class="emp-user-cell">
                                <div class="emp-avatar-box">
                                    @if($emp->avatar_path)
                                        <img src="{{ asset('storage/' . $emp->avatar_path) }}" alt="{{ $emp->first_name }}" class="emp-avatar-img"
                                             onerror="this.style.display='none'; if(this.nextElementSibling) this.nextElementSibling.style.display='flex';">
                                        <span class="emp-avatar-initials" style="display: none;">{{ $initials ?: 'U' }}</span>
                                    @else
                                        <span class="emp-avatar-initials">{{ $initials ?: 'U' }}</span>
                                    @endif
                                </div>
                                <div class="emp-user-info min-w-0">
                                    <div class="emp-user-name text-truncate" title="{{ $emp->first_name }} {{ $emp->last_name }}">
                                        {{ $emp->first_name }} {{ $emp->last_name }}
                                    </div>
                                    <div class="emp-user-sub text-truncate" title="{{ $emp->email }} · {{ $emp->position ?? 'Sin cargo' }}">
                                        <span>{{ $emp->email }}</span>
                                        <span class="opacity-50">·</span>
                                        <span>{{ $emp->position ?? 'Sin cargo' }}</span>
                                    </div>
                                    @if($emp->tenant_id)
                                        <div class="emp-tenant-wrap">
                                            @include('partials.tenant-badge', ['tenantId' => $emp->tenant_id])
                                        </div>
                                    @endif
                                </div>
                            </div>
                        </td>

                        {{-- Código y Documento --}}
                        <td>
                            <div class="emp-code-pill">{{ $emp->employee_code ?? 'EMP-' . $emp->id }}</div>
                            <div class="emp-doc-row" title="{{ label('id_document_type', $emp->document_type) }}: {{ $emp->document_number ?: 'Sin registro' }}">
                                <i class="bi bi-person-vcard opacity-75"></i>
                                <span>{{ strtoupper($emp->document_type ?? 'DOC') }}: {{ $emp->document_number ?: 'Sin reg.' }}</span>
                            </div>
                        </td>

                        {{-- Rol --}}
                        <td>
                            <span class="emp-role-badge">
                                <i class="bi bi-shield-check"></i>
                                <span>{{ label('user_role', $emp->role) }}</span>
                            </span>
                        </td>

                        {{-- Sucursales --}}
                        <td>
                            <div class="d-flex align-items-center flex-wrap gap-1">
                                @if($primaryBranch)
                                    <span class="emp-branch-badge is-primary" title="{{ $primaryBranch->name }} (Sede Principal)">
                                        <i class="bi bi-geo-alt-fill"></i>
                                        <span class="text-truncate" style="max-width: 110px;">{{ $primaryBranch->name }}</span>
                                        <i class="bi bi-star-fill star-icon" title="Principal"></i>
                                    </span>
                                    @if($extraBranchesCount > 0)
                                        <span class="emp-branch-extra" title="{{ $allBranchNames }}">
                                            +{{ $extraBranchesCount }}
                                        </span>
                                    @endif
                                @else
                                    <span class="emp-branch-empty">
                                        <span class="badge-dot"></span>
                                        <span>Sin sede</span>
                                    </span>
                                @endif
                            </div>
                        </td>

                        {{-- Turno --}}
                        <td>
                            @if($activeShift?->shift)
                                <div class="emp-shift-tag" title="{{ $activeShift->shift->name }} ({{ hora($activeShift->shift->start_time) }} - {{ hora($activeShift->shift->end_time) }})">
                                    <i class="bi bi-clock-history"></i>
                                    <span class="text-truncate" style="max-width: 120px;">{{ $activeShift->shift->name }}</span>
                                </div>
                            @else
                                <div class="emp-shift-empty">
                                    <i class="bi bi-dash-circle"></i>
                                    <span>No asignado</span>
                                </div>
                            @endif
                        </td>

                        {{-- Estado --}}
                        <td>
                            <div class="d-inline-flex align-items-center gap-1 status-container" id="status-container-{{ $emp->id }}">
                                <x-status-badge type="user_status" :value="$emp->status" />
                                @if($emp->isLocked())
                                    <span class="emp-locked-badge" title="Bloqueado por intentos fallidos de login">
                                        <i class="bi bi-lock-fill"></i>
                                    </span>
                                @endif
                            </div>
                        </td>

                        {{-- Acciones --}}
                        <td class="text-end text-nowrap">
                            <div class="emp-table-actions">
                                <button type="button" class="btn-table-action btn-action-edit" data-id="{{ $emp->id }}" data-url="{{ route('employees.edit', $emp->id) }}" title="Editar datos" aria-label="Editar a {{ $emp->first_name }}">
                                    <i class="bi bi-pencil"></i>
                                </button>
                                @can('profiles.manage')
                                    <button type="button" class="btn-table-action btn-action-view" data-id="{{ $emp->id }}" data-url="{{ route('employees.file', $emp->id) }}" title="Expediente y datos de pago" aria-label="Expediente de {{ $emp->first_name }}">
                                        <i class="bi bi-folder2-open"></i>
                                    </button>
                                @endcan
                                @if((auth()->user()->can('attendance.view_all') || auth()->user()->can('attendance.view_team')) && in_array($emp->role, \App\Modules\Core\Models\User::CLOCKING_ROLES, true))
                                    <a href="{{ route('attendance.calendar.person', $emp->id) }}" class="btn-table-action" title="Calendario mensual de asistencia" aria-label="Calendario de {{ $emp->first_name }}">
                                        <i class="bi bi-calendar3"></i>
                                    </a>
                                @endif
                                @if($emp->isLocked())
                                    <form method="POST" action="{{ route('employees.unlock', $emp->id) }}" class="d-inline">
                                        @csrf @method('PATCH')
                                        <button type="submit" class="btn-table-action text-warning" title="Desbloquear acceso de {{ $emp->first_name }}" aria-label="Desbloquear a {{ $emp->first_name }}">
                                            <i class="bi bi-unlock"></i>
                                        </button>
                                    </form>
                                @endif
                                <button type="button" 
                                        class="btn-table-action btn-action-toggle {{ $emp->status === 'active' ? 'is-active' : 'is-inactive' }}"
                                        data-id="{{ $emp->id }}"
                                        data-url="{{ route('employees.toggle-status', $emp->id) }}"
                                        data-status="{{ $emp->status === 'active' ? 'active' : 'inactive' }}"
                                        data-name="{{ $emp->first_name }}"
                                        title="{{ $emp->status === 'active' ? 'Acceso activo — Clic para desactivar' : 'Acceso desactivado — Clic para activar' }}"
                                        aria-label="{{ $emp->status === 'active' ? 'Desactivar acceso de' : 'Activar colaborador' }} {{ $emp->first_name }}">
                                    <i class="bi {{ $emp->status === 'active' ? 'bi-check-circle-fill' : 'bi-slash-circle' }}"></i>
                                </button>
                            </div>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="7">
                            <div class="empty-state py-5 text-center">
                                <i class="bi bi-people fs-2 text-muted d-block mb-2"></i>
                                <div class="fw-bold" style="font-size: 0.9rem; color: var(--text-primary);">No se encontraron colaboradores</div>
                                <div class="small text-muted mt-1" style="font-size: 0.775rem;">Intenta cambiar los términos de búsqueda o filtros seleccionados.</div>
                            </div>
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    {{-- Paginador AJAX Ejecutivo --}}
    <div class="emp-footer">
        <div class="emp-footer-info">
            @if($employees->total() > 0)
                Mostrando <strong class="text-emphasis">{{ $employees->firstItem() }}</strong> a <strong class="text-emphasis">{{ $employees->lastItem() }}</strong> de <strong class="text-emphasis">{{ $employees->total() }}</strong> colaboradores
            @else
                Total: 0 colaboradores
            @endif
        </div>
        @if($employees->hasPages())
            <nav class="emp-pagination-nav" aria-label="Paginación de colaboradores">
                <ul class="emp-pagination">
                    {{-- Botón Anterior --}}
                    @if($employees->onFirstPage())
                        <li class="emp-page-item disabled"><span class="emp-page-link"><i class="bi bi-chevron-left"></i></span></li>
                    @else
                        <li class="emp-page-item"><a href="javascript:void(0)" class="emp-page-link ajax-page-link" data-page="{{ $employees->currentPage() - 1 }}" aria-label="Página anterior"><i class="bi bi-chevron-left"></i></a></li>
                    @endif

                    {{-- Elementos de página --}}
                    @php
                        $cur = $employees->currentPage();
                        $last = $employees->lastPage();
                        $delta = 2;
                        $left = max(1, $cur - $delta);
                        $right = min($last, $cur + $delta);
                    @endphp

                    @if($left > 1)
                        <li class="emp-page-item"><a href="javascript:void(0)" class="emp-page-link ajax-page-link" data-page="1">1</a></li>
                        @if($left > 2)
                            <li class="emp-page-item disabled"><span class="emp-page-link emp-page-dots">…</span></li>
                        @endif
                    @endif

                    @for($i = $left; $i <= $right; $i++)
                        @if($i == $cur)
                            <li class="emp-page-item active" aria-current="page"><span class="emp-page-link">{{ $i }}</span></li>
                        @else
                            <li class="emp-page-item"><a href="javascript:void(0)" class="emp-page-link ajax-page-link" data-page="{{ $i }}">{{ $i }}</a></li>
                        @endif
                    @endfor

                    @if($right < $last)
                        @if($right < $last - 1)
                            <li class="emp-page-item disabled"><span class="emp-page-link emp-page-dots">…</span></li>
                        @endif
                        <li class="emp-page-item"><a href="javascript:void(0)" class="emp-page-link ajax-page-link" data-page="{{ $last }}">{{ $last }}</a></li>
                    @endif

                    {{-- Botón Siguiente --}}
                    @if($employees->hasMorePages())
                        <li class="emp-page-item"><a href="javascript:void(0)" class="emp-page-link ajax-page-link" data-page="{{ $employees->currentPage() + 1 }}" aria-label="Página siguiente"><i class="bi bi-chevron-right"></i></a></li>
                    @else
                        <li class="emp-page-item disabled"><span class="emp-page-link"><i class="bi bi-chevron-right"></i></span></li>
                    @endif
                </ul>
            </nav>
        @endif
    </div>
</div>
