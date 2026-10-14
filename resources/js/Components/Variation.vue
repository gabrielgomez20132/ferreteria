<script setup>
import { computed } from 'vue';
import { pct, toNum } from '@/lib/format';

// Variación de costo/precio: suba en rojo (le cuesta más al comercio), baja en verde
const props = defineProps({ value: [Number, String, null], invert: Boolean });
const n = computed(() => (props.value === null || props.value === undefined || props.value === '' ? null : toNum(props.value)));
const cls = computed(() => {
    if (n.value === null || Math.abs(n.value) < 0.01) return 'badge-gray';
    const up = n.value > 0;
    return (props.invert ? !up : up) ? 'badge-up' : 'badge-down';
});
</script>

<template>
    <span :class="cls">{{ n === null ? 'nuevo' : (Math.abs(n) < 0.01 ? '0%' : pct(n, true)) }}</span>
</template>
