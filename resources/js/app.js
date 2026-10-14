import '@fontsource-variable/archivo';
import '../css/app.css';
import { createApp, h } from 'vue';
import { createInertiaApp } from '@inertiajs/vue3';
import { ZiggyVue } from 'ziggy-js';
import AppLayout from '@/Layouts/AppLayout.vue';
import { money, qty, pct, date, datetime } from '@/lib/format';

const appName = 'FerreteroSoft';
const pages = import.meta.glob('./Pages/**/*.vue');

// Páginas que se muestran sin el menú lateral
const bare = ['Auth/', 'Sales/Ticket', 'Products/Labels', 'Purchases/Orders/Print', 'Subscription/'];

createInertiaApp({
    title: (title) => (title ? `${title} · ${appName}` : appName),
    resolve: async (name) => {
        const importer = pages[`./Pages/${name}.vue`];
        if (!importer) throw new Error(`Página no encontrada: ${name}`);
        const page = (await importer()).default;
        if (page.layout === undefined && !bare.some((b) => name.startsWith(b))) {
            page.layout = AppLayout;
        }
        return page;
    },
    setup({ el, App, props, plugin }) {
        const app = createApp({ render: () => h(App, props) }).use(plugin).use(ZiggyVue);
        // v-sel="valor": fija el valor de un <select> después de renderizar sus opciones (filtros)
        const setSel = (el, b) => (el.value = b.value ?? '');
        app.directive('sel', { mounted: setSel, updated: setSel });
        app.config.globalProperties.$money = money;
        app.config.globalProperties.$qty = qty;
        app.config.globalProperties.$pct = pct;
        app.config.globalProperties.$date = date;
        app.config.globalProperties.$datetime = datetime;
        app.mount(el);
    },
    progress: { color: '#F2B705', showSpinner: false },
});
