<script setup>
import { computed } from 'vue';
import PageHeader from '@/Components/PageHeader.vue';
import ReportFilters from '@/Components/ReportFilters.vue';
import { money, qty, pct } from '@/lib/format';

const props = defineProps({ rows: Array, by: String, filters: Object, branches: Array });
const tot = computed(() => {
    const net = props.rows.reduce((a, r) => a + Number(r.net), 0);
    const cost = props.rows.reduce((a, r) => a + Number(r.cost), 0);
    return { net, cost, gross: net - cost, margin: net ? ((net - cost) / net) * 100 : 0 };
});
</script>

<template>
    <PageHeader title="Rentabilidad" subtitle="Ventas netas de IVA y devoluciones, menos el costo de la mercadería vendida." :back="route('reports.index')" />
    <ReportFilters :filters="{ by: 'category', ...filters }" :branches="branches" route-name="reports.profitability" v-slot="{ f }">
        <div><label class="label">Por</label>
            <select v-model="f.by" class="input w-auto"><option value="category">Categoría</option><option value="brand">Marca</option><option value="supplier">Proveedor</option><option value="product">Producto</option><option value="seller">Vendedor</option></select>
        </div>
    </ReportFilters>
    <div class="panel overflow-x-auto">
        <table class="table">
            <thead><tr><th>{{ { category: 'Categoría', brand: 'Marca', supplier: 'Proveedor', product: 'Producto', seller: 'Vendedor' }[by] }}</th><th class="num">Unidades</th><th class="num">Venta neta</th><th class="num">Costo</th><th class="num">Utilidad bruta</th><th class="num">Margen</th></tr></thead>
            <tbody>
                <tr v-for="r in rows" :key="r.label + (r.code ?? '')">
                    <td>{{ r.label }}<div v-if="r.code" class="code">{{ r.code }}</div></td>
                    <td class="num">{{ qty(r.qty) }}</td>
                    <td class="num">{{ money(r.net) }}</td>
                    <td class="num text-steel-500">{{ money(r.cost) }}</td>
                    <td class="num font-medium">{{ money(r.gross) }}</td>
                    <td class="num"><span :class="r.margin < 20 ? 'badge-up' : r.margin < 30 ? 'badge-warn' : 'badge-down'">{{ pct(r.margin) }}</span></td>
                </tr>
            </tbody>
            <tfoot><tr class="font-semibold"><td class="px-3 py-2">Total</td><td /><td class="num px-3">{{ money(tot.net) }}</td><td class="num px-3">{{ money(tot.cost) }}</td><td class="num px-3">{{ money(tot.gross) }}</td><td class="num px-3">{{ pct(tot.margin) }}</td></tr></tfoot>
        </table>
    </div>
</template>
