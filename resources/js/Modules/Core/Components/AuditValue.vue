<script setup>
// Valor de la bitácora: texto plano, o HTML generado por el servidor (muestra de color)
defineProps({ value: { type: Object, required: true } });
</script>

<template>
    <span v-if="value.html" v-html="value.html"></span>
    <template v-else>{{ value.text }}</template>
</template>
