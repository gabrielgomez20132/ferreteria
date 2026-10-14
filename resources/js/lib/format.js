const moneyFmt = new Intl.NumberFormat('es-AR', { style: 'currency', currency: 'ARS', minimumFractionDigits: 2, maximumFractionDigits: 2 });
const numFmt = new Intl.NumberFormat('es-AR', { maximumFractionDigits: 3 });
const pctFmt = new Intl.NumberFormat('es-AR', { minimumFractionDigits: 1, maximumFractionDigits: 1 });

export const toNum = (v) => {
    const n = typeof v === 'number' ? v : parseFloat(v);
    return Number.isFinite(n) ? n : 0;
};

export const money = (v) => (v === null || v === undefined || v === '' ? '—' : moneyFmt.format(toNum(v)));
export const qty = (v) => (v === null || v === undefined || v === '' ? '—' : numFmt.format(toNum(v)));
export const pct = (v, signed = false) => {
    if (v === null || v === undefined || v === '') return '—';
    const n = toNum(v);
    return (signed && n > 0 ? '+' : '') + pctFmt.format(n) + '%';
};
export const date = (v) => (v ? new Date(v.length === 10 ? v + 'T00:00:00' : v).toLocaleDateString('es-AR') : '—');
export const datetime = (v) =>
    v ? new Date(v).toLocaleString('es-AR', { day: '2-digit', month: '2-digit', year: '2-digit', hour: '2-digit', minute: '2-digit' }) : '—';

export const round2 = (n) => Math.round(toNum(n) * 100) / 100;

/** Redondeo de precios igual al del servidor */
export function roundPrice(price, step = 0, mode = 'up') {
    price = toNum(price);
    step = toNum(step);
    if (step <= 0 || price <= 0) return round2(price);
    let units = price / step;
    units = mode === 'up' ? Math.ceil(Math.round(units * 1e6) / 1e6) : Math.round(units);
    return round2(Math.max(units, 1) * step);
}

export const methods = {
    cash: 'Efectivo',
    transfer: 'Transferencia',
    debit_card: 'Débito',
    credit_card: 'Crédito',
    mercadopago: 'Mercado Pago / QR',
    check: 'Cheque',
    account: 'Cuenta corriente',
};

/** Fecha local de hoy (o dentro de N días) en formato YYYY-MM-DD */
export function today(addDays = 0) {
    const d = new Date();
    d.setDate(d.getDate() + addDays);
    return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`;
}
