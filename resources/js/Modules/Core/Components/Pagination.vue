<script setup>
// Paginador de Laravel serializado por Inertia ({ links: [{ url, label, active }], last_page })
import { Link } from '@inertiajs/vue3';

defineProps({ paginator: { type: Object, required: true } });

// El primer y el último enlace son Anterior / Siguiente
const text = (link, i, links) => (i === 0 ? '‹' : i === links.length - 1 ? '›' : link.label);
</script>

<template>
    <nav v-if="paginator.last_page > 1" aria-label="Paginación">
        <ul class="pagination pagination-sm mb-0 flex-wrap">
            <li v-for="(link, i) in paginator.links" :key="i" class="page-item" :class="{ active: link.active, disabled: !link.url }">
                <Link v-if="link.url" :href="link.url" class="page-link" preserve-scroll>{{ text(link, i, paginator.links) }}</Link>
                <span v-else class="page-link">{{ text(link, i, paginator.links) }}</span>
            </li>
        </ul>
    </nav>
</template>
