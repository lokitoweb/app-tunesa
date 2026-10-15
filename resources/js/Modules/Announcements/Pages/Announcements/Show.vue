<script setup>
import { computed } from 'vue';
import { Link } from '@inertiajs/vue3';
import AppLayout from '@/Modules/Core/Layouts/AppLayout.vue';

const props = defineProps({ announcement: Object, readers: Array, categories: Object });
const rate = computed(() => (props.announcement.recipients ? Math.round((props.announcement.reads / props.announcement.recipients) * 100) : 0));
</script>

<template>
    <AppLayout :title="announcement.title">
        <nav aria-label="breadcrumb">
            <ol class="breadcrumb small mb-2">
                <li class="breadcrumb-item"><Link href="/announcements">Comunicados</Link></li>
                <li class="breadcrumb-item active" aria-current="page">Detalle</li>
            </ol>
        </nav>
        <div class="page-header">
            <div>
                <h1>{{ announcement.title }}</h1>
                <p>{{ categories[announcement.category]?.[0] }} · Enviado el {{ announcement.sent_at ?? '—' }} · {{ announcement.audience.join(', ') }}</p>
            </div>
        </div>

        <div class="row g-4">
            <div class="col-lg-7">
                <div class="card border-0">
                    <div class="card-body">
                        <p class="mb-3" style="white-space: pre-line">{{ announcement.body }}</p>
                        <div v-if="announcement.event_at" class="small"><i class="bi bi-calendar-event me-1"></i>{{ announcement.event_at }}</div>
                        <div v-if="announcement.location" class="small"><i class="bi bi-geo-alt me-1"></i>{{ announcement.location }}</div>
                        <a v-if="announcement.url" :href="announcement.url" target="_blank" rel="noopener" class="small"><i class="bi bi-link-45deg me-1"></i>{{ announcement.url }}</a>
                    </div>
                </div>
            </div>
            <div class="col-lg-5">
                <div class="card border-0">
                    <div class="card-header border-0 pt-3">
                        <h2 class="h6 fw-semibold mb-1">Lectura: {{ announcement.reads }} de {{ announcement.recipients }} ({{ rate }}%)</h2>
                        <div class="progress" style="height: 6px" role="progressbar" :aria-valuenow="rate" aria-valuemin="0" aria-valuemax="100" aria-label="Porcentaje de lectura">
                            <div class="progress-bar bg-success" :style="{ width: `${rate}%` }"></div>
                        </div>
                    </div>
                    <ul class="list-group list-group-flush">
                        <li v-for="(r, i) in readers" :key="i" class="list-group-item d-flex justify-content-between">
                            <span>{{ r.name }} <span v-if="r.position" class="small text-body-secondary">· {{ r.position }}</span></span>
                            <span class="small text-body-secondary">{{ r.read_at }}</span>
                        </li>
                        <li v-if="!readers.length" class="list-group-item small text-body-secondary">Nadie lo abrió todavía.</li>
                    </ul>
                </div>
            </div>
        </div>
    </AppLayout>
</template>
