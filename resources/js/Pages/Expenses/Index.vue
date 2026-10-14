<script setup>
import { reactive } from 'vue';
import { router, useForm } from '@inertiajs/vue3';
import PageHeader from '@/Components/PageHeader.vue';
import Pagination from '@/Components/Pagination.vue';
import Field from '@/Components/Field.vue';
import { money, date, today } from '@/lib/format';

const props = defineProps({ expenses: Object, byCategory: Array, total: Number, categories: Array, methods: Object, filters: Object });
const f = reactive({ ...props.filters });
const apply = () => router.get(route('expenses.index'), Object.fromEntries(Object.entries(f).filter(([, v]) => v)), { preserveState: true });
const form = useForm({ date: today(), expense_category_id: '', new_category: '', description: '', amount: '', payment_method: 'cash', from_cash: true });
const submit = () => form.transform((d) => ({ ...d, expense_category_id: d.expense_category_id || null })).post(route('expenses.store'), { preserveScroll: true, onSuccess: () => form.reset('description', 'amount', 'new_category') });
const destroy = (e) => confirm('¿Eliminar el gasto?') && router.delete(route('expenses.destroy', e.id), { preserveScroll: true });
</script>

<template>
    <PageHeader title="Gastos operativos" :subtitle="`Total del período: ${money(total)}`" />
    <div class="grid gap-4 xl:grid-cols-[340px_1fr]">
        <div class="space-y-4">
            <form class="panel space-y-3 p-4" @submit.prevent="submit">
                <h2 class="panel-title">Registrar gasto</h2>
                <div class="grid grid-cols-2 gap-2">
                    <Field label="Fecha"><input v-model="form.date" type="date" class="input" /></Field>
                    <Field label="Importe" :error="form.errors.amount"><input v-model="form.amount" type="number" step="0.01" min="0" class="input" /></Field>
                </div>
                <Field label="Categoría">
                    <select v-model="form.expense_category_id" class="input"><option value="">Nueva categoría…</option><option v-for="c in categories" :key="c.id" :value="c.id">{{ c.name }}</option></select>
                </Field>
                <Field v-if="!form.expense_category_id" label="Nombre de la categoría nueva"><input v-model="form.new_category" class="input" /></Field>
                <Field label="Descripción" :error="form.errors.description"><input v-model="form.description" class="input" /></Field>
                <Field label="Medio de pago"><select v-model="form.payment_method" class="input"><option v-for="(l, k) in methods" :key="k" :value="k">{{ l }}</option></select></Field>
                <label class="flex items-center gap-2 text-sm"><input v-model="form.from_cash" type="checkbox" class="checkbox" /> Sale de mi caja abierta</label>
                <button class="btn-primary w-full" :disabled="form.processing">Registrar</button>
            </form>
            <div class="panel p-4 text-sm">
                <div class="mb-2 font-medium">Por categoría</div>
                <div v-for="c in byCategory" :key="c.expense_category_id" class="flex justify-between py-0.5"><span>{{ c.category?.name ?? 'Sin categoría' }}</span><span class="tabular-nums">{{ money(c.total) }}</span></div>
            </div>
        </div>
        <div class="panel">
            <form class="flex flex-wrap items-end gap-2 border-b border-steel-200 px-4 py-3" @submit.prevent="apply">
                <div><label class="label">Desde</label><input v-model="f.from" type="date" class="input" /></div>
                <div><label class="label">Hasta</label><input v-model="f.to" type="date" class="input" /></div>
                <div><label class="label">Categoría</label><select v-model="f.category_id" class="input w-auto"><option :value="undefined">Todas</option><option v-for="c in categories" :key="c.id" :value="c.id">{{ c.name }}</option></select></div>
                <button class="btn-dark">Filtrar</button>
            </form>
            <div class="overflow-x-auto">
                <table class="table">
                    <thead><tr><th>Fecha</th><th>Categoría</th><th>Descripción</th><th>Sucursal</th><th>Medio</th><th class="num">Importe</th><th></th></tr></thead>
                    <tbody>
                        <tr v-for="e in expenses.data" :key="e.id">
                            <td>{{ date(e.date) }}</td>
                            <td>{{ e.category?.name ?? '—' }}</td>
                            <td>{{ e.description }}<div class="text-xs text-steel-500">{{ e.user?.name }}</div></td>
                            <td>{{ e.branch?.name }}</td>
                            <td>{{ methods[e.payment_method] ?? 'Cuenta corriente' }}<span v-if="e.cash_session_id" class="text-xs text-steel-500"> · caja</span></td>
                            <td class="num font-medium">{{ money(e.amount) }}</td>
                            <td><button v-if="!e.cash_session_id" class="text-xs text-up hover:underline" @click="destroy(e)">Eliminar</button></td>
                        </tr>
                        <tr v-if="!expenses.data.length"><td colspan="7" class="py-6 text-center text-steel-500">Sin gastos en el período.</td></tr>
                    </tbody>
                </table>
            </div>
            <Pagination :data="expenses" />
        </div>
    </div>
</template>
