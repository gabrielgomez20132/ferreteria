<script setup>
import { computed, ref, watch } from 'vue';
import { Link, router, usePage } from '@inertiajs/vue3';
import {
    LayoutDashboard, ShoppingCart, Receipt, Users, Wallet, Tags, FileSpreadsheet, AlertTriangle, Percent, Scale,
    History, Printer, Package, FolderTree, Upload, Boxes, PackageMinus, ArrowLeftRight, ClipboardCheck, Truck,
    ShoppingBag, ClipboardList, Lightbulb, BadgeDollarSign, BarChart3, Building2, Store, UserCog, ShieldCheck,
    ScrollText, LogOut, Menu, X, ChevronDown, Server, CreditCard, Sliders, Factory, Banknote, Bell, FileText,
} from '@lucide/vue';
import { useCan } from '@/lib/can';
import Toasts from '@/Components/Toasts.vue';

const page = usePage();
const can = useCan();
const open = ref(false);

const user = computed(() => page.props.auth.user);
const tenant = computed(() => page.props.tenant);

const sections = computed(() => {
    if (user.value?.is_super_admin && !tenant.value) {
        return [{ title: 'Plataforma', items: [
            { label: 'Panel SaaS', href: route('admin.dashboard'), icon: Server, active: 'admin.dashboard' },
            { label: 'Empresas', href: route('admin.tenants.index'), icon: Building2, active: 'admin.tenants.*' },
            { label: 'Planes', href: route('admin.plans.index'), icon: CreditCard, active: 'admin.plans.*' },
        ] }];
    }
    const s = [
        { title: null, items: [
            { label: 'Tablero', href: route('dashboard'), icon: LayoutDashboard, active: 'dashboard', perm: ['dashboard.view'] },
        ] },
        { title: 'Ventas', items: [
            { label: 'Punto de venta', href: route('pos'), icon: ShoppingCart, active: 'pos', perm: ['sales.create'], key: 'F2' },
            { label: 'Ventas', href: route('sales.index'), icon: Receipt, active: 'sales.*', perm: ['sales.view'] },
            { label: 'Presupuestos', href: route('quotes.index'), icon: FileText, active: 'quotes.*', perm: ['sales.view', 'sales.create'] },
            { label: 'Clientes', href: route('customers.index'), icon: Users, active: 'customers.*', perm: ['customers.view'] },
            { label: 'Caja', href: route('cash.index'), icon: Wallet, active: 'cash.*', perm: ['cash.operate', 'cash.manage'] },
        ] },
        { title: 'Precios', highlight: true, items: [
            { label: 'Listas de proveedores', href: route('price-imports.index'), icon: FileSpreadsheet, active: 'price-imports.*', perm: ['prices.manage'] },
            { label: 'Precios a revisar', href: route('prices.review'), icon: AlertTriangle, active: 'prices.review', perm: ['prices.manage'] },
            { label: 'Actualización masiva', href: route('prices.mass'), icon: Percent, active: 'prices.mass*', perm: ['prices.manage'] },
            { label: 'Comparar proveedores', href: route('prices.comparison'), icon: Scale, active: 'prices.comparison', perm: ['prices.manage'] },
            { label: 'Historial de cambios', href: route('prices.updates'), icon: History, active: 'prices.updates*', perm: ['prices.manage'] },
            { label: 'Etiquetas', href: route('products.labels'), icon: Printer, active: 'products.labels', perm: ['products.view'] },
        ] },
        { title: 'Catálogo', items: [
            { label: 'Productos', href: route('products.index'), icon: Package, active: ['products.index', 'products.show', 'products.create', 'products.edit'], perm: ['products.view'] },
            { label: 'Categorías y marcas', href: route('catalog.index', 'categorias'), icon: FolderTree, active: 'catalog.*', perm: ['products.manage'] },
            { label: 'Importar desde Excel', href: route('products.import'), icon: Upload, active: 'products.import', perm: ['products.import'] },
        ] },
        { title: 'Stock', items: [
            { label: 'Stock por sucursal', href: route('stock.index'), icon: Boxes, active: 'stock.index', perm: ['stock.view'] },
            { label: 'Alertas de reposición', href: route('stock.low'), icon: PackageMinus, active: 'stock.low', perm: ['stock.view'] },
            { label: 'Movimientos', href: route('stock.movements'), icon: Sliders, active: ['stock.movements', 'stock.adjust'], perm: ['stock.view'] },
            { label: 'Transferencias', href: route('stock.transfers.index'), icon: ArrowLeftRight, active: 'stock.transfers.*', perm: ['stock.transfer'] },
            { label: 'Inventario físico', href: route('stock.inventory.index'), icon: ClipboardCheck, active: 'stock.inventory.*', perm: ['inventory.manage'] },
            { label: 'Producción', href: route('stock.production.index'), icon: Factory, active: 'stock.production.*', perm: ['production.manage'] },
        ] },
        { title: 'Compras', items: [
            { label: 'Proveedores', href: route('suppliers.index'), icon: Truck, active: 'suppliers.*', perm: ['suppliers.view'] },
            { label: 'Compras', href: route('purchases.index'), icon: ShoppingBag, active: ['purchases.index', 'purchases.create', 'purchases.show'], perm: ['purchases.view'] },
            { label: 'Pedidos', href: route('purchase-orders.index'), icon: ClipboardList, active: 'purchase-orders.*', perm: ['purchases.view'] },
            { label: 'Órdenes de pago', href: route('payment-orders.index'), icon: Banknote, active: 'payment-orders.*', perm: ['suppliers.view'] },
            { label: 'Sugerencias de compra', href: route('purchases.suggestions'), icon: Lightbulb, active: 'purchases.suggestions', perm: ['purchases.view'] },
        ] },
        { title: 'Gestión', items: [
            { label: 'Gastos', href: route('expenses.index'), icon: BadgeDollarSign, active: 'expenses.*', perm: ['expenses.manage'] },
            { label: 'Reportes', href: route('reports.index'), icon: BarChart3, active: 'reports.*', perm: ['reports.view'] },
        ] },
        { title: 'Configuración', items: [
            { label: 'Empresa', href: route('settings.company'), icon: Building2, active: 'settings.*', perm: ['settings.manage'] },
            { label: 'Sucursales y depósitos', href: route('branches.index'), icon: Store, active: 'branches.*', perm: ['branches.manage'] },
            { label: 'Usuarios', href: route('users.index'), icon: UserCog, active: 'users.*', perm: ['users.manage'] },
            { label: 'Roles y permisos', href: route('roles.index'), icon: ShieldCheck, active: 'roles.*', perm: ['users.manage'] },
            { label: 'Auditoría', href: route('audit.index'), icon: ScrollText, active: 'audit.*', perm: ['audit.view'] },
        ] },
    ];
    return s
        .map((sec) => ({ ...sec, items: sec.items.filter((i) => !i.perm || can(...i.perm)) }))
        .filter((sec) => sec.items.length);
});

const isActive = (item) => [].concat(item.active).some((a) => route().current(a));

const branchId = computed({
    get: () => page.props.branch?.id,
    set: (id) => router.post(route('branch.switch'), { branch_id: id }, { preserveScroll: true }),
});
const logout = () => router.post(route('logout'));

watch(() => page.url, () => (open.value = false));

const trialDays = computed(() => {
    if (tenant.value?.status !== 'trial' || !tenant.value.trial_ends_at) return null;
    return Math.max(0, Math.ceil((new Date(tenant.value.trial_ends_at) - new Date()) / 86400000));
});

// Atajo global: F2 abre el punto de venta
if (typeof window !== 'undefined') {
    window.onkeydown = (e) => {
        if (e.key === 'F2' && can('sales.create') && !route().current('pos')) {
            e.preventDefault();
            router.visit(route('pos'));
        }
    };
}
</script>

<template>
    <div class="min-h-screen lg:flex">
        <!-- Barra lateral -->
        <aside
            :class="open ? 'translate-x-0' : '-translate-x-full lg:translate-x-0'"
            class="no-print fixed inset-y-0 left-0 z-40 flex w-60 flex-col bg-steel-800 text-steel-200 transition-transform lg:sticky lg:top-0 lg:h-screen"
        >
            <div class="flex items-center gap-2.5 px-4 pt-4 pb-3">
                <svg viewBox="0 0 32 32" class="size-7 shrink-0"><rect width="32" height="32" rx="6" fill="#F2B705" /><path d="M9 8h14v4h-5v12h-4V12H9z" fill="#1F2A37" /></svg>
                <div class="min-w-0">
                    <div class="truncate text-[15px] font-semibold text-white">{{ tenant?.name ?? 'FerreteroSoft' }}</div>
                    <div class="text-[11px] text-steel-400">FerreteroSoft{{ tenant?.plan ? ' · ' + tenant.plan : '' }}</div>
                </div>
                <button class="ml-auto text-steel-400 lg:hidden" @click="open = false" aria-label="Cerrar menú"><X class="size-5" /></button>
            </div>

            <nav class="flex-1 overflow-y-auto px-2 pb-4 text-[13.5px]">
                <div v-for="(sec, si) in sections" :key="si" class="mt-3">
                    <div v-if="sec.title" class="mb-1 px-2 text-[11px] font-medium text-steel-400" :class="sec.highlight && 'text-hazard-400'">
                        {{ sec.title }}
                    </div>
                    <Link
                        v-for="item in sec.items" :key="item.label" :href="item.href"
                        class="group relative flex items-center gap-2.5 rounded-md px-2 py-1.5 hover:bg-steel-700 hover:text-white"
                        :class="isActive(item) ? 'bg-steel-700 text-white' : ''"
                    >
                        <span v-if="isActive(item)" class="absolute inset-y-1 left-0 w-1 rounded-r bg-hazard-500" />
                        <component :is="item.icon" class="size-4 shrink-0" :class="isActive(item) ? 'text-hazard-400' : 'text-steel-400 group-hover:text-steel-200'" />
                        <span class="truncate">{{ item.label }}</span>
                        <kbd v-if="item.key" class="ml-auto rounded border border-steel-600 px-1 text-[10px] text-steel-400">{{ item.key }}</kbd>
                    </Link>
                </div>
            </nav>

            <div class="border-t border-steel-700 px-3 py-3 text-xs">
                <div class="truncate font-medium text-white">{{ user?.name }}</div>
                <div class="truncate text-steel-400">{{ user?.role ?? 'Super administrador' }}</div>
                <button class="mt-2 flex items-center gap-1.5 text-steel-300 hover:text-white" @click="logout"><LogOut class="size-3.5" /> Cerrar sesión</button>
            </div>
        </aside>
        <div v-if="open" class="fixed inset-0 z-30 bg-steel-900/40 lg:hidden" @click="open = false" />

        <!-- Contenido -->
        <div class="min-w-0 flex-1">
            <header class="no-print sticky top-0 z-20 flex h-12 items-center gap-3 border-b border-steel-200 bg-white/95 px-4 backdrop-blur">
                <button class="text-steel-600 lg:hidden" @click="open = true" aria-label="Abrir menú"><Menu class="size-5" /></button>
                <div v-if="page.props.branches?.length" class="flex items-center gap-2">
                    <Store class="size-4 text-steel-400" />
                    <label class="sr-only" for="branch">Sucursal</label>
                    <select
                        id="branch" v-model="branchId"
                        class="rounded-md border-0 bg-transparent py-1 pr-7 pl-1 text-sm font-medium text-steel-800 focus:ring-2 focus:ring-link/20"
                        :disabled="page.props.branches.length < 2"
                    >
                        <option v-for="b in page.props.branches" :key="b.id" :value="b.id">{{ b.name }}{{ b.type === 'warehouse' ? ' (depósito)' : '' }}</option>
                    </select>
                </div>
                <div v-if="trialDays !== null" class="badge-warn ml-2 hidden sm:inline-flex">Prueba: {{ trialDays }} días restantes</div>
                <div class="ml-auto flex items-center gap-2">
                    <Link
                        v-if="page.props.stockAlerts && (page.props.stockAlerts.low || page.props.stockAlerts.over)"
                        :href="route('stock.low', page.props.stockAlerts.low ? {} : { mode: 'over' })"
                        class="relative rounded-md p-1.5 text-steel-600 hover:bg-steel-100"
                        :title="`${page.props.stockAlerts.low} bajo mínimo · ${page.props.stockAlerts.over} sobre máximo`"
                    >
                        <Bell class="size-5" />
                        <span class="absolute -top-0.5 -right-0.5 min-w-4 rounded-full bg-up px-1 text-center text-[10px] leading-4 font-semibold text-white">{{ page.props.stockAlerts.low + page.props.stockAlerts.over }}</span>
                    </Link>
                    <Link v-if="can('sales.create') && !route().current('pos')" :href="route('pos')" class="btn-primary btn-sm">
                        <ShoppingCart class="size-3.5" /> Vender
                    </Link>
                </div>
            </header>
            <main class="p-4 lg:p-6">
                <slot />
            </main>
        </div>
        <Toasts />
    </div>
</template>
