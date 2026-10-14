<script setup>
import { ref, watch } from 'vue';
import { usePage } from '@inertiajs/vue3';
import { CheckCircle2, XCircle, X } from '@lucide/vue';

const page = usePage();
const toasts = ref([]);
let id = 0;

const push = (type, text) => {
    if (!text) return;
    const t = { id: ++id, type, text };
    toasts.value.push(t);
    setTimeout(() => (toasts.value = toasts.value.filter((x) => x.id !== t.id)), type === 'error' ? 9000 : 4500);
};

watch(
    () => page.props.flash,
    (f) => {
        push('success', f?.success);
        push('error', f?.error);
    },
    { immediate: true, deep: true },
);

// errores de validación sin campo visible
watch(
    () => page.props.errors,
    (e) => {
        const keys = Object.keys(e || {});
        if (keys.length && keys.every((k) => k.includes('.'))) push('error', e[keys[0]]);
    },
);
</script>

<template>
    <div class="no-print pointer-events-none fixed right-4 bottom-4 z-50 flex w-full max-w-sm flex-col gap-2" aria-live="polite">
        <div
            v-for="t in toasts" :key="t.id"
            class="pointer-events-auto flex items-start gap-2 rounded-md border bg-white px-3 py-2.5 text-sm shadow-lg"
            :class="t.type === 'error' ? 'border-up/40' : 'border-down/30'"
        >
            <XCircle v-if="t.type === 'error'" class="mt-0.5 size-4 shrink-0 text-up" />
            <CheckCircle2 v-else class="mt-0.5 size-4 shrink-0 text-down" />
            <span class="flex-1">{{ t.text }}</span>
            <button class="text-steel-400 hover:text-steel-700" @click="toasts = toasts.filter((x) => x.id !== t.id)" aria-label="Cerrar"><X class="size-4" /></button>
        </div>
    </div>
</template>
