<script setup>
import { computed } from 'vue';
import { money } from '@/lib/format';

/**
 * Barras por mes con una o dos series (ej.: compras vs costo de lo vendido, presupuestado vs vendido).
 * months: [{ ym: '2026-05', ...valores }]; series: [{ key, label, color }]
 */
const props = defineProps({ months: Array, series: Array, height: { type: Number, default: 150 } });
const max = computed(() => Math.max(1, ...props.months.flatMap((m) => props.series.map((s) => Number(m[s.key]) || 0))));
const label = (ym) => new Date(ym + '-01T00:00:00').toLocaleDateString('es-AR', { month: 'short' }).replace('.', '');
</script>

<template>
    <div>
        <div class="mb-2 flex flex-wrap gap-4 text-xs text-steel-600">
            <span v-for="s in series" :key="s.key" class="flex items-center gap-1.5"><span class="size-2.5 rounded-sm" :style="{ background: s.color }" />{{ s.label }}</span>
        </div>
        <div class="flex items-end gap-1.5 border-b border-steel-200" :style="{ height: height + 'px' }">
            <div v-for="m in months" :key="m.ym" class="flex h-full flex-1 items-end justify-center gap-0.5">
                <div
                    v-for="s in series"
                    :key="s.key"
                    class="w-full max-w-5 rounded-t-sm"
                    :style="{ background: s.color, height: Math.max(Number(m[s.key]) ? 2 : 0, ((Number(m[s.key]) || 0) / max) * (height - 8)) + 'px' }"
                    :title="`${s.label} ${m.ym}: ${money(m[s.key])}`"
                />
            </div>
        </div>
        <div class="flex gap-1.5 pt-1 text-center text-[10px] text-steel-500"><div v-for="m in months" :key="m.ym" class="flex-1">{{ label(m.ym) }}</div></div>
    </div>
</template>
