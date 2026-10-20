<script setup>
// Empresa del registro; solo se pinta en la vista global del Super Admin
import { usePage } from '@inertiajs/vue3';

defineProps({ tenantId: { type: Number, default: null } });
const page = usePage();
</script>

<template>
    <span v-if="page.props.globalView && tenantId" class="tenant-badge" title="Empresa">
        <i class="bi bi-buildings"></i>
        <span>{{ page.props.tenantNames[tenantId] ?? `Empresa #${tenantId}` }}</span>
    </span>
</template>
