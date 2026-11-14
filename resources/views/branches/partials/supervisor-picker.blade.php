{{--
    Supervisores de la sede: selección múltiple con casillas (mañana, tarde o varios a la vez).
    Sin ninguno marcado, la sede la ven todos los supervisores de la empresa.
    Parámetros: $pickerId, $supervisors (usuarios con rol que supervisa), $selected (ids), $inputClass (estilo del campo)
    JS: window.supervisorPickers[$pickerId].set([ids]) / .tenant(id) (vista global: solo los de esa empresa)
--}}
@php
    $selected = array_map('intval', (array) ($selected ?? []));
@endphp
<div class="supervisor-picker dropdown" id="{{ $pickerId }}" data-empty="Sin supervisor asignado">
    <input type="hidden" name="supervisors_sent" value="1">
    <button type="button" class="{{ $inputClass ?? 'form-select' }} text-start text-truncate supervisor-picker-toggle" data-bs-toggle="dropdown"
            data-bs-auto-close="outside" data-bs-popper-config='{"strategy":"fixed"}' aria-expanded="false" aria-haspopup="listbox"
            aria-describedby="{{ $pickerId }}-help">
        <span class="supervisor-picker-label">Sin supervisor asignado</span>
    </button>
    <div class="dropdown-menu p-2 shadow supervisor-picker-menu" role="listbox" aria-multiselectable="true">
        <input type="search" class="form-control form-control-sm mb-2 supervisor-picker-search" placeholder="Buscar por nombre…" aria-label="Buscar supervisor" autocomplete="off">
        <div class="supervisor-picker-options">
            @forelse($supervisors as $s)
                <label class="dropdown-item d-flex align-items-center gap-2 rounded py-2 supervisor-picker-option" data-tenant="{{ $s->tenant_id }}"
                       data-name="{{ \Illuminate\Support\Str::lower($s->first_name . ' ' . $s->last_name) }}">
                    <input type="checkbox" class="form-check-input m-0" name="supervisor_ids[]" value="{{ $s->id }}" @checked(in_array((int) $s->id, $selected, true))>
                    <span class="text-truncate">{{ $s->first_name }} {{ $s->last_name }}</span>
                    <span class="ms-auto supervisor-picker-role">{{ label('user_role', $s->role) }}</span>
                </label>
            @empty
                <div class="small text-body-secondary px-2 py-1">No hay usuarios activos con rol Supervisor, RR. HH. o Administrador.</div>
            @endforelse
            <div class="small text-body-secondary px-2 py-1 d-none supervisor-picker-none">Nadie coincide con la búsqueda.</div>
        </div>
        <div class="border-top mt-2 pt-2 d-flex justify-content-between align-items-center">
            <button type="button" class="btn btn-sm btn-link text-decoration-none px-1 supervisor-picker-clear">Quitar todos</button>
            <span class="small text-body-secondary supervisor-picker-count"></span>
        </div>
    </div>
    <div class="form-text small" id="{{ $pickerId }}-help">Puedes marcar varios (turno mañana y tarde, o a la vez). Sin ninguno, la sede la ven todos los supervisores de la empresa.</div>
</div>

@once
    <script>
        window.supervisorPickers = window.supervisorPickers || {};
        window.initSupervisorPicker = function (root) {
            const boxes = () => [...root.querySelectorAll('input[name="supervisor_ids[]"]')];
            const label = root.querySelector('.supervisor-picker-label');
            const count = root.querySelector('.supervisor-picker-count');
            const search = root.querySelector('.supervisor-picker-search');
            const none = root.querySelector('.supervisor-picker-none');
            let tenantId = null;

            const refresh = () => {
                const names = boxes().filter((b) => b.checked).map((b) => b.closest('label').querySelector('.text-truncate').textContent.trim());
                label.textContent = names.length ? names.join(', ') : root.dataset.empty;
                label.title = label.textContent;
                count.textContent = names.length ? `${names.length} seleccionado${names.length > 1 ? 's' : ''}` : 'La ven todos los supervisores';
            };
            const filter = () => {
                const q = (search.value || '').trim().toLocaleLowerCase('es');
                let shown = 0;
                root.querySelectorAll('.supervisor-picker-option').forEach((opt) => {
                    const ofTenant = tenantId === null || opt.dataset.tenant === String(tenantId);
                    const visible = ofTenant && (!q || opt.dataset.name.includes(q));
                    opt.classList.toggle('d-none', !visible);
                    if (!ofTenant) opt.querySelector('input').checked = false;
                    if (visible) shown++;
                });
                none.classList.toggle('d-none', shown > 0);
                refresh();
            };

            root.addEventListener('change', refresh);
            search.addEventListener('input', filter);
            root.querySelector('.supervisor-picker-clear').addEventListener('click', () => { boxes().forEach((b) => (b.checked = false)); refresh(); });
            // El menú flota (posición fija, para salir del modal) con el ancho exacto del campo
            const toggle = root.querySelector('.supervisor-picker-toggle');
            const menu = root.querySelector('.supervisor-picker-menu');
            root.addEventListener('show.bs.dropdown', () => { menu.style.width = `${Math.max(toggle.offsetWidth, 280)}px`; });
            root.addEventListener('shown.bs.dropdown', () => search.focus());

            window.supervisorPickers[root.id] = {
                set(ids) { const wanted = (ids || []).map(String); boxes().forEach((b) => (b.checked = wanted.includes(b.value))); search.value = ''; filter(); },
                tenant(id) { tenantId = id ? String(id) : null; filter(); },
            };
            refresh();
        };
    </script>
@endonce
<script>initSupervisorPicker(document.getElementById(@json($pickerId)));</script>
