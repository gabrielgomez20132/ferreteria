<script setup>
import { ref } from 'vue';
import { Link, router, useForm } from '@inertiajs/vue3';
import { Pencil, FileSpreadsheet, HandCoins, ShoppingBag } from '@lucide/vue';
import PageHeader from '@/Components/PageHeader.vue';
import Pagination from '@/Components/Pagination.vue';
import StatusBadge from '@/Components/StatusBadge.vue';
import Variation from '@/Components/Variation.vue';
import Modal from '@/Components/Modal.vue';
import Field from '@/Components/Field.vue';
import SupplierModal from '@/Components/SupplierModal.vue';
import { money, date, datetime } from '@/lib/format';
import { useCan } from '@/lib/can';

const props = defineProps({ supplier: Object, products: Object, movements: Object, imports: Array, purchases: Array, methods: Object, filters: Object });
const can = useCan();
const tab = ref('products');
const showEdit = ref(false);
const showPay = ref(false);
const search = ref(props.filters.search ?? '');
const pay = useForm({ amount: '', method: 'transfer', from_cash: false, notes: '' });
const doPay = () => pay.post(route('suppliers.payment', props.supplier.id), { preserveScroll: true, onSuccess: () => { showPay.value = false; pay.reset(); } });

const editing = ref(null);
const spForm = useForm({ supplier_code: '', list_price: 0, discount_1: null, discount_2: null, discount_3: null, pack_quantity: 1 });
const editSp = (sp) => {
    editing.value = sp;
    Object.assign(spForm, { supplier_code: sp.supplier_code, list_price: sp.list_price, discount_1: sp.discount_1, discount_2: sp.discount_2, discount_3: sp.discount_3, pack_quantity: sp.pack_quantity });
};
const saveSp = () => spForm.put(route('suppliers.products.update', [props.supplier.id, editing.value.id]), { preserveScroll: true, onSuccess: () => (editing.value = null) });
const removeSp = (sp) => confirm('¿Quitar el vínculo con este artículo?') && router.delete(route('suppliers.products.destroy', [props.supplier.id, sp.id]), { preserveScroll: true });
const disc = [props.supplier.discount_1, props.supplier.discount_2, props.supplier.discount_3].map(Number).filter((x) => x);
</script>

<template>
    <PageHeader :title="supplier.name" :back="route('suppliers.index')">
        <template #subtitle>
            <p class="mt-0.5 text-sm text-steel-500">
                CUIT {{ supplier.cuit || '—' }} · {{ supplier.phone || 'sin teléfono' }} · pago a {{ supplier.payment_term_days }} días ·
                bonificaciones {{ disc.length ? disc.join('% + ') + '%' : 'ninguna' }} · {{ supplier.prices_include_vat ? 'precios con IVA' : 'precios sin IVA' }}
            </p>
        </template>
        <Link v-if="can('prices.manage')" :href="route('price-imports.index', { supplier_id: supplier.id })" class="btn-secondary"><FileSpreadsheet class="size-4" /> Actualizar lista</Link>
        <Link v-if="can('purchases.manage')" :href="route('purchases.create')" class="btn-secondary"><ShoppingBag class="size-4" /> Cargar compra</Link>
        <button v-if="can('suppliers.manage')" class="btn-secondary" @click="showEdit = true"><Pencil class="size-4" /> Editar</button>
        <button v-if="can('suppliers.manage')" class="btn-primary" @click="showPay = true"><HandCoins class="size-4" /> Registrar pago</button>
    </PageHeader>

    <div class="mb-4 flex gap-4 text-sm">
        <div class="panel px-4 py-3"><div class="text-steel-500">Saldo a pagar</div><div class="text-xl font-semibold tabular-nums" :class="Number(supplier.balance) > 0 && 'text-up'">{{ money(supplier.balance) }}</div></div>
        <div class="panel px-4 py-3"><div class="text-steel-500">Artículos vinculados</div><div class="text-xl font-semibold tabular-nums">{{ products.total }}</div></div>
    </div>

    <div class="mb-3 flex gap-1 border-b border-steel-200">
        <button v-for="(l, k) in { products: 'Artículos y costos', account: 'Cuenta corriente', history: 'Listas y compras' }" :key="k" class="-mb-px border-b-2 px-3 py-2 text-sm" :class="tab === k ? 'border-hazard-500 font-medium' : 'border-transparent text-steel-500'" @click="tab = k">{{ l }}</button>
    </div>

    <div v-show="tab === 'products'" class="panel">
        <div class="border-b border-steel-200 px-4 py-3">
            <form @submit.prevent="router.get(route('suppliers.show', supplier.id), { search }, { preserveState: true })"><input v-model="search" class="input w-72" placeholder="Código del proveedor o producto" /></form>
        </div>
        <div class="overflow-x-auto">
            <table class="table">
                <thead><tr><th>Cód. proveedor</th><th>Mi producto</th><th class="num">Precio lista</th><th class="num">Unid. x bulto</th><th class="num">Costo neto</th><th class="num">Mi costo</th><th class="num">Precio venta</th><th>Actualizado</th><th></th></tr></thead>
                <tbody>
                    <tr v-for="sp in products.data" :key="sp.id">
                        <td class="font-medium">{{ sp.supplier_code ?? '—' }}</td>
                        <td><Link :href="route('products.show', sp.product_id)" class="link">{{ sp.product?.name }}</Link><div class="code">{{ sp.product?.code }}<span v-if="sp.product?.supplier_id === supplier.id"> · proveedor principal</span></div></td>
                        <td class="num">{{ money(sp.list_price) }}</td>
                        <td class="num">{{ Number(sp.pack_quantity) }}</td>
                        <td class="num font-medium">{{ money(sp.cost) }}</td>
                        <td class="num text-steel-500">{{ money(sp.product?.cost) }}</td>
                        <td class="num">{{ money(sp.product?.price) }}</td>
                        <td class="whitespace-nowrap">{{ date(sp.last_price_at) }}</td>
                        <td class="text-right whitespace-nowrap">
                            <template v-if="can('suppliers.manage')">
                                <button class="text-xs link" @click="editSp(sp)">Editar</button>
                                <button class="ml-2 text-xs text-up hover:underline" @click="removeSp(sp)">Quitar</button>
                            </template>
                        </td>
                    </tr>
                    <tr v-if="!products.data.length"><td colspan="9" class="py-6 text-center text-steel-500">Sin artículos vinculados. Se vinculan al importar su lista de precios o al cargar compras.</td></tr>
                </tbody>
            </table>
        </div>
        <Pagination :data="products" />
    </div>

    <div v-show="tab === 'account'" class="panel">
        <table class="table">
            <thead><tr><th>Fecha</th><th>Concepto</th><th>Vence</th><th class="num">Debe</th><th class="num">Pago</th><th class="num">Saldo</th></tr></thead>
            <tbody>
                <tr v-for="m in movements.data" :key="m.id">
                    <td class="whitespace-nowrap">{{ datetime(m.created_at) }}</td>
                    <td>{{ m.description }}</td>
                    <td>{{ date(m.due_date) }}</td>
                    <td class="num">{{ Number(m.debit) ? money(m.debit) : '' }}</td>
                    <td class="num">{{ Number(m.credit) ? money(m.credit) : '' }}</td>
                    <td class="num font-medium">{{ money(m.balance_after) }}</td>
                </tr>
                <tr v-if="!movements.data.length"><td colspan="6" class="py-6 text-center text-steel-500">Sin movimientos.</td></tr>
            </tbody>
        </table>
        <Pagination :data="movements" />
    </div>

    <div v-show="tab === 'history'" class="grid gap-4 lg:grid-cols-2">
        <div class="panel">
            <div class="panel-head"><h2 class="panel-title">Listas de precios</h2></div>
            <table class="table">
                <tbody>
                    <tr v-for="i in imports" :key="i.id">
                        <td><Link :href="route('price-imports.review', i.id)" class="link">{{ i.file_name }}</Link><div class="code">{{ datetime(i.created_at) }}</div></td>
                        <td class="num"><Variation v-if="i.avg_variation !== null" :value="i.avg_variation" /></td>
                        <td><StatusBadge :status="i.status" /></td>
                    </tr>
                    <tr v-if="!imports.length"><td class="text-steel-500">Sin listas importadas.</td></tr>
                </tbody>
            </table>
        </div>
        <div class="panel">
            <div class="panel-head"><h2 class="panel-title">Compras</h2></div>
            <table class="table">
                <tbody>
                    <tr v-for="p in purchases" :key="p.id">
                        <td><Link :href="route('purchases.show', p.id)" class="link">{{ p.voucher_type }} {{ p.voucher_number }}</Link><div class="code">{{ date(p.date) }}</div></td>
                        <td class="num">{{ money(p.total) }}</td>
                        <td><StatusBadge :status="p.status" /></td>
                    </tr>
                    <tr v-if="!purchases.length"><td class="text-steel-500">Sin compras.</td></tr>
                </tbody>
            </table>
        </div>
    </div>

    <SupplierModal :show="showEdit" :supplier="supplier" @close="showEdit = false" />

    <Modal :show="showPay" title="Pago a proveedor" @close="showPay = false">
        <div class="grid grid-cols-2 gap-3">
            <Field label="Importe" :error="pay.errors.amount"><input v-model="pay.amount" type="number" step="0.01" min="0" class="input" /></Field>
            <Field label="Medio"><select v-model="pay.method" class="input"><option v-for="(l, k) in methods" :key="k" :value="k">{{ l }}</option></select></Field>
            <Field class="col-span-2" label="Observaciones"><input v-model="pay.notes" class="input" /></Field>
        </div>
        <label class="mt-3 flex items-center gap-2 text-sm"><input v-model="pay.from_cash" type="checkbox" class="checkbox" /> Sale de mi caja abierta</label>
        <template #footer>
            <button class="btn-secondary" @click="showPay = false">Cancelar</button>
            <button class="btn-primary" :disabled="pay.processing || !pay.amount" @click="doPay">Registrar pago</button>
        </template>
    </Modal>

    <Modal :show="!!editing" title="Artículo del proveedor" @close="editing = null">
        <div class="grid grid-cols-2 gap-3">
            <Field label="Código del proveedor"><input v-model="spForm.supplier_code" class="input" /></Field>
            <Field label="Precio de lista"><input v-model="spForm.list_price" type="number" step="0.0001" class="input" /></Field>
            <Field label="Bonif. 1 % (vacío = la del proveedor)"><input v-model="spForm.discount_1" type="number" step="0.01" class="input" /></Field>
            <Field label="Bonif. 2 %"><input v-model="spForm.discount_2" type="number" step="0.01" class="input" /></Field>
            <Field label="Bonif. 3 %"><input v-model="spForm.discount_3" type="number" step="0.01" class="input" /></Field>
            <Field label="Unidades por bulto"><input v-model="spForm.pack_quantity" type="number" step="any" min="0.0001" class="input" /></Field>
        </div>
        <template #footer>
            <button class="btn-secondary" @click="editing = null">Cancelar</button>
            <button class="btn-primary" :disabled="spForm.processing" @click="saveSp">Guardar</button>
        </template>
    </Modal>
</template>
