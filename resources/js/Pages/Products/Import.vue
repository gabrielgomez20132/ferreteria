<script setup>
import { useForm, usePage } from '@inertiajs/vue3';
import { Download } from '@lucide/vue';
import PageHeader from '@/Components/PageHeader.vue';

defineProps({ columns: Object });
const page = usePage();
const form = useForm({ file: null, update_existing: true });
const submit = () => form.post(route('products.import.store'), { forceFormData: true });
</script>

<template>
    <PageHeader title="Importar productos desde Excel" subtitle="Para la carga inicial del catálogo o para actualizaciones masivas de datos. Para actualizar precios de proveedores usá “Listas de proveedores”." :back="route('products.index')" />
    <div class="grid gap-4 lg:grid-cols-2">
        <form class="panel space-y-4 p-4" @submit.prevent="submit">
            <a :href="route('products.import.template')" class="btn-secondary"><Download class="size-4" /> Descargar planilla modelo</a>
            <div>
                <label class="label">Archivo (.xlsx, .xls, .csv)</label>
                <input type="file" accept=".xlsx,.xls,.csv,.ods" class="input" @change="form.file = $event.target.files[0]" />
                <p v-if="form.errors.file" class="mt-1 text-xs text-up">{{ form.errors.file }}</p>
            </div>
            <label class="flex items-center gap-2 text-sm"><input v-model="form.update_existing" type="checkbox" class="checkbox" /> Si el código ya existe, actualizar el producto</label>
            <button class="btn-primary" :disabled="!form.file || form.processing">{{ form.processing ? 'Importando…' : 'Importar' }}</button>
            <div v-if="page.props.flash.data?.errors?.length" class="rounded-md border border-up/30 bg-up-bg p-3 text-sm">
                <div class="mb-1 font-medium text-up">Filas con problemas ({{ page.props.flash.data.errors.length }})</div>
                <ul class="max-h-60 list-disc overflow-y-auto pl-5 text-xs text-steel-700"><li v-for="(e, i) in page.props.flash.data.errors" :key="i">{{ e }}</li></ul>
            </div>
        </form>
        <div class="panel">
            <div class="panel-head"><h2 class="panel-title">Columnas reconocidas (primera fila)</h2></div>
            <table class="table">
                <tbody><tr v-for="(d, k) in columns" :key="k"><td class="font-medium">{{ k }}</td><td class="text-steel-600">{{ d }}</td></tr></tbody>
            </table>
        </div>
    </div>
</template>
