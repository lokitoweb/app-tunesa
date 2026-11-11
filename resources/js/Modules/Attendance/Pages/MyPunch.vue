<script setup>
import AppLayout from '@/Modules/Core/Layouts/AppLayout.vue';
import PunchCard from '@/Modules/Attendance/Components/PunchCard.vue';

defineProps({
    today: { type: String, required: true },
    attendance: { type: Object, default: null },
    schedule: { type: Object, default: null },
    next: { type: Object, default: null },
    contingency: { type: Object, default: null },
    webPunch: { type: Boolean, default: false },
});
</script>

<template>
    <AppLayout title="Marcar mi asistencia">
        <header class="page-head">
            <div class="page-head-text">
                <h1 class="page-title">Marcar mi asistencia</h1>
                <p class="page-subtitle">Tu entrada, refrigerio y salida de hoy desde el panel, con las mismas reglas que la app.</p>
            </div>
        </header>
        <div class="my-punch">
            <PunchCard :attendance="attendance" :schedule="schedule" :next="next" :contingency="contingency" :web-punch="webPunch" post-url="/my-punch" />
        </div>
    </AppLayout>
</template>

<style>
.my-punch { max-width: 880px; }
</style>
