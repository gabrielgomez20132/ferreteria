<script setup>
import { Link, router } from '@inertiajs/vue3';
import { Plus } from '@lucide/vue';
import PageHeader from '@/Components/PageHeader.vue';
import Pagination from '@/Components/Pagination.vue';
import StatusBadge from '@/Components/StatusBadge.vue';
import { money, date } from '@/lib/format';
import { useCan } from '@/lib/can';

defineProps({ orders: Object, filters: Object });
const can = useCan();
</script>

<template>
    <PageHeader title="Pedidos a proveedores">
        <select class="input w-auto" v-sel="filters.status ?? ''" @change="router.get(route('purchase-orders.index'), { status: $event.target.value || undefined })">
            <option value="">Todos</option><option value="draft">Borradores</option><option value="sent">Enviados</option><option value="partial">Recibidos parcial</option><option value="received">Recibidos</option><option value="cancelled">Cancelados</option>
        </select>
        <Link v-if="can('purchases.manage')" :href="route('purchases.suggestions')" class="btn-secondary">Desde sugerencias</Link>
        <Link v-if="can('purchases.manage')" :href="route('purchase-orders.create')" class="btn-primary"><Plus class="size-4" /> Nuevo pedido</Link>
    </PageHeader>
    <div class="panel overflow-x-auto">
        <table class="table">
            <thead><tr><th>N°</th><th>Fecha</th><th>Proveedor</th><th>Sucursal</th><th class="num">Ítems</th><th>Entrega estimada</th><th class="num">Total estimado</th><th>Estado</th></tr></thead>
            <tbody>
                <tr v-for="o in orders.data" :key="o.id">
                    <td><Link :href="route('purchase-orders.show', o.id)" class="link font-medium">#{{ o.number }}</Link></td>
                    <td>{{ date(o.created_at) }}</td>
                    <td>{{ o.supplier?.name }}</td>
                    <td>{{ o.branch?.name }}</td>
                    <td class="num">{{ o.items_count }}</td>
                    <td>{{ date(o.expected_date) }}</td>
                    <td class="num">{{ money(o.total) }}</td>
                    <td><StatusBadge :status="o.status" /></td>
                </tr>
                <tr v-if="!orders.data.length"><td colspan="8" class="py-6 text-center text-steel-500">Sin pedidos.</td></tr>
            </tbody>
        </table>
        <Pagination :data="orders" />
    </div>
</template>
