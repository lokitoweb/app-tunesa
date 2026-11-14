{{--
    Traslado a otra empresa del grupo (EmployeeTransferService): aviso que enlaza los dos registros
    y el diálogo para trasladar. Parámetro: $employee.
--}}
@php
    $transferOut = \App\Modules\HR\Models\EmployeeTransfer::with('toTenant:id,company_name')->where('from_user_id', $employee->id)->latest('id')->first();
    $transferIn  = \App\Modules\HR\Models\EmployeeTransfer::with('fromTenant:id,company_name')->where('to_user_id', $employee->id)->latest('id')->first();
    $canTransfer = auth()->user()->can('employees.transfer') && $employee->status === 'active' && $employee->tenant_id;
    $companies = $canTransfer
        ? \App\Modules\Core\Models\Tenant::where('status', 'active')->where('id', '!=', $employee->tenant_id)->orderBy('company_name')->get(['id', 'company_name'])
        : collect();
    $roles = collect(['employee', 'supervisor', 'logistics', 'accountant', 'manager', 'hr_manager', 'company_admin'])
        ->filter(fn ($r) => auth()->user()->canAssignRole($r));
@endphp

@if($transferOut)
    <div class="alert alert-info d-flex align-items-start gap-2 small" role="status">
        <i class="bi bi-arrow-left-right mt-1"></i>
        <div>
            <strong>Trasladado a {{ $transferOut->toTenant?->company_name ?? 'otra empresa' }}</strong> desde el {{ $transferOut->effective_date->format('d/m/Y') }}.
            Aquí queda su historia hasta su cese ({{ $employee->termination_date?->format('d/m/Y') }}).
            @can('settlements.manage')
                <a href="{{ route('settlements.create', $employee->id) }}" class="alert-link ms-1">Calcular su liquidación</a>
            @endcan
        </div>
    </div>
@endif
@if($transferIn)
    <div class="alert alert-info d-flex align-items-start gap-2 small" role="status">
        <i class="bi bi-arrow-left-right mt-1"></i>
        <div>
            <strong>Trasladado desde {{ $transferIn->fromTenant?->company_name ?? 'otra empresa' }}</strong>; ingresó aquí el {{ $transferIn->effective_date->format('d/m/Y') }}.
            Su historia anterior está en esa empresa.
        </div>
    </div>
@endif

@if($canTransfer && $companies->isNotEmpty())
    <div class="modal fade" id="transferModal" tabindex="-1" aria-labelledby="transferModalTitle" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered modal-lg modal-dialog-scrollable">
            <form method="POST" action="{{ route('employees.transfer', $employee->id) }}" class="modal-content" id="transferForm"
                  data-confirm="Se registrará su cese en esta empresa y se creará su registro en la nueva. Su historia se queda aquí."
                  data-confirm-title="¿Trasladar a {{ $employee->first_name }}?" data-confirm-button="Trasladar">
                @csrf
                <div class="modal-header">
                    <div>
                        <h2 class="modal-title h5 mb-0" id="transferModalTitle"><i class="bi bi-arrow-left-right me-2 text-primary"></i>Trasladar a otra empresa</h2>
                        <small class="text-secondary">{{ $employee->first_name }} {{ $employee->last_name }} · {{ $employee->tenant?->company_name }}</small>
                    </div>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button>
                </div>
                <div class="modal-body">
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label for="t_tenant" class="form-label small fw-semibold">Empresa de destino <span class="text-danger">*</span></label>
                            <select id="t_tenant" name="tenant_id" class="form-select" required>
                                <option value="">Elige la empresa…</option>
                                @foreach($companies as $c)
                                    <option value="{{ $c->id }}" @selected(old('tenant_id') == $c->id)>{{ $c->company_name }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label for="t_date" class="form-label small fw-semibold">Primer día en la nueva empresa <span class="text-danger">*</span></label>
                            <input type="date" id="t_date" name="effective_date" class="form-control" required max="{{ today()->toDateString() }}"
                                   value="{{ old('effective_date', today()->toDateString()) }}" @if($employee->hire_date) min="{{ $employee->hire_date->copy()->addDay()->toDateString() }}" @endif>
                            <div class="form-text">Su cese aquí será el día anterior.</div>
                        </div>
                        <div class="col-md-6">
                            <label for="t_branch" class="form-label small fw-semibold">Sede <span class="text-danger">*</span></label>
                            <select id="t_branch" name="branch_id" class="form-select" required disabled>
                                <option value="">Primero elige la empresa</option>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label for="t_position" class="form-label small fw-semibold">Cargo</label>
                            <select id="t_position" name="position_id" class="form-select" disabled>
                                <option value="">Primero elige la empresa</option>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label for="t_role" class="form-label small fw-semibold">Rol en el sistema <span class="text-danger">*</span></label>
                            <select id="t_role" name="role" class="form-select" required>
                                @foreach($roles as $r)
                                    <option value="{{ $r }}" @selected(old('role', $roles->contains($employee->role) ? $employee->role : 'employee') === $r)>{{ label('user_role', $r) }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label for="t_salary" class="form-label small fw-semibold">Remuneración básica</label>
                            <div class="field-icon">
                                <span class="field-prefix">S/</span>
                                <input type="number" id="t_salary" name="base_salary" class="form-control" min="0" step="0.01" value="{{ old('base_salary', $employee->base_salary) }}">
                            </div>
                        </div>
                        <div class="col-12">
                            <div class="form-check">
                                <input type="hidden" name="copy_documents" value="0">
                                <input class="form-check-input" type="checkbox" id="t_docs" name="copy_documents" value="1" @checked(old('copy_documents', '1') === '1')>
                                <label class="form-check-label small" for="t_docs">
                                    <strong>Copiar sus documentos personales</strong> (DNI, CV, certificados, domicilio, licencia).
                                    Los contratos y exámenes ocupacionales se quedan en esta empresa.
                                </label>
                            </div>
                            <div class="form-check mt-2">
                                <input type="hidden" name="onboarding_required" value="0">
                                <input class="form-check-input" type="checkbox" id="t_onboarding" name="onboarding_required" value="1" @checked(old('onboarding_required') === '1')>
                                <label class="form-check-label small" for="t_onboarding">
                                    <strong>Pedir la incorporación en la nueva empresa.</strong> Sin marcar, puede marcar asistencia desde su primer día.
                                </label>
                            </div>
                        </div>
                        <div class="col-12">
                            <label for="t_notes" class="form-label small fw-semibold">Motivo u observaciones</label>
                            <textarea id="t_notes" name="notes" rows="2" maxlength="500" class="form-control" placeholder="Ej.: reasignación a la operación de turismo">{{ old('notes') }}</textarea>
                        </div>
                    </div>
                    <div class="alert alert-light border small mt-3 mb-0">
                        <i class="bi bi-info-circle me-1"></i>
                        Pasan sus datos personales, su cuenta bancaria y su sistema de pensiones; RR. HH. de la nueva empresa verifica la cuenta.
                        Conserva su correo y contraseña: en la app y la web entrará directo a la nueva empresa.
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-light border" data-bs-dismiss="modal">Cancelar</button>
                    <button type="submit" class="btn btn-primary"><i class="bi bi-arrow-left-right me-1"></i>Trasladar</button>
                </div>
            </form>
        </div>
    </div>
    <script>
        (() => {
            const company = document.getElementById('t_tenant');
            const fill = (select, items, empty) => {
                select.innerHTML = `<option value="">${empty}</option>` + items.map((i) => `<option value="${i.id}">${Panel.esc(i.name)}</option>`).join('');
                select.disabled = !items.length && select.required;
            };
            company.addEventListener('change', async () => {
                const branch = document.getElementById('t_branch');
                const position = document.getElementById('t_position');
                if (!company.value) { fill(branch, [], 'Primero elige la empresa'); fill(position, [], 'Primero elige la empresa'); return; }
                branch.disabled = position.disabled = true;
                try {
                    const res = await fetch(@json(route('employees.transfer.options', $employee->id)) + '?tenant_id=' + encodeURIComponent(company.value), { headers: { Accept: 'application/json' } });
                    const data = await res.json();
                    fill(branch, data.branches || [], data.branches?.length ? 'Elige la sede…' : 'Esa empresa no tiene sedes activas');
                    fill(position, data.positions || [], 'Sin cargo del catálogo');
                    position.disabled = false;
                } catch (e) {
                    Panel.toast?.('No se pudieron cargar las sedes de esa empresa. Intenta de nuevo.', 'danger');
                }
            });
            @if($errors->hasAny(['tenant_id', 'effective_date', 'branch_id', 'role', 'position_id', 'base_salary']))
                document.addEventListener('DOMContentLoaded', () => bootstrap.Modal.getOrCreateInstance(document.getElementById('transferModal')).show());
            @endif
        })();
    </script>
@endif
