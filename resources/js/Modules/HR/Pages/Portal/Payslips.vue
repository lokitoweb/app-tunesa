<script setup>
import PortalLayout from '@/Modules/HR/Layouts/PortalLayout.vue';

defineProps({ payslips: { type: Array, default: () => [] } });
</script>

<template>
    <PortalLayout title="Mis boletas">
        <h1 class="portal-title">Mis boletas</h1>
        <div class="card">
            <ul class="list-plain">
                <li v-for="p in payslips" :key="p.id" class="list-plain-item flex-wrap">
                    <span class="list-plain-icon"><i class="bi bi-file-earmark-text"></i></span>
                    <div class="min-w-0 flex-grow-1">
                        <div class="cell-title text-capitalize">{{ p.period_label }} <span v-if="!p.is_read" class="status-pill tone-brand ms-1">Nueva</span></div>
                        <div class="cell-sub">{{ p.title }}</div>
                    </div>
                    <a :href="`/mi-espacio/boletas/${p.id}/descargar`" class="btn btn-sm btn-light border"><i class="bi bi-download me-1"></i>Descargar PDF</a>
                </li>
                <li v-if="!payslips.length">
                    <div class="dash-empty">
                        <span class="dash-empty-icon"><i class="bi bi-file-earmark-text"></i></span>
                        <p class="dash-empty-title">Aún no tienes boletas publicadas</p>
                        <p class="dash-empty-text">Cuando la empresa publique tu boleta aparecerá aquí.</p>
                    </div>
                </li>
            </ul>
        </div>
    </PortalLayout>
</template>
