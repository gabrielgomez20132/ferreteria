<script setup>
import { Link, router } from '@inertiajs/vue3';
import { Plus } from '@lucide/vue';
import PageHeader from '@/Components/PageHeader.vue';
import Pagination from '@/Components/Pagination.vue';
import StatusBadge from '@/Components/StatusBadge.vue';
import { datetime } from '@/lib/format';

defineProps({ transfers: Object, filters: Object });
</script>

<template>
    <PageHeader title="Transferencias entre sucursales">
        <select class="input w-auto" v-sel="filters.status ?? ''" @change="router.get(route('stock.transfers.index'), { status: $event.target.value || undefined })">
            <option value="">Todas</option><option value="in_transit">En tránsito</option><option value="received">Recibidas</option><option value="cancelled">Canceladas</option>
        </select>
        <Link :href="route('stock.transfers.create')" class="btn-primary"><Plus class="size-4" /> Nueva transferencia</Link>
    </PageHeader>
    <div class="panel overflow-x-auto">
        <table class="table">
            <thead><tr><th>N°</th><th>Fecha</th><th>Desde</th><th>Hacia</th><th class="num">Ítems</th><th>Envió</th><th>Estado</th></tr></thead>
            <tbody>
                <tr v-for="t in transfers.data" :key="t.id">
                    <td><Link :href="route('stock.transfers.show', t.id)" class="link font-medium">#{{ t.number }}</Link></td>
                    <td>{{ datetime(t.created_at) }}</td>
                    <td>{{ t.from_branch?.name }}</td>
                    <td>{{ t.to_branch?.name }}</td>
                    <td class="num">{{ t.items_count }}</td>
                    <td>{{ t.user?.name }}</td>
                    <td><StatusBadge :status="t.status" /></td>
                </tr>
                <tr v-if="!transfers.data.length"><td colspan="7" class="py-6 text-center text-steel-500">Sin transferencias.</td></tr>
            </tbody>
        </table>
        <Pagination :data="transfers" />
    </div>
</template>
