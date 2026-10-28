<script setup>
import PortalLayout from '@/Modules/HR/Layouts/PortalLayout.vue';

defineProps({ payslips: { type: Array, default: () => [] } });
</script>

<template>
    <PortalLayout title="Mis boletas">
        <h1 class="h4 fw-bold mt-2 mb-3">Mis boletas</h1>
        <div class="card shadow-sm">
            <ul class="list-group list-group-flush">
                <li v-for="p in payslips" :key="p.id" class="list-group-item d-flex flex-wrap justify-content-between align-items-center gap-2">
                    <div>
                        <div class="fw-semibold text-capitalize">{{ p.period_label }}</div>
                        <div class="small text-body-secondary">{{ p.title }}<span v-if="!p.is_read" class="badge bg-primary-subtle text-primary-emphasis ms-2">Nueva</span></div>
                    </div>
                    <a :href="`/mi-espacio/boletas/${p.id}/descargar`" class="btn btn-sm btn-outline-primary"><i class="bi bi-download me-1"></i>Descargar PDF</a>
                </li>
                <li v-if="!payslips.length" class="list-group-item text-center text-body-secondary py-4">Aún no tienes boletas publicadas.</li>
            </ul>
        </div>
    </PortalLayout>
</template>
