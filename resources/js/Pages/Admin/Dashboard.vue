<script setup>
import { Link } from '@inertiajs/vue3';
import PageHeader from '@/Components/PageHeader.vue';
import StatusBadge from '@/Components/StatusBadge.vue';
import { money, date } from '@/lib/format';

defineProps({ stats: Object, recent: Array, expiring: Array });
</script>

<template>
    <PageHeader title="Panel de la plataforma" />
    <div class="panel mb-4 grid grid-cols-2 divide-steel-200 md:grid-cols-5 md:divide-x">
        <div class="p-4"><div class="text-sm text-steel-500">Ingreso mensual (MRR)</div><div class="text-2xl font-semibold tabular-nums">{{ money(stats.mrr) }}</div></div>
        <div class="p-4"><div class="text-sm text-steel-500">Empresas activas</div><div class="text-2xl font-semibold">{{ stats.active }}</div></div>
        <div class="p-4"><div class="text-sm text-steel-500">En prueba</div><div class="text-2xl font-semibold">{{ stats.trial }}</div></div>
        <div class="p-4"><div class="text-sm text-steel-500">Suspendidas</div><div class="text-2xl font-semibold">{{ stats.suspended }}</div></div>
        <div class="p-4"><div class="text-sm text-steel-500">Ventas (30 días)</div><div class="text-2xl font-semibold">{{ stats.sales_30d }}</div><div class="text-xs text-steel-500">{{ stats.users }} usuarios</div></div>
    </div>
    <div class="grid gap-4 lg:grid-cols-2">
        <div class="panel">
            <div class="panel-head"><h2 class="panel-title">Últimas altas</h2><Link :href="route('admin.tenants.index')" class="text-sm link">Ver todas</Link></div>
            <table class="table"><tbody><tr v-for="t in recent" :key="t.id"><td>{{ t.name }}<div class="text-xs text-steel-500">{{ t.email }}</div></td><td>{{ t.plan?.name }}</td><td><StatusBadge :status="t.status" /></td><td>{{ date(t.created_at) }}</td></tr></tbody></table>
        </div>
        <div class="panel">
            <div class="panel-head"><h2 class="panel-title">Pruebas que vencen en 5 días</h2></div>
            <table class="table"><tbody>
                <tr v-for="t in expiring" :key="t.id"><td>{{ t.name }}</td><td>{{ t.email }}</td><td>{{ date(t.trial_ends_at) }}</td></tr>
                <tr v-if="!expiring.length"><td class="text-steel-500">Ninguna.</td></tr>
            </tbody></table>
        </div>
    </div>
</template>
