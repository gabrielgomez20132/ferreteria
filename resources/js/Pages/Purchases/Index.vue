<script setup>
import { reactive } from 'vue';
import { Link, router } from '@inertiajs/vue3';
import { Plus } from '@lucide/vue';
import PageHeader from '@/Components/PageHeader.vue';
import Pagination from '@/Components/Pagination.vue';
import StatusBadge from '@/Components/StatusBadge.vue';
import { money, date } from '@/lib/format';
import { useCan } from '@/lib/can';

const props = defineProps({ purchases: Object, suppliers: Array, filters: Object });
const can = useCan();
const f = reactive({ supplier_id: '', from: '', to: '', search: '', ...props.filters });
const apply = () => router.get(route('purchases.index'), Object.fromEntries(Object.entries(f).filter(([, v]) => v)), { preserveState: true });
</script>

<template>
    <PageHeader title="Compras">
        <Link v-if="can('purchases.manage')" :href="route('purchases.create')" class="btn-primary"><Plus class="size-4" /> Cargar compra</Link>
    </PageHeader>
    <div class="panel">
        <form class="flex flex-wrap items-end gap-2 border-b border-steel-200 px-4 py-3" @submit.prevent="apply">
            <div><label class="label">Proveedor</label><select v-model="f.supplier_id" class="input w-auto"><option value="">Todos</option><option v-for="s in suppliers" :key="s.id" :value="s.id">{{ s.name }}</option></select></div>
            <div><label class="label">Desde</label><input v-model="f.from" type="date" class="input" /></div>
            <div><label class="label">Hasta</label><input v-model="f.to" type="date" class="input" /></div>
            <div><label class="label">N° comprobante</label><input v-model="f.search" class="input w-40" /></div>
            <button class="btn-dark">Filtrar</button>
        </form>
        <div class="overflow-x-auto">
            <table class="table">
                <thead><tr><th>Fecha</th><th>Proveedor</th><th>Comprobante</th><th>Sucursal</th><th>Vence</th><th class="num">Total</th><th>Estado</th></tr></thead>
                <tbody>
                    <tr v-for="p in purchases.data" :key="p.id">
                        <td>{{ date(p.date) }}</td>
                        <td>{{ p.supplier?.name }}</td>
                        <td><Link :href="route('purchases.show', p.id)" class="link">{{ p.voucher_type }} {{ p.voucher_number || 's/n' }}</Link></td>
                        <td>{{ p.branch?.name }}</td>
                        <td>{{ date(p.due_date) }}</td>
                        <td class="num font-medium">{{ money(p.total) }}</td>
                        <td><StatusBadge :status="p.status" /></td>
                    </tr>
                    <tr v-if="!purchases.data.length"><td colspan="7" class="py-6 text-center text-steel-500">Sin compras registradas.</td></tr>
                </tbody>
            </table>
        </div>
        <Pagination :data="purchases" />
    </div>
</template>
