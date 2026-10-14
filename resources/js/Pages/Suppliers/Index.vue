<script setup>
import { ref } from 'vue';
import { Link, router } from '@inertiajs/vue3';
import { Plus } from '@lucide/vue';
import PageHeader from '@/Components/PageHeader.vue';
import Pagination from '@/Components/Pagination.vue';
import SupplierModal from '@/Components/SupplierModal.vue';
import { money, date } from '@/lib/format';
import { useCan } from '@/lib/can';

const props = defineProps({ suppliers: Object, filters: Object, totalDebt: Number });
const can = useCan();
const search = ref(props.filters.search ?? '');
const showNew = ref(false);
const go = (p) => router.get(route('suppliers.index'), { ...props.filters, ...p }, { preserveState: true });
const disc = (s) => [s.discount_1, s.discount_2, s.discount_3].map(Number).filter((x) => x).map((x) => x + '%').join(' + ') || '—';
</script>

<template>
    <PageHeader title="Proveedores" :subtitle="`Deuda total con proveedores: ${money(totalDebt)}`">
        <button v-if="can('suppliers.manage')" class="btn-primary" @click="showNew = true"><Plus class="size-4" /> Nuevo proveedor</button>
    </PageHeader>
    <div class="panel">
        <div class="flex flex-wrap items-center gap-3 border-b border-steel-200 px-4 py-3">
            <form @submit.prevent="go({ search })"><input v-model="search" class="input w-72" placeholder="Nombre o CUIT" /></form>
            <label class="flex items-center gap-2 text-sm"><input type="checkbox" class="checkbox" :checked="!!filters.debt" @change="go({ debt: $event.target.checked ? 1 : undefined })" /> Con deuda</label>
        </div>
        <div class="overflow-x-auto">
            <table class="table">
                <thead><tr><th>Proveedor</th><th>CUIT</th><th>Teléfono</th><th>Bonificaciones</th><th class="num">Artículos</th><th>Última lista</th><th class="num">Saldo</th></tr></thead>
                <tbody>
                    <tr v-for="s in suppliers.data" :key="s.id">
                        <td><Link :href="route('suppliers.show', s.id)" class="link font-medium">{{ s.name }}</Link></td>
                        <td>{{ s.cuit }}</td>
                        <td>{{ s.phone }}</td>
                        <td>{{ disc(s) }}</td>
                        <td class="num">{{ s.supplier_products_count }}</td>
                        <td>{{ date(s.last_price_at) }}</td>
                        <td class="num font-medium" :class="Number(s.balance) > 0 && 'text-up'">{{ money(s.balance) }}</td>
                    </tr>
                </tbody>
            </table>
        </div>
        <Pagination :data="suppliers" />
    </div>
    <SupplierModal :show="showNew" @close="showNew = false" />
</template>
