<script setup>
import { ref } from 'vue';
import { Link, router } from '@inertiajs/vue3';
import { Plus } from '@lucide/vue';
import PageHeader from '@/Components/PageHeader.vue';
import Pagination from '@/Components/Pagination.vue';
import CustomerModal from '@/Components/CustomerModal.vue';
import { money } from '@/lib/format';
import { useCan } from '@/lib/can';

const props = defineProps({ customers: Object, filters: Object, priceLists: Array, totalDebt: Number });
const can = useCan();
const search = ref(props.filters.search ?? '');
const showNew = ref(false);
const go = (p) => router.get(route('customers.index'), { ...props.filters, ...p }, { preserveState: true });
</script>

<template>
    <PageHeader title="Clientes" :subtitle="`Total a cobrar en cuentas corrientes: ${money(totalDebt)}`">
        <button v-if="can('customers.manage')" class="btn-primary" @click="showNew = true"><Plus class="size-4" /> Nuevo cliente</button>
    </PageHeader>
    <div class="panel">
        <div class="flex flex-wrap items-center gap-3 border-b border-steel-200 px-4 py-3">
            <form @submit.prevent="go({ search })"><input v-model="search" class="input w-72" placeholder="Nombre, documento o teléfono" /></form>
            <label class="flex items-center gap-2 text-sm"><input type="checkbox" class="checkbox" :checked="!!filters.debt" @change="go({ debt: $event.target.checked ? 1 : undefined })" /> Con deuda</label>
            <label class="flex items-center gap-2 text-sm"><input type="checkbox" class="checkbox" :checked="!!filters.overdue" @change="go({ overdue: $event.target.checked ? 1 : undefined })" /> Con deuda vencida</label>
        </div>
        <div class="overflow-x-auto">
            <table class="table">
                <thead><tr><th>Cliente</th><th>Documento</th><th>Condición IVA</th><th>Teléfono</th><th>Lista</th><th class="num">Límite</th><th class="num">Saldo</th></tr></thead>
                <tbody>
                    <tr v-for="c in customers.data" :key="c.id">
                        <td><Link :href="route('customers.show', c.id)" class="link font-medium">{{ c.name }}</Link><span v-if="!c.is_active" class="badge-gray ml-2">Inactivo</span></td>
                        <td>{{ c.document_number ? c.document_type + ' ' + c.document_number : '—' }}</td>
                        <td>{{ c.tax_condition }}</td>
                        <td>{{ c.phone }}</td>
                        <td>{{ c.price_list?.name ?? '—' }}</td>
                        <td class="num">{{ c.allow_credit ? (Number(c.credit_limit) ? money(c.credit_limit) : 'Sin límite') : '—' }}</td>
                        <td class="num font-medium" :class="Number(c.balance) > 0 ? 'text-up' : Number(c.balance) < 0 ? 'text-down' : ''">{{ money(c.balance) }}</td>
                    </tr>
                    <tr v-if="!customers.data.length"><td colspan="7" class="py-6 text-center text-steel-500">No hay clientes que coincidan.</td></tr>
                </tbody>
            </table>
        </div>
        <Pagination :data="customers" />
    </div>
    <CustomerModal :show="showNew" :price-lists="priceLists" @close="showNew = false" />
</template>
