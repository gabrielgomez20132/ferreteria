<script setup>
import { ref } from 'vue';
import { Link, router, useForm } from '@inertiajs/vue3';
import { Pencil, Printer, Trash2 } from '@lucide/vue';
import PageHeader from '@/Components/PageHeader.vue';
import Variation from '@/Components/Variation.vue';
import Modal from '@/Components/Modal.vue';
import Field from '@/Components/Field.vue';
import { money, qty, pct, date, datetime, toNum } from '@/lib/format';
import { useCan } from '@/lib/can';

const props = defineProps({ product: Object, canCost: Boolean, lists: Array, branches: Array, history: Array, movements: Array, sales90: Object, supplierProducts: Array });
const can = useCan();
const p = props.product;
const tab = ref('stock');
const stockOf = (branchId) => p.stocks.find((s) => s.branch_id === branchId);
const totalStock = p.stocks.reduce((a, s) => a + toNum(s.quantity), 0);

const levels = ref(null);
const levelsForm = useForm({ min_stock: '', max_stock: '', location: '' });
const editLevels = (b) => {
    const s = stockOf(b.id);
    levels.value = b;
    Object.assign(levelsForm, { min_stock: s?.min_stock ?? '', max_stock: s?.max_stock ?? '', location: s?.location ?? '' });
};
const saveLevels = () => levelsForm.put(route('stock.levels', p.id), { preserveScroll: true, onSuccess: () => (levels.value = null) });
const destroy = () => confirm('¿Eliminar el producto? Se conserva su historial.') && router.delete(route('products.destroy', p.id));
const varOf = (a, b) => (toNum(a) > 0 ? ((toNum(b) - toNum(a)) / toNum(a)) * 100 : null);
const REASONS = { purchase: 'Compra', supplier_import: 'Lista proveedor', mass_update: 'Masiva', manual: 'Manual', revert: 'Reversión', review: 'Revisión' };
const MOVES = { initial: 'Inicial', purchase: 'Compra', sale: 'Venta', return: 'Devolución', cancel: 'Anulación', adjustment: 'Ajuste', transfer_in: 'Transf. entrada', transfer_out: 'Transf. salida', inventory: 'Inventario', purchase_cancel: 'Anul. compra' };
</script>

<template>
    <PageHeader :title="p.name" :back="route('products.index')">
        <template #subtitle>
            <p class="mt-0.5 flex flex-wrap items-center gap-2 text-sm text-steel-500">
                <span>{{ p.code }}</span><span v-if="p.barcode">· {{ p.barcode }}</span>
                <span v-if="p.category">· {{ p.category.name }}</span><span v-if="p.brand">· {{ p.brand.name }}</span>
                <span v-if="!p.is_active" class="badge-gray">Inactivo</span>
                <span v-if="p.allow_fraction" class="badge-blue">Se vende fraccionado</span>
            </p>
        </template>
        <Link :href="route('products.labels', { ids: String(p.id) })" class="btn-secondary"><Printer class="size-4" /> Etiqueta</Link>
        <Link v-if="can('products.manage')" :href="route('products.edit', p.id)" class="btn-primary"><Pencil class="size-4" /> Editar</Link>
        <button v-if="can('products.manage')" class="btn-ghost text-up" @click="destroy" aria-label="Eliminar"><Trash2 class="size-4" /></button>
    </PageHeader>

    <div class="panel mb-4 grid grid-cols-2 divide-steel-200 md:grid-cols-5 md:divide-x">
        <div class="p-4"><div class="text-sm text-steel-500">Precio de venta</div><div class="text-2xl font-semibold tabular-nums">{{ money(p.price) }}</div><div class="text-xs text-steel-500">IVA {{ toNum(p.vat_rate) }}% incluido · {{ date(p.price_updated_at) }}</div></div>
        <template v-if="canCost">
            <div class="p-4"><div class="text-sm text-steel-500">Costo de reposición</div><div class="text-2xl font-semibold tabular-nums">{{ money(p.cost) }}</div><div class="text-xs text-steel-500">neto sin IVA · {{ date(p.cost_updated_at) }}</div></div>
            <div class="p-4"><div class="text-sm text-steel-500">Margen real / objetivo</div><div class="text-2xl font-semibold tabular-nums" :class="toNum(p.price) < toNum(p.suggested_price) * 0.98 && 'text-up'">{{ pct(p.margin) }}</div><div class="text-xs text-steel-500">objetivo {{ pct(p.markup) }} s/costo · sugerido {{ money(p.suggested_price) }}</div></div>
            <div class="p-4"><div class="text-sm text-steel-500">Costo promedio / última compra</div><div class="text-base font-semibold tabular-nums">{{ money(p.average_cost) }}</div><div class="text-sm tabular-nums text-steel-600">{{ money(p.last_purchase_cost) }}</div></div>
        </template>
        <div class="p-4"><div class="text-sm text-steel-500">Stock total</div><div class="text-2xl font-semibold tabular-nums">{{ qty(totalStock) }} <span class="text-sm text-steel-500">{{ p.unit?.abbreviation }}</span></div><div class="text-xs text-steel-500">vendido 90 días: {{ qty(sales90.qty) }}</div></div>
    </div>

    <div class="mb-3 flex flex-wrap gap-1 border-b border-steel-200">
        <button v-for="(l, k) in { stock: 'Stock por sucursal', prices: 'Listas de precio', suppliers: 'Proveedores', history: 'Historial de precios', moves: 'Movimientos' }" :key="k" v-show="canCost || !['suppliers', 'history'].includes(k)"
            class="-mb-px border-b-2 px-3 py-2 text-sm" :class="tab === k ? 'border-hazard-500 font-medium' : 'border-transparent text-steel-500'" @click="tab = k">{{ l }}</button>
    </div>

    <div v-show="tab === 'stock'" class="panel">
        <table class="table">
            <thead><tr><th>Sucursal</th><th class="num">Stock</th><th class="num">Mínimo</th><th class="num">Máximo</th><th>Ubicación</th><th></th></tr></thead>
            <tbody>
                <tr v-for="b in branches" :key="b.id">
                    <td>{{ b.name }}</td>
                    <td class="num font-semibold">{{ qty(stockOf(b.id)?.quantity ?? 0) }}</td>
                    <td class="num">{{ qty(stockOf(b.id)?.min_stock ?? p.min_stock) }}</td>
                    <td class="num">{{ stockOf(b.id)?.max_stock !== undefined && stockOf(b.id)?.max_stock !== null ? qty(stockOf(b.id).max_stock) : '—' }}</td>
                    <td>{{ stockOf(b.id)?.location ?? p.location ?? '—' }}</td>
                    <td class="text-right"><button v-if="can('stock.adjust') && b.id === $page.props.branch?.id" class="text-xs link" @click="editLevels(b)">Mínimos y ubicación</button></td>
                </tr>
            </tbody>
        </table>
        <div v-if="p.purchase_unit_name" class="border-t border-steel-200 px-4 py-2.5 text-sm text-steel-600">Se compra por {{ p.purchase_unit_name }} = {{ qty(p.purchase_unit_factor) }} {{ p.unit?.abbreviation }}</div>
    </div>

    <div v-show="tab === 'prices'" class="panel">
        <table class="table">
            <thead><tr><th>Lista</th><th class="num">Precio</th><th></th></tr></thead>
            <tbody>
                <tr v-for="l in lists" :key="l.id"><td>{{ l.name }}</td><td class="num font-medium">{{ money(l.price) }}</td><td><span v-if="l.override" class="badge-blue">Precio especial</span></td></tr>
            </tbody>
        </table>
    </div>

    <div v-show="tab === 'suppliers'" class="panel">
        <table class="table">
            <thead><tr><th>Proveedor</th><th>Código</th><th class="num">Precio lista</th><th class="num">Unid. x bulto</th><th class="num">Costo neto</th><th>Actualizado</th></tr></thead>
            <tbody>
                <tr v-for="(sp, i) in supplierProducts" :key="sp.id">
                    <td><Link :href="route('suppliers.show', sp.supplier_id)" class="link">{{ sp.supplier?.name }}</Link> <span v-if="i === 0 && supplierProducts.length > 1" class="badge-down">más barato</span> <span v-if="sp.supplier_id === p.supplier_id" class="badge-gray">principal</span></td>
                    <td>{{ sp.supplier_code ?? '—' }}</td>
                    <td class="num">{{ money(sp.list_price) }}</td>
                    <td class="num">{{ Number(sp.pack_quantity) }}</td>
                    <td class="num font-medium">{{ money(sp.cost) }}</td>
                    <td>{{ date(sp.last_price_at) }}</td>
                </tr>
                <tr v-if="!supplierProducts.length"><td colspan="6" class="py-6 text-center text-steel-500">Sin proveedores vinculados.</td></tr>
            </tbody>
        </table>
    </div>

    <div v-show="tab === 'history'" class="panel">
        <table class="table">
            <thead><tr><th>Fecha</th><th>Origen</th><th class="num">Costo</th><th class="num">Var.</th><th class="num">Precio</th><th class="num">Var.</th><th>Usuario</th></tr></thead>
            <tbody>
                <tr v-for="h in history" :key="h.id">
                    <td class="whitespace-nowrap">{{ datetime(h.created_at) }}</td>
                    <td>{{ REASONS[h.reason] ?? h.reason }}<span v-if="h.supplier" class="text-xs text-steel-500"> · {{ h.supplier.name }}</span></td>
                    <td class="num">{{ money(h.old_cost) }} → {{ money(h.new_cost) }}</td>
                    <td class="num"><Variation :value="varOf(h.old_cost, h.new_cost)" /></td>
                    <td class="num">{{ money(h.old_price) }} → <strong>{{ money(h.new_price) }}</strong></td>
                    <td class="num"><Variation :value="varOf(h.old_price, h.new_price)" /></td>
                    <td>{{ h.user?.name }}</td>
                </tr>
                <tr v-if="!history.length"><td colspan="7" class="py-6 text-center text-steel-500">Sin cambios registrados.</td></tr>
            </tbody>
        </table>
    </div>

    <div v-show="tab === 'moves'" class="panel">
        <table class="table">
            <thead><tr><th>Fecha</th><th>Tipo</th><th>Sucursal</th><th class="num">Cantidad</th><th class="num">Saldo</th><th>Detalle</th></tr></thead>
            <tbody>
                <tr v-for="m in movements" :key="m.id">
                    <td class="whitespace-nowrap">{{ datetime(m.created_at) }}</td>
                    <td>{{ MOVES[m.type] ?? m.type }}</td>
                    <td>{{ m.branch?.name }}</td>
                    <td class="num font-medium" :class="toNum(m.quantity) < 0 ? 'text-up' : 'text-down'">{{ toNum(m.quantity) > 0 ? '+' : '' }}{{ qty(m.quantity) }}</td>
                    <td class="num">{{ qty(m.balance_after) }}</td>
                    <td class="text-steel-600">{{ m.notes }} <span class="text-xs text-steel-400">{{ m.user?.name }}</span></td>
                </tr>
            </tbody>
        </table>
        <div class="border-t border-steel-200 px-4 py-2.5"><Link :href="route('stock.movements', { product_id: p.id })" class="text-sm link">Ver todos los movimientos</Link></div>
    </div>

    <Modal :show="!!levels" :title="`Stock en ${levels?.name}`" @close="levels = null">
        <div class="grid grid-cols-3 gap-3">
            <Field label="Mínimo"><input v-model="levelsForm.min_stock" type="number" step="any" min="0" class="input" /></Field>
            <Field label="Máximo"><input v-model="levelsForm.max_stock" type="number" step="any" min="0" class="input" /></Field>
            <Field label="Ubicación"><input v-model="levelsForm.location" class="input" /></Field>
        </div>
        <template #footer>
            <button class="btn-secondary" @click="levels = null">Cancelar</button>
            <button class="btn-primary" @click="saveLevels">Guardar</button>
        </template>
    </Modal>
</template>
