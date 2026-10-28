{{-- Rol propio de la empresa (Configuración › Roles y permisos). Solo lista los basados en el rol elegido. --}}
@php
    $customRoles = \App\Modules\Core\Models\Role::query()->where('tenant_id', current_tenant_id_or_null())
        ->whereNotNull('base_role')->orderBy('name')->get(['id', 'name', 'base_role']);
@endphp
@if($customRoles->isNotEmpty())
    <div class="col-12 col-md-6" id="{{ $prefix }}_custom_role_wrap">
        <label for="{{ $prefix }}_custom_role_id" class="emp-form-label">Rol propio de la empresa</label>
        <select id="{{ $prefix }}_custom_role_id" name="custom_role_id" class="emp-form-select">
            <option value="">— Ninguno (permisos del rol base) —</option>
            @foreach($customRoles as $customRole)
                <option value="{{ $customRole->id }}" data-base="{{ $customRole->base_role }}">{{ $customRole->name }}</option>
            @endforeach
        </select>
        <div class="invalid-feedback" id="{{ $prefix === 'edit' ? 'edit_err' : 'err' }}_custom_role_id"></div>
        <div class="emp-form-hint">Ajusta los permisos sobre el rol elegido.</div>
    </div>
    @once
        <script>
            // Al cambiar el rol base solo quedan los roles propios basados en él
            window.filterCustomRoles = function (prefix) {
                const role = document.getElementById(`${prefix}_role`);
                const select = document.getElementById(`${prefix}_custom_role_id`);
                if (!role || !select) return;
                let any = false;
                [...select.options].forEach((o) => {
                    if (!o.value) return;
                    o.hidden = o.dataset.base !== role.value;
                    any = any || !o.hidden;
                });
                if (select.selectedOptions[0]?.hidden) select.value = '';
                document.getElementById(`${prefix}_custom_role_wrap`).classList.toggle('d-none', !any);
            };
            document.addEventListener('change', (e) => {
                const m = /^(modal|edit)_role$/.exec(e.target.id || '');
                if (m) window.filterCustomRoles(m[1]);
            });
            document.addEventListener('DOMContentLoaded', () => ['modal', 'edit'].forEach((p) => window.filterCustomRoles(p)));
        </script>
    @endonce
@endif
