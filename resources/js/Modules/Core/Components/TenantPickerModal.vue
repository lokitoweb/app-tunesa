<script setup>
import { computed, ref, watch } from 'vue';
import { useTenantPicker } from '@/Modules/Core/Composables/useTenantPicker';

// "¿En qué empresa?" del Super Admin en la vista global (ver useTenantPicker)
const { pickerState, activeTenants, choose, close } = useTenantPicker();

const selected = ref(null);
const search = ref('');

watch(() => pickerState.value.show, (show) => {
    if (show) {
        selected.value = null;
        search.value = '';
    }
});

const filtered = computed(() => {
    const q = search.value.trim().toLowerCase();
    return activeTenants().filter((t) => !q || `${t.company_name} ${t.ruc ?? ''}`.toLowerCase().includes(q));
});

function submit() {
    if (selected.value) {
        choose(selected.value);
    }
}
</script>

<template>
    <div
        v-if="pickerState.show"
        class="tenant-picker-overlay d-flex align-items-center justify-content-center"
        role="dialog"
        aria-modal="true"
        aria-labelledby="vueTenantPickerTitle"
        @click.self="close"
        @keydown.esc="close"
    >
        <div class="tenant-picker-card shadow-lg p-4 animate-scale-in">
            <div class="d-flex align-items-center mb-3">
                <div class="modal-header-icon bg-primary-subtle text-primary" style="margin-right: 14px !important;">
                    <i class="bi bi-buildings-fill"></i>
                </div>
                <div>
                    <h3 id="vueTenantPickerTitle" class="h6 fw-bold text-body mb-0">{{ pickerState.title }}</h3>
                    <p class="text-secondary small mb-0">{{ pickerState.message || 'Elige la empresa en la que vas a crear.' }}</p>
                </div>
            </div>

            <div v-if="activeTenants().length > 6" class="tenant-picker-search mb-3">
                <i class="bi bi-search"></i>
                <input v-model="search" type="search" class="form-control" placeholder="Buscar empresa o RUC..." autocomplete="off" aria-label="Buscar empresa">
            </div>

            <div class="tenant-picker-list" role="radiogroup" aria-labelledby="vueTenantPickerTitle">
                <label v-for="t in filtered" :key="t.id" class="tenant-picker-option">
                    <input v-model="selected" type="radio" name="vue_tenant_pick" :value="t.id">
                    <span class="tenant-picker-avatar">{{ t.company_name.charAt(0).toUpperCase() }}</span>
                    <span class="tenant-picker-text">
                        <span class="tenant-picker-name">{{ t.company_name }}</span>
                        <span v-if="t.ruc" class="tenant-picker-sub">RUC {{ t.ruc }}</span>
                    </span>
                    <i class="bi bi-check-circle-fill tenant-picker-check"></i>
                </label>
            </div>

            <p class="text-secondary small mt-3 mb-3">
                <i class="bi bi-info-circle me-1"></i>
                El panel pasará a administrar esa empresa. Para volver al consolidado elige "Todas las empresas" arriba.
            </p>

            <div class="d-flex justify-content-end" style="gap: 12px !important;">
                <button type="button" class="btn btn-outline-secondary px-4" style="border-radius: 8px; font-weight: 500; font-size: 0.85rem;" @click="close">Cancelar</button>
                <button type="button" class="btn btn-primary d-inline-flex align-items-center px-4" style="border-radius: 8px; font-weight: 600; font-size: 0.85rem; gap: 8px !important;" :disabled="!selected" @click="submit">
                    <span>Continuar</span>
                    <i class="bi bi-arrow-right"></i>
                </button>
            </div>
        </div>
    </div>
</template>

<style scoped>
.tenant-picker-overlay {
    position: fixed;
    inset: 0;
    background: rgba(15, 23, 42, 0.65);
    backdrop-filter: blur(4px);
    z-index: 1090;
    padding: 1rem;
}

.tenant-picker-card {
    width: 100%;
    max-width: 480px;
    max-height: calc(100vh - 2rem);
    overflow-y: auto;
    border: 1px solid var(--border-color);
}

.animate-scale-in {
    animation: scaleIn 0.15s cubic-bezier(0.16, 1, 0.3, 1) forwards;
}

@keyframes scaleIn {
    from { opacity: 0; transform: scale(0.95); }
    to { opacity: 1; transform: scale(1); }
}
</style>
