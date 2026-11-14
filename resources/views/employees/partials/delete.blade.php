{{--
    Zona de acciones peligrosas: eliminar a un colaborador registrado por error (EmployeeDeletion).
    Solo con el permiso employees.delete. Parámetro: $employee.
--}}
@if(auth()->user()->can('employees.delete') && ! $employee->isSuperAdmin() && (int) $employee->id !== (int) auth()->id()
    && (auth()->user()->isSuperAdmin() || auth()->user()->canManage($employee)))
    @php
        $had = app(\App\Modules\HR\Services\EmployeeDeletion::class)->footprint($employee);
        $confirmWith = $employee->document_number ?: $employee->employee_code;
        $deleteErrors = $errors->getBag('deleteEmployee');
    @endphp
    <section class="card border-danger-subtle shadow-sm mt-4" aria-labelledby="dangerZoneTitle">
        <div class="card-body d-flex flex-wrap align-items-center gap-3">
            <div class="flex-grow-1">
                <h2 class="h6 fw-bold text-danger mb-1" id="dangerZoneTitle"><i class="bi bi-exclamation-octagon me-1"></i>Zona de acciones peligrosas</h2>
                <p class="small text-body-secondary mb-0">Elimina a {{ $employee->first_name }} y todo lo suyo. Úsalo solo si se registró por error; si dejó de trabajar, desactívalo o regístrale su cese.</p>
            </div>
            <button type="button" class="btn btn-outline-danger" data-bs-toggle="modal" data-bs-target="#deleteEmployeeModal">
                <i class="bi bi-trash3 me-1"></i>Eliminar colaborador
            </button>
        </div>
    </section>

    <div class="modal fade" id="deleteEmployeeModal" tabindex="-1" aria-labelledby="deleteEmployeeTitle" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <form method="POST" action="{{ route('employees.destroy', $employee->id) }}" class="modal-content">
                @csrf
                @method('DELETE')
                <div class="modal-header">
                    <h2 class="modal-title h5 text-danger mb-0" id="deleteEmployeeTitle"><i class="bi bi-trash3 me-2"></i>¿Eliminar a {{ $employee->first_name }} {{ $employee->last_name }}?</h2>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button>
                </div>
                <div class="modal-body">
                    <p class="small mb-2">Se eliminará <strong>definitivamente</strong>, sin forma de deshacerlo, junto con:</p>
                    <ul class="small mb-3">
                        <li>{{ $had['asistencia'] }} jornadas de asistencia y {{ $had['marcaciones'] }} marcaciones (con sus selfies)</li>
                        <li>{{ $had['documentos'] }} documentos del legajo</li>
                        <li>{{ $had['solicitudes'] }} solicitudes y {{ $had['boletas'] }} boletas</li>
                        <li>Su acceso a la web y a la app</li>
                    </ul>
                    @if(array_sum($had) > 0)
                        <div class="alert alert-warning small py-2">Tiene historia registrada. Si trabajó de verdad, conviene <strong>desactivarlo</strong> o registrar su cese en lugar de eliminarlo.</div>
                    @endif
                    <label for="confirmDocument" class="form-label small fw-semibold">Para confirmar, escribe su número de documento: <span class="font-monospace">{{ $confirmWith }}</span></label>
                    <input type="text" id="confirmDocument" name="confirm_document" class="form-control @if($deleteErrors->has('confirm_document')) is-invalid @endif"
                           autocomplete="off" inputmode="text" required data-expected="{{ $confirmWith }}">
                    @if($deleteErrors->has('confirm_document'))<div class="invalid-feedback">{{ $deleteErrors->first('confirm_document') }}</div>@endif
                    <p class="small text-body-secondary mt-2 mb-0">Queda constancia en la bitácora: quién lo eliminó, cuándo y qué tenía.</p>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-light border" data-bs-dismiss="modal">Cancelar</button>
                    <button type="submit" class="btn btn-danger" id="confirmDeleteBtn" disabled><i class="bi bi-trash3 me-1"></i>Eliminar definitivamente</button>
                </div>
            </form>
        </div>
    </div>
    <script>
        (() => {
            const input = document.getElementById('confirmDocument');
            const button = document.getElementById('confirmDeleteBtn');
            const norm = (v) => String(v || '').replace(/\s+/g, '').toLowerCase();
            input.addEventListener('input', () => { button.disabled = norm(input.value) !== norm(input.dataset.expected); });
            @if($deleteErrors->any())
                document.addEventListener('DOMContentLoaded', () => bootstrap.Modal.getOrCreateInstance(document.getElementById('deleteEmployeeModal')).show());
            @endif
        })();
    </script>
@endif
