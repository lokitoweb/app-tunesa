<script setup>
import { ref, computed, onMounted } from 'vue';
import { Link, router, useForm, usePage } from '@inertiajs/vue3';
import AppLayout from '@/Modules/Core/Layouts/AppLayout.vue';
import TenantBadge from '@/Modules/Core/Components/TenantBadge.vue';
import AudiencePicker from '@/Modules/Core/Components/AudiencePicker.vue';
import ModernDatePicker from '@/Modules/Core/Components/ModernDatePicker.vue';
import { useConfirm } from '@/Modules/Core/Composables/useConfirm';
import { useTenantPicker } from '@/Modules/Core/Composables/useTenantPicker';

const page = usePage();

const props = defineProps({
    benefits: {
        type: Object,
        required: true,
    },
    categories: {
        type: Array,
        default: () => [],
    },
    category_icons: {
        type: Object,
        default: () => ({}),
    },
    statuses: {
        type: Object,
        default: () => ({}),
    },
    totals: {
        type: Object,
        default: () => ({ published: 0, draft: 0, expired: 0, archived: 0, total: 0 }),
    },
    filters: {
        type: Object,
        default: () => ({ status: '', category: null, search: '' }),
    },
    audience: {
        type: Object,
        default: () => ({ types: {}, branches: [], departments: [], positions: [], employees: [] }),
    },
});

const { ask } = useConfirm();
const { ensureTenant, consumeIntent } = useTenantPicker();

// Tras elegir empresa en la vista global, reabre el formulario que se pidió
onMounted(() => {
    if (consumeIntent('beneficio')) openCreateBenefitModal();
    if (consumeIntent('categorias')) openCategoriesModal();
});

// Datos brutos de beneficios (desde el paginador de servidor)
const rawList = computed(() => props.benefits?.data || []);

// Filtros 100% internos reactivos (sin mutar URL ni recargar página)
const selectedStatus = ref(props.filters.status || 'all');
const searchQuery = ref(props.filters.search || '');
const selectedCategory = ref(props.filters.category ? String(props.filters.category) : '');

// Paginación interna reactiva
const currentPage = ref(1);
const perPage = ref(9); // 3x3 tarjetas por página

const STATUS_CONFIG = {
    published: {
        label: 'Vigente',
        badgeClass: 'bg-success-subtle text-success border border-success-subtle',
        icon: 'bi-check-circle-fill',
    },
    draft: {
        label: 'Borrador',
        badgeClass: 'bg-warning-subtle text-warning-emphasis border border-warning-subtle',
        icon: 'bi-pencil-square',
    },
    archived: {
        label: 'Archivado',
        badgeClass: 'bg-secondary-subtle text-secondary-emphasis border border-secondary-subtle',
        icon: 'bi-archive-fill',
    },
};

// Conteos reactivos para los tabs superiores
const publishedCount = computed(() => rawList.value.filter((b) => b.status === 'published' && !b.expired).length);
const draftCount = computed(() => rawList.value.filter((b) => b.status === 'draft').length);
const expiredCount = computed(() => rawList.value.filter((b) => b.expired).length);
const archivedCount = computed(() => rawList.value.filter((b) => b.status === 'archived').length);

// Filtrado reactivo en memoria
const filteredBenefits = computed(() => {
    return rawList.value.filter((b) => {
        // Filtro por Estado / Vigencia
        if (selectedStatus.value && selectedStatus.value !== 'all') {
            if (selectedStatus.value === 'expired') {
                if (!b.expired) return false;
            } else if (selectedStatus.value === 'published') {
                if (b.status !== 'published' || b.expired) return false;
            } else if (b.status !== selectedStatus.value) {
                return false;
            }
        }

        // Filtro por Categoría
        if (selectedCategory.value) {
            const catId = Number(selectedCategory.value);
            if (b.category?.id && b.category.id !== catId) return false;
            if (!b.category && selectedCategory.value !== '') return false;
        }

        // Filtro por Búsqueda Predictiva
        if (searchQuery.value.trim()) {
            const q = searchQuery.value.toLowerCase().trim();
            const matchesTitle = b.title?.toLowerCase().includes(q);
            const matchesPartner = b.partner?.toLowerCase().includes(q);
            const matchesCategory = b.category?.name?.toLowerCase().includes(q);
            const matchesHighlight = b.highlight?.toLowerCase().includes(q);
            if (!matchesTitle && !matchesPartner && !matchesCategory && !matchesHighlight) return false;
        }

        return true;
    });
});

// Paginación interna
const totalPages = computed(() => Math.max(1, Math.ceil(filteredBenefits.value.length / perPage.value)));

const paginatedBenefits = computed(() => {
    const start = (currentPage.value - 1) * perPage.value;
    return filteredBenefits.value.slice(start, start + perPage.value);
});

function setStatusFilter(key) {
    selectedStatus.value = key;
    currentPage.value = 1;
}

function handleSearchInput() {
    currentPage.value = 1;
}

function handleCategoryChange() {
    currentPage.value = 1;
}

function resetFilters() {
    selectedStatus.value = 'all';
    searchQuery.value = '';
    selectedCategory.value = '';
    currentPage.value = 1;
}

function goToPage(page) {
    if (page >= 1 && page <= totalPages.value) {
        currentPage.value = page;
    }
}

async function setStatus(b, status) {
    const isPublishing = status === 'published';
    const isArchiving = status === 'archived';

    const confirmed = await ask({
        title: isPublishing ? '¿Publicar beneficio?' : (isArchiving ? '¿Archivar beneficio?' : '¿Cambiar estado?'),
        message: isPublishing
            ? `¿Deseas publicar "${b.title}"? Será visible de inmediato en la app móvil para los colaboradores asignados.`
            : `¿Deseas archivar "${b.title}"? Dejará de mostrarse en la app móvil.`,
        confirmText: isPublishing ? 'Sí, publicar' : (isArchiving ? 'Sí, archivar' : 'Confirmar'),
        variant: isPublishing ? 'primary' : 'warning',
        icon: isPublishing ? 'bi bi-send-check-fill' : 'bi bi-archive-fill',
    });

    if (confirmed) {
        router.post(`/benefits/${b.id}/status`, { status }, { preserveScroll: true });
    }
}

async function destroyBenefit(b) {
    const confirmed = await ask({
        title: '¿Eliminar beneficio?',
        message: `¿Estás seguro de eliminar permanentemente "${b.title}"? Esta acción no se puede deshacer.`,
        confirmText: 'Sí, eliminar',
        variant: 'danger',
        icon: 'bi bi-trash3-fill',
    });

    if (confirmed) {
        router.delete(`/benefits/${b.id}`, { preserveScroll: true });
    }
}

// =========================================================================
// MODAL PARA NUEVO BENEFICIO CORPORATIVO
// =========================================================================
const showCreateBenefitModal = ref(false);

const benefitForm = useForm({
    title: '',
    highlight: '',
    partner: '',
    category_id: '',
    summary: '',
    description: '',
    promo_code: '',
    how_to_use: '',
    terms: '',
    url: '',
    contact: '',
    location: '',
    valid_from: '',
    valid_until: '',
    is_featured: false,
    status: 'draft',
    notify: true,
    image: null,
    targets: [{ type: 'all', id: null, value: null }],
});

const imagePreview = ref(null);

function onBenefitImage(e) {
    const file = e.target.files[0] ?? null;
    benefitForm.image = file;
    if (file) {
        imagePreview.value = URL.createObjectURL(file);
    } else {
        imagePreview.value = null;
    }
}

async function openCreateBenefitModal() {
    if (!ensureTenant({ intent: 'beneficio', message: 'Elige la empresa del nuevo beneficio.' })) {
        return;
    }

    benefitForm.reset();
    benefitForm.clearErrors();
    imagePreview.value = null;
    benefitForm.category_id = props.categories.length ? props.categories[0].id : '';
    benefitForm.status = 'draft';
    benefitForm.valid_from = new Date().toISOString().substring(0, 10);
    benefitForm.valid_until = '';
    benefitForm.targets = [{ type: 'all', id: null, value: null }];
    showCreateBenefitModal.value = true;
}

function closeCreateBenefitModal() {
    showCreateBenefitModal.value = false;
    imagePreview.value = null;
}

async function submitBenefit(status) {
    if (status === 'published') {
        const ok = await ask({
            title: '¿Publicar beneficio ahora?',
            message: 'El beneficio se publicará y será visible de inmediato en la app móvil para todo el público asignado.',
            confirmText: 'Publicar ahora',
            variant: 'primary',
            icon: 'bi bi-send-check-fill',
        });
        if (!ok) return;
    }

    benefitForm.status = status;
    benefitForm.post('/benefits', {
        forceFormData: true,
        preserveScroll: true,
        onSuccess: () => {
            closeCreateBenefitModal();
            benefitForm.reset();
        },
    });
}

// =========================================================================
// MODAL PARA GESTIÓN DE CATEGORÍAS
// =========================================================================
const showCategoriesModal = ref(false);

const categoryForm = useForm({
    id: null,
    name: '',
    icon: 'gift',
    color: '#6C4CE0',
    position: null,
});

const DEFAULT_ICONS = {
    gift: 'Regalo',
    tag: 'Etiqueta',
    percent: 'Porcentaje',
    'heart-pulse': 'Salud',
    'cup-hot': 'Comida',
    book: 'Educación',
    film: 'Entretenimiento',
    'piggy-bank': 'Finanzas',
    bag: 'Compras',
    'car-front': 'Transporte',
    airplane: 'Viajes',
    house: 'Hogar',
    bicycle: 'Deporte',
    phone: 'Tecnología',
    people: 'Familia',
    star: 'Destacado',
};

const PRESET_COLORS = ['#6C4CE0', '#E0457B', '#F08C2E', '#2E86DE', '#10AC84', '#0E9F9A', '#DC2626', '#2563EB', '#7C3AED', '#059669', '#D97706', '#475569'];

async function openCategoriesModal() {
    if (!ensureTenant({ intent: 'categorias', message: 'Elige la empresa cuyas categorías quieres administrar.' })) {
        return;
    }

    resetCategoryForm();
    showCategoriesModal.value = true;
}

function closeCategoriesModal() {
    showCategoriesModal.value = false;
    resetCategoryForm();
}

function editCategory(c) {
    categoryForm.id = c.id;
    categoryForm.name = c.name;
    categoryForm.icon = c.icon;
    categoryForm.color = c.color;
    categoryForm.position = c.position;
    categoryForm.clearErrors();
}

function resetCategoryForm() {
    categoryForm.reset();
    categoryForm.id = null;
    categoryForm.clearErrors();
}

function submitCategory() {
    const options = {
        preserveScroll: true,
        onSuccess: () => {
            resetCategoryForm();
        },
    };

    if (categoryForm.id) {
        categoryForm.put(`/benefits/categories/${categoryForm.id}`, options);
    } else {
        categoryForm.post('/benefits/categories', options);
    }
}

async function destroyCategory(c) {
    const confirmed = await ask({
        title: '¿Eliminar categoría?',
        message: `¿Estás seguro de eliminar "${c.name}"? Sus ${c.benefits_count ?? 0} beneficio(s) asociados quedarán sin categoría.`,
        confirmText: 'Sí, eliminar',
        variant: 'danger',
        icon: 'bi bi-trash3-fill',
    });

    if (confirmed) {
        router.delete(`/benefits/categories/${c.id}`, {
            preserveScroll: true,
            onSuccess: () => {
                if (categoryForm.id === c.id) {
                    resetCategoryForm();
                }
            },
        });
    }
}
</script>

<template>
    <AppLayout title="Beneficios Corporativos">
        <div class="benefits-page-wrapper">
            <!-- =========================================================================
                 1. CABECERA EJECUTIVA DEL MÓDULO
                 ========================================================================= -->
            <div class="benefits-header-card p-4 mb-4 shadow-sm">
                <div class="d-flex flex-column flex-md-row justify-content-between align-items-start align-items-md-center gap-3">
                    <div class="d-flex align-items-center gap-3">
                        <div class="benefits-header-icon shadow-sm">
                            <i class="bi bi-gift-fill"></i>
                        </div>
                        <div>
                            <div class="d-flex align-items-center gap-2 mb-1 flex-wrap">
                                <h1 class="h4 fw-bold mb-0 text-body">Beneficios Corporativos</h1>
                                <span class="badge rounded-pill bg-success-subtle text-success border border-success-subtle px-2 py-1 small">
                                    <i class="bi bi-patch-check-fill me-1"></i>App Móvil
                                </span>
                            </div>
                            <p class="text-body-secondary small mb-0">
                                Descuentos, convenios y beneficios exclusivos visibles en la app móvil de los colaboradores.
                            </p>
                        </div>
                    </div>
                    <div class="d-flex flex-wrap gap-2 w-100 w-md-auto justify-content-md-end">
                        <button 
                            type="button" 
                            class="btn btn-outline-secondary px-3 py-2 fw-semibold d-inline-flex align-items-center"
                            style="height: 38px; border-radius: 6px; font-weight: 600; font-size: 0.85rem;"
                            @click="openCategoriesModal"
                        >
                            <i class="bi bi-tags-fill text-primary" style="margin-right: 8px !important;"></i>
                            <span>Categorías</span>
                        </button>
                        <button 
                            type="button" 
                            class="btn btn-primary px-3 py-2 fw-semibold d-inline-flex align-items-center shadow-sm"
                            style="height: 38px; border-radius: 6px; font-weight: 600; font-size: 0.85rem;"
                            @click="openCreateBenefitModal"
                        >
                            <i class="bi bi-plus-lg" style="margin-right: 8px !important;"></i>
                            <span>Nuevo beneficio</span>
                        </button>
                    </div>
                </div>
            </div>

            <!-- =========================================================================
                 2. TARJETAS DE INDICADORES (KPIs EJECUTIVOS)
                 ========================================================================= -->
            <div class="row g-3 mb-4">
                <div class="col-12 col-sm-6 col-xl-3">
                    <div class="benefits-kpi-card p-3 shadow-sm h-100">
                        <div class="d-flex align-items-center gap-3">
                            <div class="benefits-kpi-pill bg-success-subtle text-success">
                                <i class="bi bi-gift-fill"></i>
                            </div>
                            <div>
                                <div class="text-body-secondary small fw-medium text-uppercase" style="letter-spacing: 0.05em; font-size: 0.72rem;">
                                    Vigentes en la App
                                </div>
                                <div class="fs-4 fw-bold text-body lh-1 mt-1">
                                    {{ totals.published ?? publishedCount }}
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="col-12 col-sm-6 col-xl-3">
                    <div class="benefits-kpi-card p-3 shadow-sm h-100">
                        <div class="d-flex align-items-center gap-3">
                            <div class="benefits-kpi-pill bg-warning-subtle text-warning-emphasis">
                                <i class="bi bi-pencil-square"></i>
                            </div>
                            <div>
                                <div class="text-body-secondary small fw-medium text-uppercase" style="letter-spacing: 0.05em; font-size: 0.72rem;">
                                    Borradores
                                </div>
                                <div class="fs-4 fw-bold text-body lh-1 mt-1">
                                    {{ totals.draft ?? draftCount }}
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="col-12 col-sm-6 col-xl-3">
                    <div class="benefits-kpi-card p-3 shadow-sm h-100">
                        <div class="d-flex align-items-center gap-3">
                            <div class="benefits-kpi-pill bg-danger-subtle text-danger">
                                <i class="bi bi-calendar-x-fill"></i>
                            </div>
                            <div>
                                <div class="text-body-secondary small fw-medium text-uppercase" style="letter-spacing: 0.05em; font-size: 0.72rem;">
                                    Vencidos
                                </div>
                                <div class="fs-4 fw-bold text-body lh-1 mt-1">
                                    {{ totals.expired ?? expiredCount }}
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="col-12 col-sm-6 col-xl-3">
                    <div class="benefits-kpi-card p-3 shadow-sm h-100">
                        <div class="d-flex align-items-center gap-3">
                            <div class="benefits-kpi-pill bg-primary-subtle text-primary">
                                <i class="bi bi-collection-fill"></i>
                            </div>
                            <div>
                                <div class="text-body-secondary small fw-medium text-uppercase" style="letter-spacing: 0.05em; font-size: 0.72rem;">
                                    Total Beneficios
                                </div>
                                <div class="fs-4 fw-bold text-body lh-1 mt-1">
                                    {{ totals.total ?? rawList.length }}
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- =========================================================================
                 3. BARRA DE FILTROS 100% INTERNA EN UNA SOLA LÍNEA HORIZONTAL
                 ========================================================================= -->
            <div class="benefits-filter-card p-3 mb-4 shadow-sm">
                <div class="d-flex flex-wrap flex-lg-nowrap align-items-center justify-content-between gap-3">
                    <!-- Segmented Controls por Estado -->
                    <div class="training-segmented-bar flex-shrink-0" role="tablist">
                        <button
                            type="button"
                            class="training-segmented-item"
                            :class="{ 'is-active': selectedStatus === 'all' }"
                            @click="setStatusFilter('all')"
                        >
                            <i class="bi bi-grid-fill"></i>
                            <span>Todos</span>
                            <span class="training-segmented-pill">{{ rawList.length }}</span>
                        </button>
                        <button
                            type="button"
                            class="training-segmented-item"
                            :class="{ 'is-active': selectedStatus === 'published' }"
                            @click="setStatusFilter('published')"
                        >
                            <i class="bi bi-check-circle-fill text-success"></i>
                            <span>Vigentes</span>
                            <span class="training-segmented-pill">{{ publishedCount }}</span>
                        </button>
                        <button
                            type="button"
                            class="training-segmented-item"
                            :class="{ 'is-active': selectedStatus === 'draft' }"
                            @click="setStatusFilter('draft')"
                        >
                            <i class="bi bi-pencil-square text-warning"></i>
                            <span>Borradores</span>
                            <span class="training-segmented-pill">{{ draftCount }}</span>
                        </button>
                        <button
                            type="button"
                            class="training-segmented-item"
                            :class="{ 'is-active': selectedStatus === 'expired' }"
                            @click="setStatusFilter('expired')"
                        >
                            <i class="bi bi-calendar-x-fill text-danger"></i>
                            <span>Vencidos</span>
                            <span class="training-segmented-pill">{{ expiredCount }}</span>
                        </button>
                        <button
                            type="button"
                            class="training-segmented-item"
                            :class="{ 'is-active': selectedStatus === 'archived' }"
                            @click="setStatusFilter('archived')"
                        >
                            <i class="bi bi-archive-fill text-secondary"></i>
                            <span>Archivados</span>
                            <span class="training-segmented-pill">{{ archivedCount }}</span>
                        </button>
                    </div>

                    <!-- Filtros Complementarios: Categoría y Búsqueda EN UNA SOLA LÍNEA HORIZONTAL -->
                    <div class="d-flex align-items-center flex-nowrap" style="gap: 12px; min-width: 0;">
                        <!-- Selector de Categoría -->
                        <div class="modern-input-wrapper flex-shrink-0" style="width: 220px; min-width: 195px;">
                            <i class="bi bi-tag-fill modern-input-icon"></i>
                            <select
                                v-model="selectedCategory"
                                class="modern-input-control"
                                aria-label="Filtrar por categoría"
                                @change="handleCategoryChange"
                            >
                                <option value="">Todas las categorías</option>
                                <option v-for="c in categories" :key="c.id" :value="String(c.id)">{{ c.name }}</option>
                            </select>
                        </div>

                        <!-- Buscador en tiempo real -->
                        <div class="modern-input-wrapper flex-shrink-0" style="width: 280px; min-width: 220px;">
                            <i class="bi bi-search modern-input-icon"></i>
                            <input
                                v-model="searchQuery"
                                type="search"
                                class="modern-input-control"
                                placeholder="Buscar por título o aliado..."
                                aria-label="Buscar beneficios"
                                @input="handleSearchInput"
                            >
                            <button
                                v-if="searchQuery"
                                type="button"
                                class="btn btn-sm text-secondary p-0 me-2 border-0"
                                style="width: 22px; height: 22px;"
                                title="Limpiar búsqueda"
                                @click="searchQuery = ''; handleSearchInput();"
                            >
                                <i class="bi bi-x-circle-fill"></i>
                            </button>
                        </div>
                    </div>
                </div>
            </div>

            <!-- =========================================================================
                 4. GRID DE TARJETAS DE BENEFICIOS
                 ========================================================================= -->
            <div v-if="paginatedBenefits.length > 0" class="row g-4 mb-4">
                <div v-for="b in paginatedBenefits" :key="b.id" class="col-12 col-md-6 col-xl-4">
                    <div class="benefits-benefit-card h-100 shadow-sm d-flex flex-column">
                        <!-- Imagen / Portada con gradiente / fallback de categoría -->
                        <div
                            class="benefits-card-media position-relative"
                            :style="{ background: b.category?.color ? `${b.category.color}15` : 'var(--bs-tertiary-bg)' }"
                        >
                            <img
                                v-if="b.image_url"
                                :src="b.image_url"
                                :alt="b.title"
                                class="benefits-card-img"
                            >
                            <div
                                v-else
                                class="w-100 h-100 d-flex align-items-center justify-content-center fs-1"
                                :style="{ color: b.category?.color ?? 'var(--bs-secondary-color)' }"
                            >
                                <i class="bi" :class="`bi-${b.category?.icon ?? 'gift'}`"></i>
                            </div>

                            <!-- Badges flotantes en portada -->
                            <div class="position-absolute top-0 start-0 m-2 d-flex flex-wrap gap-1">
                                <span v-if="b.highlight" class="badge bg-dark text-white shadow-sm fw-bold px-2 py-1">
                                    {{ b.highlight }}
                                </span>
                            </div>

                            <div class="position-absolute top-0 end-0 m-2 d-flex flex-wrap gap-1">
                                <span v-if="b.is_featured" class="badge bg-warning text-dark shadow-sm fw-bold px-2 py-1 d-inline-flex align-items-center">
                                    <i class="bi bi-star-fill me-1 text-dark"></i>Destacado
                                </span>
                            </div>
                        </div>

                        <!-- Contenido Principal -->
                        <div class="card-body p-3 flex-grow-1 d-flex flex-column">
                            <div class="d-flex justify-content-between align-items-start gap-2 mb-2">
                                <Link
                                    :href="`/benefits/${b.id}/edit`"
                                    class="fw-bold text-body text-decoration-none benefits-card-title line-clamp-2"
                                    :title="b.title"
                                >
                                    {{ b.title }}
                                </Link>
                                <span class="badge rounded-pill flex-shrink-0" :class="STATUS_CONFIG[b.status]?.badgeClass || 'bg-secondary-subtle text-secondary'">
                                    <i class="bi me-1" :class="STATUS_CONFIG[b.status]?.icon"></i>
                                    {{ STATUS_CONFIG[b.status]?.label || b.status }}
                                </span>
                            </div>

                            <!-- Aliado y Categoría -->
                            <div class="d-flex align-items-center gap-2 small text-body-secondary mb-2 flex-wrap">
                                <span v-if="b.partner" class="fw-semibold text-body d-inline-flex align-items-center">
                                    <i class="bi bi-building me-1 text-primary"></i>{{ b.partner }}
                                </span>
                                <span v-if="b.partner && b.category" class="text-muted">•</span>
                                <span
                                    v-if="b.category"
                                    class="badge rounded-pill px-2 py-1"
                                    :style="{ background: `${b.category.color}18`, color: b.category.color, border: `1px solid ${b.category.color}40` }"
                                >
                                    <i class="bi me-1" :class="`bi-${b.category.icon}`"></i>{{ b.category.name }}
                                </span>
                            </div>

                            <!-- Vigencia de Fechas -->
                            <div class="small mt-auto pt-2 d-flex align-items-center justify-content-between text-body-secondary border-top">
                                <span class="d-inline-flex align-items-center" :class="b.expired ? 'text-danger fw-semibold' : 'text-body-secondary'">
                                    <i class="bi me-1" :class="b.expired ? 'bi-calendar-x-fill text-danger' : 'bi-calendar-event text-primary'"></i>
                                    {{ b.valid_until ? (b.expired ? `Venció: ${b.valid_until}` : `Vigente hasta: ${b.valid_until}`) : 'Permanente' }}
                                </span>
                                <TenantBadge :tenant-id="b.tenant_id" />
                            </div>
                        </div>

                        <!-- Barra de Acciones con Botones de Ícono Elegantes (Pure Icon Buttons) -->
                        <div class="benefits-card-footer p-2 px-3 border-top d-flex align-items-center justify-content-between">
                            <span class="small text-body-secondary fw-medium">Acciones</span>
                            <div class="d-flex align-items-center gap-2">
                                <!-- Publicar en App Móvil -->
                                <button
                                    v-if="b.status !== 'published'"
                                    type="button"
                                    class="btn-table-action btn-action-view"
                                    title="Publicar en la app móvil"
                                    @click="setStatus(b, 'published')"
                                >
                                    <i class="bi bi-send-fill"></i>
                                </button>

                                <!-- Archivar -->
                                <button
                                    v-else
                                    type="button"
                                    class="btn-table-action"
                                    title="Archivar beneficio"
                                    @click="setStatus(b, 'archived')"
                                >
                                    <i class="bi bi-archive-fill"></i>
                                </button>

                                <!-- Editar -->
                                <Link
                                    :href="`/benefits/${b.id}/edit`"
                                    class="btn-table-action btn-action-edit"
                                    title="Editar beneficio"
                                >
                                    <i class="bi bi-pencil-fill"></i>
                                </Link>

                                <!-- Eliminar -->
                                <button
                                    type="button"
                                    class="btn-table-action btn-table-delete"
                                    title="Eliminar beneficio"
                                    @click="destroyBenefit(b)"
                                >
                                    <i class="bi bi-trash3-fill"></i>
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Estado Vacío -->
            <div v-else class="benefits-section-card p-5 text-center shadow-sm mb-4">
                <div class="d-inline-flex align-items-center justify-content-center p-4 rounded-circle bg-primary-subtle text-primary mb-3" style="width: 72px; height: 72px;">
                    <i class="bi bi-gift fs-1"></i>
                </div>
                <h5 class="fw-bold text-body mb-2">No se encontraron beneficios</h5>
                <p class="text-body-secondary small mb-3 mx-auto" style="max-width: 460px;">
                    No hay beneficios que coincidan con los filtros aplicados. Puedes restablecer los filtros o crear un nuevo beneficio institucional.
                </p>
                <div class="d-flex justify-content-center gap-2">
                    <button
                        v-if="selectedStatus !== 'all' || searchQuery || selectedCategory"
                        type="button"
                        class="btn btn-outline-secondary px-3 py-2 fw-medium"
                        @click="resetFilters"
                    >
                        <i class="bi bi-arrow-counterclockwise me-1"></i>Restablecer filtros
                    </button>
                    <button
                        type="button"
                        class="btn btn-primary px-3 py-2 fw-semibold d-inline-flex align-items-center shadow-sm"
                        @click="openCreateBenefitModal"
                    >
                        <i class="bi bi-plus-lg me-1"></i>Crear beneficio
                    </button>
                </div>
            </div>

            <!-- =========================================================================
                 5. PAGINACIÓN 100% INTERNA (SIN MODIFICAR URL)
                 ========================================================================= -->
            <div v-if="filteredBenefits.length > 0" class="benefits-pagination-bar d-flex flex-column flex-sm-row justify-content-between align-items-center gap-3 py-3 px-2">
                <div class="text-body-secondary small">
                    Mostrando
                    <span class="fw-bold text-body">{{ ((currentPage - 1) * perPage) + 1 }}</span>
                    -
                    <span class="fw-bold text-body">{{ Math.min(currentPage * perPage, filteredBenefits.length) }}</span>
                    de
                    <span class="fw-bold text-body">{{ filteredBenefits.length }}</span> beneficios
                </div>

                <div v-if="totalPages > 1" class="d-flex align-items-center gap-1">
                    <button
                        type="button"
                        class="btn btn-sm btn-outline-secondary px-2 py-1"
                        style="border-radius: 6px;"
                        :disabled="currentPage === 1"
                        @click="goToPage(currentPage - 1)"
                    >
                        <i class="bi bi-chevron-left me-1"></i>Anterior
                    </button>

                    <button
                        v-for="page in totalPages"
                        :key="page"
                        type="button"
                        class="btn btn-sm"
                        :class="currentPage === page ? 'btn-primary fw-bold' : 'btn-outline-secondary'"
                        style="width: 32px; height: 32px; border-radius: 6px; padding: 0;"
                        @click="goToPage(page)"
                    >
                        {{ page }}
                    </button>

                    <button
                        type="button"
                        class="btn btn-sm btn-outline-secondary px-2 py-1"
                        style="border-radius: 6px;"
                        :disabled="currentPage === totalPages"
                        @click="goToPage(currentPage + 1)"
                    >
                        Siguiente<i class="bi bi-chevron-right ms-1"></i>
                    </button>
                </div>
            </div>
        </div>

        <!-- =========================================================================
             MODAL 1: NUEVO BENEFICIO CORPORATIVO (Elegante y Escalable)
             ========================================================================= -->
        <div
            v-if="showCreateBenefitModal"
            class="modal fade show d-block"
            tabindex="-1"
            role="dialog"
            aria-modal="true"
            style="background: rgba(15, 23, 42, 0.65); backdrop-filter: blur(4px); z-index: 1055;"
            @click.self="closeCreateBenefitModal"
        >
            <div class="modal-dialog modal-lg modal-dialog-centered modal-dialog-scrollable">
                <div class="modal-content border-0 shadow-lg" style="border-radius: 14px; overflow: hidden;">
                    <!-- Modal Header -->
                    <div class="modal-header border-bottom p-3 p-md-4" style="background: var(--bg-surface);">
                        <div class="d-flex align-items-center" style="gap: 14px !important;">
                            <div style="width: 44px; height: 44px; border-radius: 12px; background: linear-gradient(135deg, #10b981, #059669); display: flex; align-items: center; justify-content: center; color: #fff; font-size: 1.35rem; box-shadow: 0 4px 12px rgba(16, 185, 129, 0.25);">
                                <i class="bi bi-gift-fill"></i>
                            </div>
                            <div>
                                <h2 class="h5 fw-bold mb-0 text-body">Nuevo Beneficio Corporativo</h2>
                                <span class="small text-secondary">Define la información del beneficio, condiciones de uso y el público objetivo</span>
                            </div>
                        </div>
                        <button type="button" class="btn-close" aria-label="Cerrar" @click="closeCreateBenefitModal"></button>
                    </div>

                    <!-- Modal Body -->
                    <div class="modal-body p-3 p-md-4" style="background: var(--bg-surface);">
                        <form @submit.prevent="submitBenefit('published')">
                            <!-- Título -->
                            <div class="mb-3">
                                <label for="benefit_title" class="form-label small fw-semibold text-body">
                                    Título del beneficio <span class="text-danger">*</span>
                                </label>
                                <div class="modern-input-wrapper">
                                    <i class="bi bi-gift-fill modern-input-icon"></i>
                                    <input
                                        id="benefit_title"
                                        v-model="benefitForm.title"
                                        type="text"
                                        maxlength="150"
                                        required
                                        class="modern-input-control"
                                        :class="{ 'is-invalid': benefitForm.errors.title }"
                                        placeholder="Ej.: 30% de descuento en membresía Smart Fit"
                                    >
                                </div>
                                <div v-if="benefitForm.errors.title" class="text-danger small mt-1">{{ benefitForm.errors.title }}</div>
                            </div>

                            <div class="row g-3 mb-3">
                                <!-- Empresa Aliada -->
                                <div class="col-12 col-md-5">
                                    <label for="benefit_partner" class="form-label small fw-semibold text-body">
                                        Empresa aliada / Proveedor
                                    </label>
                                    <div class="modern-input-wrapper">
                                        <i class="bi bi-building modern-input-icon"></i>
                                        <input
                                            id="benefit_partner"
                                            v-model="benefitForm.partner"
                                            type="text"
                                            maxlength="120"
                                            class="modern-input-control"
                                            placeholder="Ej.: Smart Fit, Clínica San Pablo"
                                        >
                                    </div>
                                </div>

                                <!-- Destacado Corto / Badge -->
                                <div class="col-12 col-md-3">
                                    <label for="benefit_highlight" class="form-label small fw-semibold text-body">
                                        Insignia destacada
                                    </label>
                                    <div class="modern-input-wrapper">
                                        <i class="bi bi-tag-fill modern-input-icon"></i>
                                        <input
                                            id="benefit_highlight"
                                            v-model="benefitForm.highlight"
                                            type="text"
                                            maxlength="40"
                                            class="modern-input-control"
                                            placeholder="Ej.: 30% DSCTO, 2x1"
                                        >
                                    </div>
                                </div>

                                <!-- Categoría -->
                                <div class="col-12 col-md-4">
                                    <label for="benefit_category_id" class="form-label small fw-semibold text-body">
                                        Categoría
                                    </label>
                                    <div class="modern-input-wrapper">
                                        <i class="bi bi-grid-fill modern-input-icon"></i>
                                        <select
                                            id="benefit_category_id"
                                            v-model="benefitForm.category_id"
                                            class="modern-input-control"
                                        >
                                            <option value="">Sin categoría</option>
                                            <option v-for="c in categories" :key="c.id" :value="c.id">{{ c.name }}</option>
                                        </select>
                                    </div>
                                </div>
                            </div>

                            <!-- Resumen Corto -->
                            <div class="mb-3">
                                <label for="benefit_summary" class="form-label small fw-semibold text-body">
                                    Resumen breve para el listado móvil (opcional)
                                </label>
                                <input
                                    id="benefit_summary"
                                    v-model="benefitForm.summary"
                                    type="text"
                                    maxlength="200"
                                    class="form-control"
                                    style="border-radius: 8px; font-size: 0.875rem;"
                                    placeholder="Ej.: Accede a tarifa preferencial en todos los planes Black presentando tu fotocheck."
                                >
                            </div>

                            <!-- Descripción Completa -->
                            <div class="mb-3">
                                <label for="benefit_description" class="form-label small fw-semibold text-body">
                                    Descripción detallada del beneficio
                                </label>
                                <textarea
                                    id="benefit_description"
                                    v-model="benefitForm.description"
                                    rows="5"
                                    maxlength="10000"
                                    class="form-control"
                                    style="border-radius: 8px; font-size: 0.875rem; min-height: 125px; padding: 10px 14px; line-height: 1.5; resize: vertical;"
                                    placeholder="Detalles del convenio, alcance, servicios incluidos y restricciones..."
                                ></textarea>
                            </div>

                            <div class="row g-3 mb-3">
                                <!-- Código Promocional -->
                                <div class="col-12 col-md-4">
                                    <label for="benefit_promo_code" class="form-label small fw-semibold text-body">
                                        Código promocional / Cupón
                                    </label>
                                    <div class="modern-input-wrapper">
                                        <i class="bi bi-upc-scan modern-input-icon"></i>
                                        <input
                                            id="benefit_promo_code"
                                            v-model="benefitForm.promo_code"
                                            type="text"
                                            maxlength="60"
                                            class="modern-input-control"
                                            placeholder="Ej.: TUNESA30"
                                        >
                                    </div>
                                </div>

                                <!-- Enlace Web -->
                                <div class="col-12 col-md-4">
                                    <label for="benefit_url" class="form-label small fw-semibold text-body">
                                        Enlace web externo
                                    </label>
                                    <div class="modern-input-wrapper">
                                        <i class="bi bi-link-45deg modern-input-icon"></i>
                                        <input
                                            id="benefit_url"
                                            v-model="benefitForm.url"
                                            type="url"
                                            maxlength="500"
                                            class="modern-input-control"
                                            placeholder="https://..."
                                        >
                                    </div>
                                </div>

                                <!-- Contacto -->
                                <div class="col-12 col-md-4">
                                    <label for="benefit_contact" class="form-label small fw-semibold text-body">
                                        Contacto / Teléfono / WhatsApp
                                    </label>
                                    <div class="modern-input-wrapper">
                                        <i class="bi bi-telephone modern-input-icon"></i>
                                        <input
                                            id="benefit_contact"
                                            v-model="benefitForm.contact"
                                            type="text"
                                            maxlength="150"
                                            class="modern-input-control"
                                            placeholder="Ej.: 987 654 321 / info@aliado.com"
                                        >
                                    </div>
                                </div>
                            </div>

                            <div class="row g-3 mb-3">
                                <!-- Cómo Usar -->
                                <div class="col-12 col-md-6">
                                    <label for="benefit_how_to_use" class="form-label small fw-semibold text-body">
                                        ¿Cómo acceder al beneficio?
                                    </label>
                                    <textarea
                                        id="benefit_how_to_use"
                                        v-model="benefitForm.how_to_use"
                                        rows="4"
                                        maxlength="5000"
                                        class="form-control"
                                        style="border-radius: 8px; font-size: 0.875rem; min-height: 110px; padding: 10px 14px; line-height: 1.5; resize: vertical;"
                                        placeholder="Ej.: Presentar fotocheck en counter o ingresar el cupón en la web..."
                                    ></textarea>
                                </div>

                                <!-- Términos y Condiciones -->
                                <div class="col-12 col-md-6">
                                    <label for="benefit_terms" class="form-label small fw-semibold text-body">
                                        Términos y condiciones
                                    </label>
                                    <textarea
                                        id="benefit_terms"
                                        v-model="benefitForm.terms"
                                        rows="4"
                                        maxlength="5000"
                                        class="form-control"
                                        style="border-radius: 8px; font-size: 0.875rem; min-height: 110px; padding: 10px 14px; line-height: 1.5; resize: vertical;"
                                        placeholder="Ej.: No acumulable con otras promociones. Válido solo para colaboradores activos..."
                                    ></textarea>
                                </div>
                            </div>

                            <div class="row g-3 mb-3">
                                <!-- Sede / Ubicación -->
                                <div class="col-12 col-md-4">
                                    <label for="benefit_location" class="form-label small fw-semibold text-body">
                                        Lugar / Sedes participantes
                                    </label>
                                    <div class="modern-input-wrapper">
                                        <i class="bi bi-geo-alt modern-input-icon"></i>
                                        <input
                                            id="benefit_location"
                                            v-model="benefitForm.location"
                                            type="text"
                                            maxlength="200"
                                            class="modern-input-control"
                                            placeholder="Ej.: A nivel nacional / Lima y Trujillo"
                                        >
                                    </div>
                                </div>

                                <!-- Válido desde -->
                                <div class="col-12 col-md-4">
                                    <label for="benefit_valid_from" class="form-label small fw-semibold text-body">
                                        Válido desde
                                    </label>
                                    <ModernDatePicker
                                        id="benefit_valid_from"
                                        v-model="benefitForm.valid_from"
                                        placeholder="dd/mm/aaaa"
                                        icon="bi bi-calendar-event"
                                        align="left"
                                    />
                                </div>

                                <!-- Válido hasta -->
                                <div class="col-12 col-md-4">
                                    <label for="benefit_valid_until" class="form-label small fw-semibold text-body">
                                        Válido hasta <span class="text-muted fw-normal" style="font-size: 0.75rem;">(Opcional)</span>
                                    </label>
                                    <ModernDatePicker
                                        id="benefit_valid_until"
                                        v-model="benefitForm.valid_until"
                                        placeholder="dd/mm/aaaa"
                                        icon="bi bi-calendar-check"
                                        :allow-clear="true"
                                        clear-label="Sin fecha fin"
                                        align="right"
                                    />
                                    <div v-if="benefitForm.errors.valid_until" class="text-danger small mt-1">
                                        {{ benefitForm.errors.valid_until }}
                                    </div>
                                </div>
                            </div>

                            <!-- Imagen / Banner -->
                            <div class="mb-3 p-3 border rounded-3 bg-light-subtle">
                                <label class="form-label small fw-bold text-body mb-2 d-flex align-items-center">
                                    <i class="bi bi-image text-primary" style="margin-right: 8px !important;"></i>
                                    Imagen o Banner promocional (JPG, PNG, WebP máx. 4MB)
                                </label>
                                <div class="d-flex align-items-center gap-3">
                                    <input
                                        id="benefit_image"
                                        type="file"
                                        accept="image/*"
                                        class="form-control"
                                        style="border-radius: 8px; font-size: 0.85rem;"
                                        @change="onBenefitImage"
                                    >
                                    <div v-if="imagePreview" class="flex-shrink-0 position-relative" style="width: 50px; height: 50px; border-radius: 8px; overflow: hidden; border: 1px solid var(--border-color);">
                                        <img :src="imagePreview" alt="Vista previa" class="w-100 h-100 object-fit-cover">
                                    </div>
                                </div>
                                <div v-if="benefitForm.errors.image" class="text-danger small mt-1">{{ benefitForm.errors.image }}</div>
                            </div>

                            <!-- Switches: Destacado y Notificar -->
                            <div class="row g-3 mb-3">
                                <div class="col-12 col-md-6">
                                    <div class="form-check form-switch p-2 border rounded d-flex align-items-center justify-content-between h-100">
                                        <label class="form-check-label small fw-semibold text-body mb-0 cursor-pointer" for="benefit_featured">
                                            <i class="bi bi-star-fill text-warning" style="margin-right: 6px !important;"></i>
                                            Destacar beneficio (aparece primero)
                                        </label>
                                        <input
                                            id="benefit_featured"
                                            v-model="benefitForm.is_featured"
                                            class="form-check-input ms-0"
                                            type="checkbox"
                                            role="switch"
                                            style="cursor: pointer;"
                                        >
                                    </div>
                                </div>

                                <div class="col-12 col-md-6">
                                    <div class="form-check form-switch p-2 border rounded d-flex align-items-center justify-content-between h-100">
                                        <label class="form-check-label small fw-semibold text-body mb-0 cursor-pointer" for="benefit_notify">
                                            <i class="bi bi-bell-fill text-info" style="margin-right: 6px !important;"></i>
                                            Notificar push a colaboradores al publicar
                                        </label>
                                        <input
                                            id="benefit_notify"
                                            v-model="benefitForm.notify"
                                            class="form-check-input ms-0"
                                            type="checkbox"
                                            role="switch"
                                            style="cursor: pointer;"
                                        >
                                    </div>
                                </div>
                            </div>

                            <!-- Público Objetivo / Audiencia -->
                            <div class="mb-3 p-3 border rounded-3 bg-light-subtle">
                                <label class="form-label small fw-bold text-body mb-2 d-flex align-items-center">
                                    <i class="bi bi-people-fill text-primary" style="margin-right: 8px !important;"></i>
                                    Público destinatario
                                </label>
                                <AudiencePicker
                                    v-model="benefitForm.targets"
                                    :options="audience"
                                    :errors="benefitForm.errors"
                                />
                            </div>
                        </form>
                    </div>

                    <!-- Modal Footer -->
                    <div class="modal-footer border-top p-3 px-md-4 d-flex justify-content-between align-items-center" style="background: var(--bg-surface);">
                        <button
                            type="button"
                            class="btn btn-outline-secondary d-inline-flex align-items-center"
                            @click="closeCreateBenefitModal"
                        >
                            Cancelar
                        </button>
                        <div class="d-flex align-items-center" style="gap: 10px !important;">
                            <button
                                type="button"
                                class="btn btn-outline-primary d-inline-flex align-items-center"
                                :disabled="benefitForm.processing"
                                @click="submitBenefit('draft')"
                            >
                                <span v-if="benefitForm.processing" class="spinner-border spinner-border-sm" style="margin-right: 8px !important;"></span>
                                <i v-else class="bi bi-file-earmark-check" style="margin-right: 8px !important;"></i>
                                <span>Guardar borrador</span>
                            </button>
                            <button
                                type="button"
                                class="btn btn-primary d-inline-flex align-items-center shadow-sm"
                                :disabled="benefitForm.processing"
                                @click="submitBenefit('published')"
                            >
                                <span v-if="benefitForm.processing" class="spinner-border spinner-border-sm" style="margin-right: 8px !important;"></span>
                                <i v-else class="bi bi-send-check-fill" style="margin-right: 8px !important;"></i>
                                <span>Publicar beneficio</span>
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- =========================================================================
             MODAL 2: GESTIÓN DE CATEGORÍAS DE BENEFICIOS (Elegante y Escalable)
             ========================================================================= -->
        <div
            v-if="showCategoriesModal"
            class="modal fade show d-block"
            tabindex="-1"
            role="dialog"
            aria-modal="true"
            style="background: rgba(15, 23, 42, 0.65); backdrop-filter: blur(4px); z-index: 1055;"
            @click.self="closeCategoriesModal"
        >
            <div class="modal-dialog modal-lg modal-dialog-centered modal-dialog-scrollable">
                <div class="modal-content border-0 shadow-lg" style="border-radius: 14px; overflow: hidden;">
                    <!-- Modal Header -->
                    <div class="modal-header border-bottom p-3 p-md-4" style="background: var(--bg-surface);">
                        <div class="d-flex align-items-center" style="gap: 14px !important;">
                            <div style="width: 44px; height: 44px; border-radius: 12px; background: linear-gradient(135deg, #6366f1, #4f46e5); display: flex; align-items: center; justify-content: center; color: #fff; font-size: 1.35rem; box-shadow: 0 4px 12px rgba(99, 102, 241, 0.25);">
                                <i class="bi bi-tags-fill"></i>
                            </div>
                            <div>
                                <h2 class="h5 fw-bold mb-0 text-body">Categorías de Beneficios</h2>
                                <span class="small text-secondary">Organiza y administra las categorías visibles en la app móvil</span>
                            </div>
                        </div>
                        <button type="button" class="btn-close" aria-label="Cerrar" @click="closeCategoriesModal"></button>
                    </div>

                    <!-- Modal Body -->
                    <div class="modal-body p-3 p-md-4" style="background: var(--bg-surface);">
                        <div class="row g-4">
                            <!-- Lista de categorías existentes -->
                            <div class="col-12 col-md-6">
                                <div class="d-flex align-items-center justify-content-between mb-2">
                                    <span class="small fw-bold text-uppercase text-secondary" style="font-size: 0.75rem; letter-spacing: 0.05em;">Categorías activas</span>
                                    <span class="badge bg-primary-subtle text-primary rounded-pill">{{ categories.length }}</span>
                                </div>
                                <div class="list-group border rounded-3 overflow-hidden shadow-xs" style="max-height: 380px; overflow-y: auto;">
                                    <div
                                        v-for="c in categories"
                                        :key="c.id"
                                        class="list-group-item d-flex align-items-center justify-content-between p-2.5 border-bottom"
                                        :class="{ 'bg-primary-subtle': categoryForm.id === c.id }"
                                    >
                                        <div class="d-flex align-items-center gap-2 min-w-0">
                                            <div
                                                class="d-flex align-items-center justify-content-center flex-shrink-0"
                                                :style="{ background: `${c.color}22`, color: c.color, width: '34px', height: '34px', borderRadius: '8px' }"
                                            >
                                                <i class="bi fs-6" :class="`bi-${c.icon}`"></i>
                                            </div>
                                            <div class="min-w-0">
                                                <div class="fw-semibold text-body small text-truncate">{{ c.name }}</div>
                                                <div class="text-secondary" style="font-size: 0.725rem;">
                                                    {{ c.benefits_count ?? 0 }} beneficio(s) · Pos. {{ c.position }}
                                                </div>
                                            </div>
                                        </div>
                                        <div class="d-flex align-items-center gap-1 flex-shrink-0">
                                            <button
                                                type="button"
                                                class="btn btn-sm btn-outline-secondary p-0 d-inline-flex align-items-center justify-content-center"
                                                style="width: 28px; height: 28px; border-radius: 6px;"
                                                title="Editar categoría"
                                                @click="editCategory(c)"
                                            >
                                                <i class="bi bi-pencil-fill" style="font-size: 0.75rem;"></i>
                                            </button>
                                            <button
                                                type="button"
                                                class="btn btn-sm btn-outline-danger p-0 d-inline-flex align-items-center justify-content-center"
                                                style="width: 28px; height: 28px; border-radius: 6px;"
                                                title="Eliminar categoría"
                                                @click="destroyCategory(c)"
                                            >
                                                <i class="bi bi-trash3-fill" style="font-size: 0.75rem;"></i>
                                            </button>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <!-- Formulario Crear / Editar Categoría -->
                            <div class="col-12 col-md-6">
                                <div class="p-3 border rounded-3 bg-light-subtle h-100 d-flex flex-column justify-content-between">
                                    <div>
                                        <div class="d-flex align-items-center justify-content-between mb-3 pb-2 border-bottom">
                                            <span class="small fw-bold text-body">
                                                {{ categoryForm.id ? `Editar: ${categoryForm.name}` : 'Nueva Categoría' }}
                                            </span>
                                            <button
                                                v-if="categoryForm.id"
                                                type="button"
                                                class="btn btn-link btn-sm text-secondary p-0 text-decoration-none"
                                                @click="resetCategoryForm"
                                            >
                                                Cancelar edición
                                            </button>
                                        </div>

                                        <div class="mb-3">
                                            <label for="cat_name" class="form-label small fw-semibold text-body">Nombre de categoría *</label>
                                            <input
                                                id="cat_name"
                                                v-model="categoryForm.name"
                                                type="text"
                                                maxlength="60"
                                                required
                                                class="form-control"
                                                :class="{ 'is-invalid': categoryForm.errors.name }"
                                                placeholder="Ej.: Salud y Bienestar"
                                                style="border-radius: 8px; font-size: 0.85rem;"
                                            >
                                            <div v-if="categoryForm.errors.name" class="text-danger small mt-1">{{ categoryForm.errors.name }}</div>
                                        </div>

                                        <div class="row g-2 mb-3">
                                            <div class="col-7">
                                                <label for="cat_icon" class="form-label small fw-semibold text-body">Ícono</label>
                                                <select
                                                    id="cat_icon"
                                                    v-model="categoryForm.icon"
                                                    class="form-select form-select-sm"
                                                    style="border-radius: 8px; font-size: 0.85rem; height: 38px;"
                                                >
                                                    <option v-for="(label, key) in (category_icons && Object.keys(category_icons).length ? category_icons : DEFAULT_ICONS)" :key="key" :value="key">
                                                        {{ label }} ({{ key }})
                                                    </option>
                                                </select>
                                            </div>
                                            <div class="col-5">
                                                <label for="cat_position" class="form-label small fw-semibold text-body">Posición</label>
                                                <input
                                                    id="cat_position"
                                                    v-model="categoryForm.position"
                                                    type="number"
                                                    min="0"
                                                    class="form-control form-control-sm"
                                                    style="border-radius: 8px; font-size: 0.85rem; height: 38px;"
                                                    placeholder="Ej.: 1"
                                                >
                                            </div>
                                        </div>

                                        <div class="mb-3">
                                            <label class="form-label small fw-semibold text-body mb-1">Color temático</label>
                                            <div class="d-flex align-items-center gap-2 mb-2">
                                                <input
                                                    v-model="categoryForm.color"
                                                    type="color"
                                                    class="form-control form-control-color p-1"
                                                    style="width: 38px; height: 38px; border-radius: 8px; cursor: pointer;"
                                                >
                                                <input
                                                    v-model="categoryForm.color"
                                                    type="text"
                                                    maxlength="7"
                                                    class="form-control form-control-sm"
                                                    style="width: 100px; height: 38px; border-radius: 8px; font-size: 0.85rem;"
                                                    placeholder="#6C4CE0"
                                                >
                                                <div
                                                    class="ms-auto d-flex align-items-center justify-content-center"
                                                    :style="{ background: `${categoryForm.color}22`, color: categoryForm.color, width: '38px', height: '38px', borderRadius: '8px' }"
                                                >
                                                    <i class="bi fs-5" :class="`bi-${categoryForm.icon}`"></i>
                                                </div>
                                            </div>
                                            <!-- Paleta predefinida -->
                                            <div class="d-flex flex-wrap gap-1">
                                                <button
                                                    v-for="clr in PRESET_COLORS"
                                                    :key="clr"
                                                    type="button"
                                                    class="btn p-0 border"
                                                    :style="{ background: clr, width: '22px', height: '22px', borderRadius: '5px', borderColor: categoryForm.color === clr ? '#000' : 'transparent', transform: categoryForm.color === clr ? 'scale(1.15)' : 'none' }"
                                                    @click="categoryForm.color = clr"
                                                ></button>
                                            </div>
                                        </div>
                                    </div>

                                    <button
                                        type="button"
                                        class="btn btn-primary w-100 d-inline-flex align-items-center justify-content-center shadow-sm"
                                        style="height: 38px; border-radius: 8px; font-weight: 600; font-size: 0.85rem;"
                                        :disabled="categoryForm.processing"
                                        @click="submitCategory"
                                    >
                                        <span v-if="categoryForm.processing" class="spinner-border spinner-border-sm me-2"></span>
                                        <i v-else class="bi bi-check2" style="margin-right: 8px !important;"></i>
                                        <span>{{ categoryForm.id ? 'Actualizar Categoría' : 'Guardar Categoría' }}</span>
                                    </button>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Modal Footer -->
                    <div class="modal-footer border-top p-3 px-md-4 d-flex justify-content-end" style="background: var(--bg-surface);">
                        <button
                            type="button"
                            class="btn btn-outline-secondary"
                            @click="closeCategoriesModal"
                        >
                            Cerrar
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </AppLayout>
</template>
