<script setup>
// Empresa del registro; solo se pinta en la vista global del Super Admin
import { usePage } from '@inertiajs/vue3';

defineProps({ tenantId: { type: Number, default: null } });
const page = usePage();
</script>

<template>
    <span v-if="page.props.globalView && tenantId" class="badge rounded-pill text-bg-primary bg-opacity-10 text-primary-emphasis border border-primary-subtle fw-medium tenant-badge" title="Empresa">
        {{ page.props.tenantNames[tenantId] ?? `Empresa #${tenantId}` }}
    </span>
</template>
