<script setup>
import { computed, ref } from 'vue';
import { Link, router, useForm } from '@inertiajs/vue3';
import { Plus, Download } from '@lucide/vue';
import PageHeader from '@/Components/PageHeader.vue';
import Pagination from '@/Components/Pagination.vue';
import StatusBadge from '@/Components/StatusBadge.vue';
import Modal from '@/Components/Modal.vue';
import Field from '@/Components/Field.vue';
import { money, qty, date } from '@/lib/format';

const props = defineProps({ orders: Object, requirements: Array, pendingCount: Number, products: Array, filters: Object });
const show = ref(false);
const form = useForm({ product_id: '', quantity: 1, due_date: '', notes: '' });
const submit = () => form.transform((d) => ({ ...d, due_date: d.due_date || null })).post(route('stock.production.store'));
const missingCost = computed(() => props.requirements.reduce((a, r) => a + r.missing * r.cost, 0));
const missingCount = computed(() => props.requirements.filter((r) => r.missing > 0).length);
</script>

<template>
    <PageHeader title="Producción" subtitle="Órdenes para fabricar productos a partir de insumos. Al completarlas se descuentan los insumos y se ingresa el producto terminado.">
        <select class="input w-auto" v-sel="filters.status ?? ''" @change="router.get(route('stock.production.index'), { status: $event.target.value || undefined })">
            <option value="">Todas</option><option value="pending">Pendientes</option><option value="completed">Completadas</option><option value="cancelled">Canceladas</option>
        </select>
        <button class="btn-primary" @click="show = true"><Plus class="size-4" /> Nueva orden</button>
    </PageHeader>

    <div class="grid gap-4 xl:grid-cols-[1fr_440px]">
        <div class="panel overflow-x-auto">
            <table class="table">
                <thead><tr><th>N°</th><th>Fecha</th><th>Producto</th><th class="num">Cantidad</th><th>Para el</th><th>Sucursal</th><th>Estado</th></tr></thead>
                <tbody>
                    <tr v-for="o in orders.data" :key="o.id">
                        <td><Link :href="route('stock.production.show', o.id)" class="link font-medium">#{{ o.number }}</Link></td>
                        <td>{{ date(o.created_at) }}</td>
                        <td>{{ o.product?.name }}<div class="code">{{ o.product?.code }}</div></td>
                        <td class="num">{{ qty(o.quantity) }}</td>
                        <td>{{ date(o.due_date) }}</td>
                        <td>{{ o.branch?.name }}</td>
                        <td><StatusBadge :status="o.status" :labels="{ completed: 'Completada' }" /></td>
                    </tr>
                    <tr v-if="!orders.data.length"><td colspan="7" class="py-8 text-center text-steel-500">Sin órdenes de producción. Primero marcá un producto como "Fabricado" y cargale sus insumos.</td></tr>
                </tbody>
            </table>
            <Pagination :data="orders" />
        </div>

        <div class="panel h-fit">
            <div class="panel-head">
                <div>
                    <h2 class="panel-title">Insumos para órdenes pendientes</h2>
                    <p class="text-xs text-steel-500">{{ pendingCount }} órdenes en esta sucursal<span v-if="missingCount"> · faltan {{ missingCount }} insumos ({{ money(missingCost) }} a costo)</span></p>
                </div>
                <a :href="route('stock.production.requirements')" class="btn-secondary btn-sm" :class="!requirements.length && 'pointer-events-none opacity-50'"><Download class="size-3.5" /> Excel</a>
            </div>
            <table class="table">
                <thead><tr><th>Insumo</th><th class="num">Necesario</th><th class="num">Stock</th><th class="num">Falta</th></tr></thead>
                <tbody>
                    <tr v-for="r in requirements" :key="r.id">
                        <td>{{ r.name }}<div class="code">{{ r.code }}</div></td>
                        <td class="num">{{ qty(r.required) }} {{ r.unit }}</td>
                        <td class="num">{{ qty(r.stock) }}</td>
                        <td class="num font-semibold" :class="r.missing > 0 ? 'text-up' : 'text-down'">{{ r.missing > 0 ? qty(r.missing) : 'OK' }}</td>
                    </tr>
                    <tr v-if="!requirements.length"><td colspan="4" class="py-6 text-center text-steel-500">No hay órdenes pendientes.</td></tr>
                </tbody>
            </table>
            <div v-if="missingCount" class="border-t border-steel-200 px-4 py-2.5"><Link :href="route('purchases.suggestions')" class="text-sm link">Ver sugerencias de compra</Link></div>
        </div>
    </div>

    <Modal :show="show" title="Nueva orden de producción" @close="show = false">
        <div class="grid gap-3">
            <Field label="Producto a fabricar" :error="form.errors.product_id">
                <select v-model="form.product_id" class="input">
                    <option value="" disabled>Elegí…</option>
                    <option v-for="p in products" :key="p.id" :value="p.id" :disabled="!p.components_count">{{ p.code }} · {{ p.name }}{{ p.components_count ? '' : ' (sin insumos cargados)' }}</option>
                </select>
            </Field>
            <p v-if="!products.length" class="text-sm text-steel-500">No hay productos fabricados. En la ficha del producto elegí el tipo "Producto fabricado" y cargá sus insumos.</p>
            <div class="grid grid-cols-2 gap-3">
                <Field label="Cantidad a producir" :error="form.errors.quantity"><input v-model="form.quantity" type="number" min="0" step="any" class="input" /></Field>
                <Field label="Para el día"><input v-model="form.due_date" type="date" class="input" /></Field>
            </div>
            <Field label="Observaciones"><input v-model="form.notes" class="input" /></Field>
        </div>
        <template #footer><button class="btn-secondary" @click="show = false">Cancelar</button><button class="btn-primary" :disabled="form.processing || !form.product_id" @click="submit">Crear orden</button></template>
    </Modal>
</template>
