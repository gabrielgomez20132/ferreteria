<script setup>
import { ref, watch, nextTick } from 'vue';
import { Search, Loader2 } from '@lucide/vue';
import { getJson } from '@/lib/http';
import { money, qty } from '@/lib/format';

/**
 * Buscador de productos con teclado: por código, código de barras (lector), código de proveedor o descripción.
 * Si el código coincide exacto (lector de barras), selecciona directamente.
 */
const props = defineProps({
    priceListId: [Number, null],
    supplierId: [Number, null],
    placeholder: { type: String, default: 'Buscar por código, código de barras o descripción…' },
    showCost: Boolean,
    autofocus: Boolean,
});
const emit = defineEmits(['select']);

const q = ref('');
const items = ref([]);
const active = ref(0);
const loading = ref(false);
const open = ref(false);
const input = ref(null);
let timer = null;
let seq = 0;

async function run(term, immediate = false) {
    const my = ++seq;
    loading.value = true;
    try {
        const r = await getJson(route('products.search'), { q: term, price_list_id: props.priceListId, supplier_id: props.supplierId });
        if (my !== seq) return;
        items.value = r.items;
        active.value = 0;
        open.value = true;
        if (immediate && r.exact && r.items.length === 1) pick(r.items[0]);
    } finally {
        if (my === seq) loading.value = false;
    }
}

watch(q, (v) => {
    clearTimeout(timer);
    if (v.trim().length < 2) {
        items.value = [];
        open.value = false;
        return;
    }
    timer = setTimeout(() => run(v.trim()), 220);
});

function pick(item) {
    emit('select', item);
    q.value = '';
    items.value = [];
    open.value = false;
    nextTick(() => input.value?.focus());
}

function onEnter() {
    clearTimeout(timer);
    if (open.value && items.value[active.value]) return pick(items.value[active.value]);
    if (q.value.trim()) run(q.value.trim(), true);
}

function move(d) {
    if (!items.value.length) return;
    active.value = (active.value + d + items.value.length) % items.value.length;
}

function onBlur() {
    setTimeout(() => (open.value = false), 150);
}

defineExpose({ focus: () => input.value?.focus() });
</script>

<template>
    <div class="relative">
        <Search class="pointer-events-none absolute top-1/2 left-2.5 size-4 -translate-y-1/2 text-steel-400" />
        <input
            ref="input" v-model="q" type="text" :placeholder="placeholder" :autofocus="autofocus" autocomplete="off"
            class="input py-2 pl-8"
            @keydown.enter.prevent="onEnter" @keydown.down.prevent="move(1)" @keydown.up.prevent="move(-1)" @keydown.esc="open = false"
            @blur="onBlur" @focus="items.length && (open = true)"
        />
        <Loader2 v-if="loading" class="absolute top-1/2 right-2.5 size-4 -translate-y-1/2 animate-spin text-steel-400" />
        <div v-if="open" class="absolute z-30 mt-1 max-h-96 w-full overflow-y-auto rounded-md border border-steel-200 bg-white shadow-lg">
            <div v-if="!items.length" class="px-3 py-3 text-sm text-steel-500">Sin resultados para “{{ q }}”.</div>
            <button
                v-for="(it, i) in items" :key="it.id" type="button"
                class="flex w-full items-center gap-3 border-b border-steel-100 px-3 py-2 text-left text-sm last:border-0"
                :class="i === active ? 'bg-hazard-300/25' : 'hover:bg-steel-50'"
                @mousedown.prevent="pick(it)" @mouseenter="active = i"
            >
                <div class="min-w-0 flex-1">
                    <div class="truncate font-medium">{{ it.name }}</div>
                    <div class="code">
                        {{ it.code }}<span v-if="it.barcode"> · {{ it.barcode }}</span><span v-if="it.supplier_code"> · Prov: {{ it.supplier_code }}</span><span v-if="it.location"> · {{ it.location }}</span>
                    </div>
                </div>
                <div class="text-right">
                    <div class="font-semibold tabular-nums">{{ showCost ? money(it.supplier_cost || it.cost) : money(it.price) }}</div>
                    <div class="text-xs tabular-nums" :class="it.track_stock && it.stock <= 0 ? 'text-up' : 'text-steel-500'">
                        Stock {{ qty(it.stock) }} {{ it.unit }}
                    </div>
                </div>
            </button>
        </div>
    </div>
</template>
