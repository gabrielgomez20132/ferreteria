<script setup>
import { Link, router } from '@inertiajs/vue3';
import PageHeader from '@/Components/PageHeader.vue';
import StatusBadge from '@/Components/StatusBadge.vue';
import Variation from '@/Components/Variation.vue';
import { money, qty, date, toNum } from '@/lib/format';
import { useCan } from '@/lib/can';

const props = defineProps({ purchase: Object, priceUpdate: Object });
const can = useCan();
const p = props.purchase;
const varOf = (i) => (toNum(i.previous_cost) > 0 ? ((toNum(i.new_cost) - toNum(i.previous_cost)) / toNum(i.previous_cost)) * 100 : null);
const cancel = () => confirm('¿Anular la compra? Se descontará el stock ingresado.') && router.post(route('purchases.cancel', p.id));
</script>

<template>
    <PageHeader :title="`${p.voucher_type} ${p.voucher_number || 's/n'} · ${p.supplier.name}`" :back="route('purchases.index')">
        <template #subtitle>
            <p class="mt-0.5 flex items-center gap-2 text-sm text-steel-500">
                {{ date(p.date) }} · {{ p.branch?.name }} · cargada por {{ p.user?.name }} · vence {{ date(p.due_date) }} <StatusBadge :status="p.status" />
                <Link v-if="p.purchase_order" :href="route('purchase-orders.show', p.purchase_order.id)" class="link">Pedido #{{ p.purchase_order.number }}</Link>
            </p>
        </template>
        <Link v-if="priceUpdate" :href="route('prices.updates.show', priceUpdate.id)" class="btn-secondary">Ver {{ priceUpdate.products_count }} cambios de precio</Link>
        <button v-if="p.status === 'confirmed' && can('purchases.manage')" class="btn-danger" @click="cancel">Anular</button>
    </PageHeader>
    <div class="grid gap-4 lg:grid-cols-[1fr_280px]">
        <div class="panel overflow-x-auto">
            <table class="table">
                <thead><tr><th>Producto</th><th class="num">Cantidad</th><th class="num">Ingresó al stock</th><th class="num">Costo unit.</th><th class="num">Bonif.</th><th class="num">Subtotal</th><th class="num">Costo ant.</th><th class="num">Costo nuevo</th></tr></thead>
                <tbody>
                    <tr v-for="i in p.items" :key="i.id">
                        <td><Link :href="route('products.show', i.product_id)" class="link">{{ i.description }}</Link><div class="code">{{ i.product?.code }}</div></td>
                        <td class="num">{{ qty(i.quantity) }}</td>
                        <td class="num">{{ qty(i.stock_quantity) }}</td>
                        <td class="num">{{ money(i.unit_cost) }}</td>
                        <td class="num">{{ toNum(i.discount_percent) ? i.discount_percent + '%' : '' }}</td>
                        <td class="num">{{ money(i.subtotal) }}</td>
                        <td class="num text-steel-500">{{ money(i.previous_cost) }}</td>
                        <td class="num font-medium">{{ money(i.new_cost) }} <Variation :value="varOf(i)" /></td>
                    </tr>
                </tbody>
            </table>
        </div>
        <div class="panel space-y-1.5 p-4 text-sm">
            <div class="flex justify-between"><span>Subtotal</span><span class="tabular-nums">{{ money(p.subtotal) }}</span></div>
            <div class="flex justify-between"><span>IVA</span><span class="tabular-nums">{{ money(p.vat_amount) }}</span></div>
            <div class="flex justify-between"><span>Percepciones</span><span class="tabular-nums">{{ money(p.other_taxes) }}</span></div>
            <div class="flex justify-between"><span>Flete / gastos</span><span class="tabular-nums">{{ money(p.extra_costs) }}</span></div>
            <div class="flex justify-between border-t border-steel-200 pt-1.5 text-base font-semibold"><span>Total</span><span class="tabular-nums">{{ money(p.total) }}</span></div>
            <p v-if="p.notes" class="pt-2 text-steel-600">{{ p.notes }}</p>
        </div>
    </div>
</template>
