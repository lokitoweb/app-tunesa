<script setup>
import { computed } from 'vue';
import { Link, router, useForm } from '@inertiajs/vue3';
import AppLayout from '@/Modules/Core/Layouts/AppLayout.vue';
import Pagination from '@/Modules/Core/Components/Pagination.vue';
import TenantBadge from '@/Modules/Core/Components/TenantBadge.vue';

const props = defineProps({
    shifts: Array,
    assignments: Object,
    employees: Array,
    branches: Array,
    today: String,
    canAssign: Boolean,
});

const DAYS = ['Dom', 'Lun', 'Mar', 'Mié', 'Jue', 'Vie', 'Sáb'];
const activeShifts = computed(() => props.shifts.filter((s) => s.is_active));

const assign = useForm({
    shift_id: activeShifts.value[0]?.id ?? null,
    branch_id: props.branches[0]?.id ?? null,
    user_ids: [],
    effective_from: props.today,
    effective_until: '',
    rotation_anchor_date: '',
    replace_current: true,
});

function submitAssign() {
    assign.post('/shifts/assign', { preserveScroll: true, onSuccess: () => assign.reset('user_ids', 'effective_until', 'rotation_anchor_date') });
}

function destroy(shift) {
    if (confirm(`¿Eliminar el turno ${shift.name}?`)) router.delete(`/shifts/${shift.id}`, { preserveScroll: true });
}

function unassign(assignment) {
    if (confirm('¿Finalizar esta asignación hoy?')) router.delete(`/shifts/assignments/${assignment.id}`, { preserveScroll: true });
}
</script>

<template>
    <AppLayout title="Turnos y horarios">
        <div class="page-header">
            <div>
                <h1>Turnos y horarios</h1>
                <p>Turnos fijos y rotativos, tolerancia de tardanza y asignación de colaboradores.</p>
            </div>
            <Link href="/shifts/create" class="btn btn-primary"><i class="bi bi-plus-lg me-1"></i>Nuevo turno</Link>
        </div>

        <div class="card border-0 mb-4">
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead>
                        <tr>
                            <th>Turno</th>
                            <th>Tipo</th>
                            <th>Horario</th>
                            <th>Días / ciclo</th>
                            <th>Tolerancia</th>
                            <th>Asignados</th>
                            <th>Estado</th>
                            <th class="text-end">Acciones</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="shift in shifts" :key="shift.id">
                            <td>
                                <div class="d-flex align-items-center gap-2">
                                    <span class="rounded-circle d-inline-block flex-shrink-0" :style="{ width: '10px', height: '10px', background: shift.color }"></span>
                                    <div>
                                        <div class="fw-semibold">{{ shift.name }}</div>
                                        <div v-if="shift.code" class="small text-body-secondary">{{ shift.code }}</div>
                                    </div>
                                </div>
                                <TenantBadge :tenant-id="shift.tenant_id" />
                            </td>
                            <td><span class="badge text-bg-light border">{{ shift.type === 'rotating' ? 'Rotativo' : 'Fijo' }}</span></td>
                            <td class="text-nowrap">
                                {{ shift.start_time }} – {{ shift.end_time }}
                                <span v-if="shift.crosses_midnight" class="small text-body-secondary">(+1 día)</span>
                            </td>
                            <td>
                                <template v-if="shift.type === 'rotating'">{{ shift.rotation_work_days }} × {{ shift.rotation_rest_days }}</template>
                                <template v-else>{{ shift.working_days.map((d) => DAYS[d] ?? d).join(', ') }}</template>
                            </td>
                            <td>{{ shift.grace_period_minutes }} min</td>
                            <td>{{ shift.employees_count }}</td>
                            <td>
                                <span v-if="shift.is_active" class="badge rounded-pill bg-success-subtle text-success-emphasis border border-success-subtle">Activo</span>
                                <span v-else class="badge rounded-pill bg-secondary-subtle text-secondary-emphasis border border-secondary-subtle">Inactivo</span>
                            </td>
                            <td class="text-end text-nowrap">
                                <Link :href="`/shifts/${shift.id}/edit`" class="btn btn-sm btn-outline-secondary me-1"><i class="bi bi-pencil"></i> Editar</Link>
                                <button type="button" class="btn btn-sm btn-outline-danger" :aria-label="`Eliminar ${shift.name}`" @click="destroy(shift)"><i class="bi bi-trash"></i></button>
                            </td>
                        </tr>
                        <tr v-if="!shifts.length">
                            <td colspan="8"><div class="empty-state"><i class="bi bi-calendar-x"></i>Aún no hay turnos. Crea el primero.</div></td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>

        <div class="row g-3">
            <div v-if="canAssign" class="col-12 col-xl-5">
                <div class="card border-0 h-100">
                    <div class="card-header border-0 pt-3"><h2 class="h6 fw-semibold mb-0">Asignar turno</h2></div>
                    <div class="card-body">
                        <form @submit.prevent="submitAssign">
                            <div class="mb-3">
                                <label for="assign_shift" class="form-label">Turno</label>
                                <select id="assign_shift" v-model="assign.shift_id" class="form-select" :class="{ 'is-invalid': assign.errors.shift_id }" required>
                                    <option v-for="shift in activeShifts" :key="shift.id" :value="shift.id">{{ shift.name }} ({{ shift.start_time }}–{{ shift.end_time }})</option>
                                </select>
                            </div>
                            <div class="mb-3">
                                <label for="assign_branch" class="form-label">Sucursal</label>
                                <select id="assign_branch" v-model="assign.branch_id" class="form-select" :class="{ 'is-invalid': assign.errors.branch_id }" required>
                                    <option v-for="branch in branches" :key="branch.id" :value="branch.id">{{ branch.name }}</option>
                                </select>
                            </div>
                            <div class="mb-3">
                                <label for="assign_users" class="form-label">Colaboradores</label>
                                <select id="assign_users" v-model="assign.user_ids" class="form-select" :class="{ 'is-invalid': assign.errors.user_ids }" multiple size="6" required aria-describedby="assign_users_help">
                                    <option v-for="e in employees" :key="e.id" :value="e.id">{{ e.last_name }}, {{ e.first_name }}{{ e.employee_code ? ` · ${e.employee_code}` : '' }}</option>
                                </select>
                                <div id="assign_users_help" class="form-text">Mantén Ctrl (o Cmd) para elegir varios.</div>
                            </div>
                            <div class="row g-2 mb-3">
                                <div class="col-sm-4">
                                    <label for="effective_from" class="form-label small">Desde</label>
                                    <input id="effective_from" v-model="assign.effective_from" type="date" class="form-control" required>
                                </div>
                                <div class="col-sm-4">
                                    <label for="effective_until" class="form-label small">Hasta (opcional)</label>
                                    <input id="effective_until" v-model="assign.effective_until" type="date" class="form-control" :class="{ 'is-invalid': assign.errors.effective_until }">
                                </div>
                                <div class="col-sm-4">
                                    <label for="rotation_anchor_date" class="form-label small">Inicio de ciclo</label>
                                    <input id="rotation_anchor_date" v-model="assign.rotation_anchor_date" type="date" class="form-control">
                                </div>
                            </div>
                            <button class="btn btn-primary w-100" :disabled="assign.processing">Asignar</button>
                            <div class="form-text">La asignación vigente de cada colaborador se cierra el día anterior.</div>
                        </form>
                    </div>
                </div>
            </div>

            <div class="col-12" :class="{ 'col-xl-7': canAssign }">
                <div class="card border-0 h-100">
                    <div class="card-header border-0 pt-3"><h2 class="h6 fw-semibold mb-0">Asignaciones vigentes</h2></div>
                    <div class="table-responsive">
                        <table class="table table-hover align-middle mb-0">
                            <thead><tr><th>Colaborador</th><th>Turno</th><th>Sucursal</th><th>Vigencia</th><th></th></tr></thead>
                            <tbody>
                                <tr v-for="a in assignments.data" :key="a.id">
                                    <td class="fw-semibold">{{ a.employee }} <TenantBadge :tenant-id="a.tenant_id" /></td>
                                    <td><span class="rounded-circle d-inline-block me-1" :style="{ width: '8px', height: '8px', background: a.color }"></span>{{ a.shift }}</td>
                                    <td>{{ a.branch }}</td>
                                    <td class="text-nowrap small">{{ a.from }} → {{ a.until ?? 'indefinido' }}</td>
                                    <td class="text-end">
                                        <button v-if="canAssign" type="button" class="btn btn-sm btn-outline-secondary" @click="unassign(a)">Finalizar</button>
                                    </td>
                                </tr>
                                <tr v-if="!assignments.data.length">
                                    <td colspan="5"><div class="empty-state">Sin asignaciones vigentes.</div></td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                    <div v-if="assignments.last_page > 1" class="card-footer bg-transparent"><Pagination :paginator="assignments" /></div>
                </div>
            </div>
        </div>
    </AppLayout>
</template>
