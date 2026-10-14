<script setup>
import { ref } from 'vue';
import { Link, router, useForm } from '@inertiajs/vue3';
import { Plus } from '@lucide/vue';
import PageHeader from '@/Components/PageHeader.vue';
import Modal from '@/Components/Modal.vue';
import Field from '@/Components/Field.vue';
import { pct } from '@/lib/format';

const props = defineProps({ type: String, title: String, items: Array, parents: Array });
const TABS = { categorias: 'Categorías', marcas: 'Marcas', unidades: 'Unidades', 'listas-precio': 'Listas de precio' };
const DEFAULTS = {
    categorias: { name: '', parent_id: '', default_markup: '' },
    marcas: { name: '' },
    unidades: { name: '', abbreviation: '', allows_decimals: false },
    'listas-precio': { name: '', adjustment_percent: 0, is_default: false, is_active: true },
};
const editing = ref(null);
const show = ref(false);
const form = useForm({ ...DEFAULTS[props.type] });

const open = (item = null) => {
    editing.value = item;
    form.clearErrors();
    Object.assign(form, DEFAULTS[props.type], item ? Object.fromEntries(Object.keys(DEFAULTS[props.type]).map((k) => [k, item[k] ?? DEFAULTS[props.type][k]])) : {});
    show.value = true;
};
const save = () => {
    form.transform((d) => ({ ...d, parent_id: d.parent_id || null, default_markup: d.default_markup === '' ? null : d.default_markup }));
    const opts = { preserveScroll: true, onSuccess: () => (show.value = false) };
    editing.value ? form.put(route('catalog.update', [props.type, editing.value.id]), opts) : form.post(route('catalog.store', props.type), opts);
};
const destroy = (item) => confirm(`¿Eliminar "${item.name}"?`) && router.delete(route('catalog.destroy', [props.type, item.id]), { preserveScroll: true });
</script>

<template>
    <PageHeader :title="title">
        <button class="btn-primary" @click="open()"><Plus class="size-4" /> Agregar</button>
    </PageHeader>
    <div class="mb-3 flex gap-1 border-b border-steel-200">
        <Link v-for="(l, k) in TABS" :key="k" :href="route('catalog.index', k)" class="-mb-px border-b-2 px-3 py-2 text-sm" :class="type === k ? 'border-hazard-500 font-medium' : 'border-transparent text-steel-500'">{{ l }}</Link>
    </div>
    <div class="panel">
        <table class="table">
            <thead>
                <tr>
                    <th>Nombre</th>
                    <th v-if="type === 'categorias'">Categoría padre</th>
                    <th v-if="type === 'categorias'" class="num">Margen sugerido</th>
                    <th v-if="type === 'unidades'">Abreviatura</th>
                    <th v-if="type === 'unidades'">Admite decimales</th>
                    <th v-if="type === 'listas-precio'" class="num">Ajuste sobre minorista</th>
                    <th v-if="'products_count' in (items[0] ?? {})" class="num">Productos</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
                <tr v-for="i in items" :key="i.id">
                    <td class="font-medium">{{ i.name }} <span v-if="i.is_default" class="badge-down ml-1">Principal</span><span v-if="i.is_active === false" class="badge-gray ml-1">Inactiva</span></td>
                    <td v-if="type === 'categorias'">{{ i.parent?.name ?? '—' }}</td>
                    <td v-if="type === 'categorias'" class="num">{{ i.default_markup !== null ? pct(i.default_markup) : '—' }}</td>
                    <td v-if="type === 'unidades'">{{ i.abbreviation }}</td>
                    <td v-if="type === 'unidades'">{{ i.allows_decimals ? 'Sí' : 'No' }}</td>
                    <td v-if="type === 'listas-precio'" class="num">{{ pct(i.adjustment_percent, true) }}</td>
                    <td v-if="'products_count' in i" class="num">{{ i.products_count }}</td>
                    <td class="text-right whitespace-nowrap">
                        <button class="text-xs link" @click="open(i)">Editar</button>
                        <button class="ml-3 text-xs text-up hover:underline" @click="destroy(i)">Eliminar</button>
                    </td>
                </tr>
                <tr v-if="!items.length"><td colspan="6" class="py-6 text-center text-steel-500">Sin registros.</td></tr>
            </tbody>
        </table>
    </div>

    <Modal :show="show" :title="editing ? 'Editar' : 'Agregar'" @close="show = false">
        <div class="grid gap-3">
            <Field label="Nombre" :error="form.errors.name"><input v-model="form.name" class="input" autofocus /></Field>
            <template v-if="type === 'categorias'">
                <Field label="Categoría padre"><select v-model="form.parent_id" class="input"><option value="">Ninguna</option><option v-for="p in parents" :key="p.id" :value="p.id" :disabled="p.id === editing?.id">{{ p.name }}</option></select></Field>
                <Field label="Margen sugerido %" hint="Se propone al crear productos de esta categoría"><input v-model="form.default_markup" type="number" step="0.01" class="input" /></Field>
            </template>
            <template v-if="type === 'unidades'">
                <Field label="Abreviatura" :error="form.errors.abbreviation"><input v-model="form.abbreviation" class="input" /></Field>
                <label class="flex items-center gap-2 text-sm"><input v-model="form.allows_decimals" type="checkbox" class="checkbox" /> Admite cantidades con decimales</label>
            </template>
            <template v-if="type === 'listas-precio'">
                <Field label="Ajuste % sobre el precio minorista" hint="Ej.: -10 para mayorista" :error="form.errors.adjustment_percent"><input v-model="form.adjustment_percent" type="number" step="0.01" class="input" :disabled="form.is_default" /></Field>
                <label class="flex items-center gap-2 text-sm"><input v-model="form.is_default" type="checkbox" class="checkbox" /> Lista principal (minorista)</label>
                <label class="flex items-center gap-2 text-sm"><input v-model="form.is_active" type="checkbox" class="checkbox" /> Activa</label>
            </template>
        </div>
        <template #footer>
            <button class="btn-secondary" @click="show = false">Cancelar</button>
            <button class="btn-primary" :disabled="form.processing" @click="save">Guardar</button>
        </template>
    </Modal>
</template>
