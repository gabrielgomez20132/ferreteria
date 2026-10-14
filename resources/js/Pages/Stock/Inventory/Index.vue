<script setup>
import { Link, useForm } from '@inertiajs/vue3';
import PageHeader from '@/Components/PageHeader.vue';
import Pagination from '@/Components/Pagination.vue';
import StatusBadge from '@/Components/StatusBadge.vue';
import Field from '@/Components/Field.vue';
import { datetime } from '@/lib/format';

defineProps({ counts: Object, categories: Array });
const form = useForm({ category_id: '', notes: '' });
const start = () => form.transform((d) => ({ ...d, category_id: d.category_id || null })).post(route('stock.inventory.store'));
</script>

<template>
    <PageHeader title="Inventario físico" subtitle="Contá la mercadería y el sistema ajusta las diferencias. Podés hacerlo por categoría (inventario rotativo)." />
    <div class="grid gap-4 lg:grid-cols-[320px_1fr]">
        <form class="panel h-fit space-y-3 p-4" @submit.prevent="start">
            <h2 class="panel-title">Nuevo conteo</h2>
            <Field label="Categoría"><select v-model="form.category_id" class="input"><option value="">Todo el stock</option><option v-for="c in categories" :key="c.id" :value="c.id">{{ c.name }}</option></select></Field>
            <Field label="Observaciones"><input v-model="form.notes" class="input" /></Field>
            <button class="btn-primary w-full" :disabled="form.processing">Comenzar conteo</button>
        </form>
        <div class="panel overflow-x-auto">
            <table class="table">
                <thead><tr><th>N°</th><th>Fecha</th><th>Sucursal</th><th>Alcance</th><th class="num">Productos</th><th>Responsable</th><th>Estado</th></tr></thead>
                <tbody>
                    <tr v-for="c in counts.data" :key="c.id">
                        <td><Link :href="route('stock.inventory.show', c.id)" class="link">#{{ c.id }}</Link></td>
                        <td>{{ datetime(c.created_at) }}</td>
                        <td>{{ c.branch?.name }}</td>
                        <td>{{ c.category?.name ?? 'Todo' }}</td>
                        <td class="num">{{ c.items_count }}</td>
                        <td>{{ c.user?.name }}</td>
                        <td><StatusBadge :status="c.status" /></td>
                    </tr>
                    <tr v-if="!counts.data.length"><td colspan="7" class="py-6 text-center text-steel-500">Sin inventarios.</td></tr>
                </tbody>
            </table>
            <Pagination :data="counts" />
        </div>
    </div>
</template>
