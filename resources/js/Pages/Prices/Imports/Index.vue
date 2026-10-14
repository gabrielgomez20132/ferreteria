<script setup>
import { ref, computed } from 'vue';
import { Link, router, useForm } from '@inertiajs/vue3';
import { UploadCloud, FileSpreadsheet, Download } from '@lucide/vue';
import PageHeader from '@/Components/PageHeader.vue';
import Pagination from '@/Components/Pagination.vue';
import StatusBadge from '@/Components/StatusBadge.vue';
import Variation from '@/Components/Variation.vue';
import { datetime, date } from '@/lib/format';

const props = defineProps({ imports: Object, suppliers: Array, filters: Object });

const form = useForm({ supplier_id: '', file: null });
const dragging = ref(false);
const fileInput = ref(null);

const supplier = computed(() => props.suppliers.find((s) => s.id === Number(form.supplier_id)));
const discounts = computed(() => {
    const s = supplier.value;
    if (!s) return '';
    const d = [s.discount_1, s.discount_2, s.discount_3].map(Number).filter((x) => x);
    return d.length ? 'Bonificaciones: ' + d.map((x) => x + '%').join(' + ') : 'Sin bonificaciones cargadas';
});

const setFile = (f) => (form.file = f || null);
const onDrop = (e) => {
    dragging.value = false;
    setFile(e.dataTransfer.files[0]);
};
const submit = () => form.post(route('price-imports.store'), { forceFormData: true });
</script>

<template>
    <PageHeader title="Listas de precios de proveedores" subtitle="Subí el Excel o CSV que te manda el proveedor. El sistema calcula los costos nuevos, te muestra qué cambia y vos decidís qué aplicar." />

    <div class="grid gap-4 lg:grid-cols-[minmax(0,420px)_1fr]">
        <form class="panel p-4" @submit.prevent="submit">
            <h2 class="panel-title mb-3">Nueva actualización</h2>
            <label class="label" for="sup">Proveedor</label>
            <select id="sup" v-model="form.supplier_id" class="input" required>
                <option value="" disabled>Elegí el proveedor…</option>
                <option v-for="s in suppliers" :key="s.id" :value="s.id">{{ s.name }}</option>
            </select>
            <p v-if="supplier" class="mt-1 text-xs text-steel-500">
                {{ discounts }} · {{ supplier.prices_include_vat ? 'Precios con IVA' : 'Precios sin IVA' }}
                <span v-if="supplier.import_mapping"> · Se usará la configuración de columnas de la última lista</span>
            </p>
            <p v-if="form.errors.supplier_id" class="mt-1 text-xs text-up">{{ form.errors.supplier_id }}</p>

            <div
                class="mt-4 flex cursor-pointer flex-col items-center justify-center rounded-lg border-2 border-dashed px-4 py-8 text-center transition-colors"
                :class="dragging ? 'border-hazard-500 bg-hazard-300/15' : form.file ? 'border-down/50 bg-down-bg/40' : 'border-steel-300 hover:border-steel-400'"
                @click="fileInput.click()" @dragover.prevent="dragging = true" @dragleave="dragging = false" @drop.prevent="onDrop"
            >
                <FileSpreadsheet v-if="form.file" class="size-8 text-down" />
                <UploadCloud v-else class="size-8 text-steel-400" />
                <div class="mt-2 text-sm font-medium">{{ form.file ? form.file.name : 'Arrastrá el archivo o hacé clic para elegirlo' }}</div>
                <div class="text-xs text-steel-500">Excel (.xlsx, .xls), LibreOffice (.ods) o CSV · hasta 30 MB</div>
                <input ref="fileInput" type="file" class="hidden" accept=".xlsx,.xls,.csv,.txt,.ods" @change="setFile($event.target.files[0])" />
            </div>
            <p v-if="form.errors.file" class="mt-1 text-xs text-up">{{ form.errors.file }}</p>

            <button class="btn-primary mt-4 w-full py-2" :disabled="!form.supplier_id || !form.file || form.processing">
                {{ form.processing ? 'Subiendo…' : 'Continuar a columnas' }}
            </button>
            <a href="/ejemplos/lista-distribuidora-norte.xlsx" class="mt-3 flex items-center gap-1.5 text-xs text-link hover:underline">
                <Download class="size-3.5" /> Descargar una lista de ejemplo (Distribuidora Norte) para probar
            </a>
        </form>

        <div class="panel min-w-0">
            <div class="panel-head">
                <h2 class="panel-title">Importaciones anteriores</h2>
                <select class="input w-auto py-1" v-sel="filters.supplier_id ?? ''" @change="router.get(route('price-imports.index'), { supplier_id: $event.target.value || undefined })">
                    <option value="">Todos los proveedores</option>
                    <option v-for="s in suppliers" :key="s.id" :value="s.id">{{ s.name }}</option>
                </select>
            </div>
            <div class="overflow-x-auto">
                <table class="table">
                    <thead>
                        <tr><th>Fecha</th><th>Proveedor</th><th>Archivo</th><th class="num">Vinculados</th><th class="num">Var. prom.</th><th>Estado</th><th></th></tr>
                    </thead>
                    <tbody>
                        <tr v-for="i in imports.data" :key="i.id">
                            <td class="whitespace-nowrap">{{ datetime(i.created_at) }}</td>
                            <td>{{ i.supplier?.name }}</td>
                            <td class="max-w-56 truncate text-steel-600" :title="i.file_name">{{ i.file_name }}</td>
                            <td class="num">{{ i.matched_rows }}/{{ i.total_rows }}</td>
                            <td class="num"><Variation v-if="i.avg_variation !== null" :value="i.avg_variation" /></td>
                            <td><StatusBadge :status="i.status" /></td>
                            <td class="text-right">
                                <Link v-if="i.status !== 'cancelled'" :href="i.status === 'uploaded' ? route('price-imports.show', i.id) : route('price-imports.review', i.id)" class="link">
                                    {{ i.status === 'applied' ? 'Ver' : 'Continuar' }}
                                </Link>
                            </td>
                        </tr>
                        <tr v-if="!imports.data.length"><td colspan="7" class="py-6 text-center text-steel-500">Todavía no subiste ninguna lista.</td></tr>
                    </tbody>
                </table>
            </div>
            <Pagination :data="imports" />
        </div>
    </div>

    <div class="panel mt-4">
        <div class="panel-head"><h2 class="panel-title">Última actualización por proveedor</h2></div>
        <div class="grid gap-px bg-steel-100 sm:grid-cols-2 lg:grid-cols-4">
            <div v-for="s in suppliers" :key="s.id" class="bg-white px-4 py-3">
                <div class="font-medium">{{ s.name }}</div>
                <div class="text-xs" :class="!s.last_import_at || (Date.now() - new Date(s.last_import_at)) / 864e5 > 45 ? 'text-up' : 'text-steel-500'">
                    {{ s.last_import_at ? 'Aplicada el ' + date(s.last_import_at) : 'Nunca se aplicó una lista' }}
                </div>
            </div>
        </div>
    </div>
</template>
