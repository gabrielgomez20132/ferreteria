<script setup>
// Estado de un presupuesto: pendiente, vencido, convertido en venta o rechazado
defineProps({ state: String });
const MAP = {
    pending: ['Pendiente', 'badge-warn'],
    expired: ['Vencido', 'badge-gray'],
    converted: ['Convertido', 'badge-down'],
    rejected: ['Rechazado', 'badge-up'],
};
</script>

<template>
    <span v-if="MAP[state]" :class="MAP[state][1]">{{ MAP[state][0] }}</span>
</template>
