<script setup>
import { Link, router } from '@inertiajs/vue3';
import { Plus } from '@lucide/vue';
import PageHeader from '@/Components/PageHeader.vue';
import Pagination from '@/Components/Pagination.vue';
import StatusBadge from '@/Components/StatusBadge.vue';
import { money, date } from '@/lib/format';
import { useCan } from '@/lib/can';

defineProps({ orders: Object, suppliers: Array, filters: Object, methods: Object });
const can = useCan();
</script>

<template>
    <PageHeader title="Órdenes de pago" subtitle="Pagos a proveedores con uno o varios medios (transferencia, efectivo, cheques) imputados a sus facturas.">
        <select class="input w-auto" v-sel="filters.supplier_id ?? ''" @change="router.get(route('payment-orders.index'), { supplier_id: $event.target.value || undefined })">
            <option value="">Todos los proveedores</option><option v-for="s in suppliers" :key="s.id" :value="s.id">{{ s.name }}</option>
        </select>
        <Link v-if="can('suppliers.manage')" :href="route('payment-orders.create')" class="btn-primary"><Plus class="size-4" /> Nueva orden de pago</Link>
    </PageHeader>
    <div class="panel overflow-x-auto">
        <table class="table">
            <thead><tr><th>N°</th><th>Fecha</th><th>Proveedor</th><th>Medios de pago</th><th>Usuario</th><th class="num">Total</th><th>Estado</th></tr></thead>
            <tbody>
                <tr v-for="o in orders.data" :key="o.id">
                    <td><Link :href="route('payment-orders.show', o.id)" class="link font-medium">#{{ o.number }}</Link></td>
                    <td>{{ date(o.date) }}</td>
                    <td>{{ o.supplier?.name }}</td>
                    <td class="text-xs text-steel-600">{{ o.lines.map((l) => methods[l.method] ?? l.method).join(', ') }}</td>
                    <td>{{ o.user?.name }}</td>
                    <td class="num font-medium">{{ money(o.total) }}</td>
                    <td><StatusBadge :status="o.status" /></td>
                </tr>
                <tr v-if="!orders.data.length"><td colspan="7" class="py-6 text-center text-steel-500">Sin órdenes de pago.</td></tr>
            </tbody>
        </table>
        <Pagination :data="orders" />
    </div>
</template>
