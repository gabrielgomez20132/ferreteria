<script setup>
import { ref } from 'vue';
import { Head, Link, router } from '@inertiajs/vue3';
import { money, today } from '@/lib/format';

const props = defineProps({ products: Array, filters: Object, company: String });
const since = ref(props.filters.since ?? today());
const format = ref(props.filters.format ?? 'gondola');
const print = () => window.print();
</script>

<template>
    <Head title="Etiquetas de precio" />
    <div class="no-print sticky top-0 z-10 flex flex-wrap items-end gap-3 border-b border-steel-200 bg-white px-6 py-3">
        <Link :href="route('dashboard')" class="btn-ghost">← Volver</Link>
        <div>
            <label class="label">Con precio cambiado desde</label>
            <input v-model="since" type="date" class="input" />
        </div>
        <button class="btn-secondary" @click="router.get(route('products.labels'), { since, format })">Buscar</button>
        <div>
            <label class="label">Formato</label>
            <select v-model="format" class="input w-auto"><option value="gondola">Góndola (3 por fila)</option><option value="small">Chica (5 por fila)</option></select>
        </div>
        <div class="ml-auto text-sm text-steel-500">{{ products.length }} etiquetas</div>
        <button class="btn-primary" :disabled="!products.length" @click="print">Imprimir</button>
    </div>

    <div class="mx-auto max-w-[210mm] bg-white p-[8mm] print:p-0">
        <div v-if="!products.length" class="no-print py-16 text-center text-steel-500">No hay productos para etiquetar con este criterio.</div>
        <div class="grid gap-[3mm]" :class="format === 'small' ? 'grid-cols-5' : 'grid-cols-3'">
            <div v-for="p in products" :key="p.id" class="flex flex-col justify-between border border-steel-900 p-[2.5mm] break-inside-avoid" :class="format === 'small' ? 'h-[22mm]' : 'h-[34mm]'">
                <div class="leading-tight font-medium" :class="format === 'small' ? 'line-clamp-2 text-[8pt]' : 'line-clamp-2 text-[10pt]'">{{ p.name }}</div>
                <div class="flex items-end justify-between">
                    <div class="text-[7pt] text-steel-600">{{ p.code }}<br v-if="format !== 'small'" /><span v-if="format !== 'small'">{{ company }}</span></div>
                    <div class="text-right">
                        <div class="font-bold tabular-nums" :class="format === 'small' ? 'text-[11pt]' : 'text-[18pt] leading-none'">{{ money(p.price) }}</div>
                        <div v-if="p.unit" class="text-[7pt] text-steel-600">por {{ p.unit.abbreviation }}</div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</template>
