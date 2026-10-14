<script setup>
import { reactive } from 'vue';
import { Link, router } from '@inertiajs/vue3';
import PageHeader from '@/Components/PageHeader.vue';
import Pagination from '@/Components/Pagination.vue';
import StatusBadge from '@/Components/StatusBadge.vue';
import { money, datetime, methods as M } from '@/lib/format';

const props = defineProps({ sales: Object, totals: Object, filters: Object, users: Array, branches: Array, methods: Object });
const f = reactive({ search: '', from: '', to: '', status: '', type: 'sale', user_id: '', branch_id: '', method: '', ...props.filters });
const apply = () => router.get(route('sales.index'), Object.fromEntries(Object.entries(f).filter(([, v]) => v !== '' && v !== null)), { preserveState: true });
</script>

<template>
    <PageHeader :title="f.type === 'quote' ? 'Presupuestos' : 'Ventas'">
        <div class="inline-flex rounded-md border border-steel-300 bg-white p-0.5 text-sm">
            <button class="rounded px-2.5 py-1" :class="f.type === 'sale' ? 'bg-steel-800 text-white' : 'text-steel-600'" @click="f.type = 'sale'; apply()">Ventas</button>
            <button class="rounded px-2.5 py-1" :class="f.type === 'quote' ? 'bg-steel-800 text-white' : 'text-steel-600'" @click="f.type = 'quote'; apply()">Presupuestos</button>
        </div>
    </PageHeader>

    <div class="panel">
        <form class="flex flex-wrap items-end gap-2 border-b border-steel-200 px-4 py-3" @submit.prevent="apply">
            <div><label class="label">N° o cliente</label><input v-model="f.search" class="input w-44" /></div>
            <div><label class="label">Desde</label><input v-model="f.from" type="date" class="input" /></div>
            <div><label class="label">Hasta</label><input v-model="f.to" type="date" class="input" /></div>
            <div><label class="label">Sucursal</label><select v-model="f.branch_id" class="input w-auto"><option value="">Actual</option><option value="all">Todas</option><option v-for="b in branches" :key="b.id" :value="b.id">{{ b.name }}</option></select></div>
            <div><label class="label">Vendedor</label><select v-model="f.user_id" class="input w-auto"><option value="">Todos</option><option v-for="u in users" :key="u.id" :value="u.id">{{ u.name }}</option></select></div>
            <div v-if="f.type === 'sale'"><label class="label">Medio de pago</label><select v-model="f.method" class="input w-auto"><option value="">Todos</option><option v-for="(l, k) in methods" :key="k" :value="k">{{ l }}</option></select></div>
            <div v-if="f.type === 'sale'"><label class="label">Estado</label><select v-model="f.status" class="input w-auto"><option value="">Todos</option><option value="completed">Confirmadas</option><option value="cancelled">Anuladas</option></select></div>
            <button class="btn-dark">Filtrar</button>
        </form>
        <div v-if="f.type === 'sale'" class="flex flex-wrap gap-6 border-b border-steel-200 px-4 py-2.5 text-sm">
            <span>{{ totals.c }} ventas confirmadas</span>
            <span>Total <strong class="tabular-nums">{{ money(totals.t) }}</strong></span>
            <span v-if="Number(totals.r)">Devoluciones <strong class="tabular-nums">{{ money(totals.r) }}</strong></span>
        </div>
        <div class="overflow-x-auto">
            <table class="table">
                <thead><tr><th>N°</th><th>Fecha</th><th>Cliente</th><th>Vendedor</th><th>Sucursal</th><th>Pago</th><th class="num">Total</th><th>Estado</th></tr></thead>
                <tbody>
                    <tr v-for="s in sales.data" :key="s.id">
                        <td><Link :href="route('sales.show', s.id)" class="link font-medium">#{{ s.number }}</Link></td>
                        <td class="whitespace-nowrap">{{ datetime(s.created_at) }}</td>
                        <td>{{ s.customer?.name ?? 'Consumidor final' }}</td>
                        <td>{{ s.user?.name }}</td>
                        <td>{{ s.branch?.name }}</td>
                        <td class="text-xs text-steel-600">{{ s.payments.map((p) => M[p.method] ?? p.method).join(', ') }}</td>
                        <td class="num font-medium">{{ money(s.total) }}<div v-if="Number(s.returned_amount)" class="text-xs text-up">dev. {{ money(s.returned_amount) }}</div></td>
                        <td>
                            <StatusBadge :status="s.status" />
                            <Link v-if="s.type === 'quote'" :href="route('pos', { quote: s.id })" class="ml-2 text-xs link">Convertir en venta</Link>
                        </td>
                    </tr>
                    <tr v-if="!sales.data.length"><td colspan="8" class="py-6 text-center text-steel-500">Sin resultados.</td></tr>
                </tbody>
            </table>
        </div>
        <Pagination :data="sales" />
    </div>
</template>
