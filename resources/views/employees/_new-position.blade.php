{{--
    "+ Nuevo cargo" junto al selector de cargo (alta y ficha del colaborador).
    Crea el cargo en el catálogo sin salir del formulario y lo deja elegido.
    Parámetros: $select (id del <select> de cargo), $areaSelect (id del <select> de área, opcional).
--}}
<div class="new-position" data-new-position data-select="{{ $select }}" data-area-select="{{ $areaSelect ?? '' }}" data-url="{{ route('employees.positions.store') }}">
    <button type="button" class="btn btn-link btn-sm p-0 mt-1 text-decoration-none" data-new-position-toggle>
        <i class="bi bi-plus-circle me-1"></i>Nuevo cargo
    </button>
    <div class="border rounded-3 p-2 mt-2 d-none" data-new-position-panel style="background: var(--bs-tertiary-bg, #f8fafc);">
        <label class="form-label small fw-semibold mb-1" for="{{ $select }}_new_name">Nombre del cargo nuevo</label>
        <div class="d-flex gap-2">
            {{-- Sin name: no viaja con el formulario del colaborador --}}
            <input type="text" id="{{ $select }}_new_name" class="form-control form-control-sm" maxlength="100" placeholder="Ej.: Asesor de ventas" data-new-position-name>
            <button type="button" class="btn btn-sm btn-primary text-nowrap" data-new-position-save>Agregar</button>
        </div>
        <div class="small text-danger mt-1 d-none" data-new-position-error></div>
        <div class="form-text">Queda en el catálogo con el área elegida en este formulario. Para editarlo o desactivarlo, ve a Organización.</div>
    </div>
</div>

@once
<script>
document.addEventListener('click', async (event) => {
    const root = event.target.closest('[data-new-position]');
    if (!root) return;
    const panel = root.querySelector('[data-new-position-panel]');
    const nameInput = root.querySelector('[data-new-position-name]');
    const error = root.querySelector('[data-new-position-error]');

    if (event.target.closest('[data-new-position-toggle]')) {
        panel.classList.toggle('d-none');
        if (!panel.classList.contains('d-none')) nameInput.focus();
        return;
    }
    if (!event.target.closest('[data-new-position-save]')) return;

    const select = document.getElementById(root.dataset.select);
    const area = root.dataset.areaSelect ? document.getElementById(root.dataset.areaSelect) : null;
    const button = event.target.closest('[data-new-position-save]');
    error.classList.add('d-none');
    button.disabled = true;
    try {
        const response = await fetch(root.dataset.url, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'Accept': 'application/json',
                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
            },
            body: JSON.stringify({ name: nameInput.value, department_id: area?.value || null }),
        });
        const json = await response.json().catch(() => ({}));
        if (!response.ok) {
            throw new Error(json.errors ? Object.values(json.errors)[0][0] : (json.message || 'No se pudo crear el cargo.'));
        }
        const p = json.data;
        let option = select.querySelector(`option[value="${p.id}"]`);
        if (!option) {
            option = new Option(p.name, p.id);
            option.dataset.department = p.department_id ?? '';
            option.dataset.roaming = p.is_roaming ? '1' : '0';
            select.appendChild(option);
        }
        select.value = String(p.id);
        select.dispatchEvent(new Event('change', { bubbles: true }));
        nameInput.value = '';
        panel.classList.add('d-none');
        window.Panel?.toast(json.message, 'success');
    } catch (e) {
        error.textContent = e.message;
        error.classList.remove('d-none');
    } finally {
        button.disabled = false;
    }
});
// Enter en el nombre agrega el cargo sin enviar el formulario del colaborador
document.addEventListener('keydown', (event) => {
    if (event.key === 'Enter' && event.target.matches('[data-new-position-name]')) {
        event.preventDefault();
        event.target.closest('[data-new-position]').querySelector('[data-new-position-save]').click();
    }
});
</script>
@endonce
