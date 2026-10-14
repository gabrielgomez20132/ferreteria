<script setup>
import { reactive } from 'vue';
import { Link, router } from '@inertiajs/vue3';
import { Plus } from '@lucide/vue';
import PageHeader from '@/Components/PageHeader.vue';
import Pagination from '@/Components/Pagination.vue';
import { qty, money, datetime, toNum } from '@/lib/format';
import { useCan } from '@/lib/can';

const props = defineProps({ movements: Object, filters: Object, branches: Array, types: Object, product: Object });
const can = useCan();
const f = reactive({ branch_id: '', type: '', from: '', to: '', product_id: '', ...props.filters });
const apply = () => router.get(route('stock.movements'), Object.fromEntries(Object.entries(f).filter(([, v]) => v)), { preserveState: true });
</script>

<template>
    <PageHeader title="Movimientos de stock" :subtitle="product ? `${product.code} · ${product.name}` : 'Cada entrada y salida queda registrada con su saldo'">
        <Link v-if="can('stock.adjust')" :href="route('stock.adjust')" class="btn-primary"><Plus class="size-4" /> Ajuste</Link>
    </PageHeader>
    <div class="panel">
        <form class="flex flex-wrap items-end gap-2 border-b border-steel-200 px-4 py-3" @submit.prevent="apply">
            <div><label class="label">Sucursal</label><select v-model="f.branch_id" class="input w-auto"><option value="">Todas</option><option v-for="b in branches" :key="b.id" :value="b.id">{{ b.name }}</option></select></div>
            <div><label class="label">Tipo</label><select v-model="f.type" class="input w-auto"><option value="">Todos</option><option v-for="(l, k) in types" :key="k" :value="k">{{ l }}</option></select></div>
            <div><label class="label">Desde</label><input v-model="f.from" type="date" class="input" /></div>
            <div><label class="label">Hasta</label><input v-model="f.to" type="date" class="input" /></div>
            <button class="btn-dark">Filtrar</button>
            <button v-if="product" type="button" class="btn-ghost" @click="f.product_id = ''; apply()">Ver todos los productos</button>
        </form>
        <div class="overflow-x-auto">
            <table class="table">
                <thead><tr><th>Fecha</th><th>Producto</th><th>Sucursal</th><th>Tipo</th><th class="num">Cantidad</th><th class="num">Saldo</th><th class="num">Costo</th><th>Detalle</th></tr></thead>
                <tbody>
                    <tr v-for="m in movements.data" :key="m.id">
                        <td class="whitespace-nowrap">{{ datetime(m.created_at) }}</td>
                        <td><Link :href="route('products.show', m.product_id)" class="link">{{ m.product?.name }}</Link><div class="code">{{ m.product?.code }}</div></td>
                        <td>{{ m.branch?.name }}</td>
                        <td>{{ types[m.type] ?? m.type }}</td>
                        <td class="num font-medium" :class="toNum(m.quantity) < 0 ? 'text-up' : 'text-down'">{{ toNum(m.quantity) > 0 ? '+' : '' }}{{ qty(m.quantity) }}</td>
                        <td class="num">{{ qty(m.balance_after) }}</td>
                        <td class="num text-steel-500">{{ money(m.unit_cost) }}</td>
                        <td class="text-steel-600">{{ m.notes }} <span class="text-xs text-steel-400">{{ m.user?.name }}</span></td>
                    </tr>
                    <tr v-if="!movements.data.length"><td colspan="8" class="py-6 text-center text-steel-500">Sin movimientos.</td></tr>
                </tbody>
            </table>
        </div>
        <Pagination :data="movements" />
    </div>
</template>
