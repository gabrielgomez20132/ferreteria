import { usePage } from '@inertiajs/vue3';

/** can('products.manage') — también acepta varios: can('a', 'b') = cualquiera */
export function useCan() {
    const page = usePage();
    return (...perms) => perms.some((p) => (page.props.auth?.permissions || []).includes(p));
}
