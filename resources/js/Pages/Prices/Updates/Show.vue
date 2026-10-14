<script setup>
import { Link, router } from '@inertiajs/vue3';
import { Printer, Undo2 } from '@lucide/vue';
import PageHeader from '@/Components/PageHeader.vue';
import Pagination from '@/Components/Pagination.vue';
import StatusBadge from '@/Components/StatusBadge.vue';
import Variation from '@/Components/Variation.vue';
import { money, pct, datetime, toNum } from '@/lib/format';
import { useCan } from '@/lib/can';

const props = defineProps({ update: Object, items: Object });
const can = useCan();
const varOf = (a, b) => (toNum(a) > 0 ? ((toNum(b) - toNum(a)) / toNum(a)) * 100 : null);
const revert = () => confirm('¿Volver todos estos productos a su costo y precio anterior?') && router.post(route('prices.updates.revert', props.update.id));
</script>

<template>
    <PageHeader :title="update.description" :back="route('prices.updates')">
        <template #subtitle>
            <p class="mt-0.5 flex items-center gap-2 text-sm text-steel-500">
                {{ datetime(update.created_at) }} · {{ update.user?.name }} · {{ update.products_count }} productos <StatusBadge :status="update.status" />
                <span v-if="update.reverted_at">el {{ datetime(update.reverted_at) }} por {{ update.reverter?.name }}</span>
            </p>
        </template>
        <Link :href="route('products.labels', { price_update_id: update.id })" class="btn-secondary"><Printer class="size-4" /> Etiquetas</Link>
        <button v-if="update.status === 'applied' && can('prices.revert')" class="btn-danger" @click="revert"><Undo2 class="size-4" /> Revertir</button>
    </PageHeader>
    <div class="panel">
        <div class="overflow-x-auto">
            <table class="table">
                <thead><tr><th>Producto</th><th class="num">Costo anterior</th><th class="num">Costo nuevo</th><th class="num">Var.</th><th class="num">Precio anterior</th><th class="num">Precio nuevo</th><th class="num">Var.</th><th class="num">Margen obj.</th></tr></thead>
                <tbody>
                    <tr v-for="i in items.data" :key="i.id">
                        <td><Link :href="route('products.show', i.product_id)" class="link">{{ i.product?.name }}</Link><div class="code">{{ i.product?.code }}</div></td>
                        <td class="num text-steel-500">{{ money(i.old_cost) }}</td>
                        <td class="num">{{ money(i.new_cost) }}</td>
                        <td class="num"><Variation :value="varOf(i.old_cost, i.new_cost)" /></td>
                        <td class="num text-steel-500">{{ money(i.old_price) }}</td>
                        <td class="num font-medium">{{ money(i.new_price) }}</td>
                        <td class="num"><Variation :value="varOf(i.old_price, i.new_price)" /></td>
                        <td class="num">{{ pct(i.old_markup) }}<span v-if="toNum(i.old_markup) !== toNum(i.new_markup)"> → {{ pct(i.new_markup) }}</span></td>
                    </tr>
                </tbody>
            </table>
        </div>
        <Pagination :data="items" />
    </div>
</template>
