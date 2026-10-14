<script setup>
import { Link } from '@inertiajs/vue3';
import { BarChart3, TrendingUp, Warehouse, Snail, ArrowDownWideNarrow, Users, Calculator } from '@lucide/vue';
import PageHeader from '@/Components/PageHeader.vue';

const reports = [
    { r: 'reports.sales', icon: BarChart3, t: 'Ventas', d: 'Por día, mes, hora, vendedor, sucursal o medio de pago. Productos más vendidos.' },
    { r: 'reports.profitability', icon: TrendingUp, t: 'Rentabilidad', d: 'Utilidad bruta y margen por categoría, marca, proveedor, producto o vendedor.' },
    { r: 'reports.results', icon: Calculator, t: 'Estado de resultados', d: 'Ventas netas, costo de mercadería, gastos operativos y resultado por mes.' },
    { r: 'reports.valuation', icon: Warehouse, t: 'Stock valorizado', d: 'Cuánto dinero hay en mercadería, a costo y a precio de venta.' },
    { r: 'reports.no-rotation', icon: Snail, t: 'Productos sin rotación', d: 'Mercadería con stock que no se vendió en los últimos días: capital inmovilizado.' },
    { r: 'reports.low-margin', icon: ArrowDownWideNarrow, t: 'Bajo margen', d: 'Productos cuyo margen real está por debajo de un umbral.' },
    { r: 'reports.accounts', icon: Users, t: 'Cuentas corrientes', d: 'Antigüedad de deuda de clientes y saldos con proveedores.' },
];
</script>

<template>
    <PageHeader title="Reportes" />
    <div class="grid gap-3 sm:grid-cols-2 xl:grid-cols-3">
        <Link v-for="x in reports" :key="x.r" :href="route(x.r)" class="panel flex gap-3 p-4 hover:border-steel-400">
            <component :is="x.icon" class="mt-0.5 size-5 shrink-0 text-steel-500" />
            <div><div class="font-semibold">{{ x.t }}</div><div class="text-sm text-steel-600">{{ x.d }}</div></div>
        </Link>
    </div>
</template>
