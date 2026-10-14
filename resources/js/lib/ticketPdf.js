import { money, qty, datetime, toNum } from '@/lib/format';

/**
 * Genera el ticket en PDF de 80 mm de ancho (alto variable según los ítems) y lo descarga.
 * Se dibuja como texto (no como imagen): queda liviano y nítido al imprimir.
 */
const FONT = 'courier';
const W = 80; // ancho del papel (mm)
const M = 5; // margen lateral (mm)
const R = W - M;
const LINE = 0.42; // alto de línea = tamaño de fuente (pt) × factor (mm)

// Las fuentes estándar de PDF no tienen los espacios "finos" que usa Intl (es-AR) en moneda y hora
const clean = (t) => String(t ?? '').replace(/[    ]/g, ' ');

export async function downloadTicketPdf({ sale, company, settings, methods }) {
    const { jsPDF } = await import('jspdf');

    const rows = [];
    const add = (text, opts = {}) => rows.push({ text: clean(text), ...opts, right: opts.right ? clean(opts.right) : null });
    const sep = () => rows.push({ sep: true });

    // ---- contenido ----
    add(company.name, { center: true, bold: true, size: 11 });
    if (company.legal_name) add(company.legal_name, { center: true });
    if (company.cuit) add(`CUIT ${company.cuit}`, { center: true });
    if (company.address) add(company.address, { center: true });
    if (sale.branch) add(sale.branch.name, { center: true });
    if (settings.ticket_header) settings.ticket_header.split('\n').forEach((l) => add(l, { center: true }));
    sep();
    add(`${sale.type === 'quote' ? 'PRESUPUESTO' : 'TICKET'} N° ${String(sale.number).padStart(8, '0')}`, { bold: true });
    add(`${datetime(sale.created_at)} - ${sale.user?.name ?? ''}`);
    add(`Cliente: ${sale.customer?.name ?? 'Consumidor final'}`);
    if (sale.customer?.document_number) add(`${sale.customer.document_type} ${sale.customer.document_number}`);
    add('Documento no válido como factura', { size: 7 });
    sep();
    for (const i of sale.items) {
        add(i.description);
        const disc = toNum(i.discount_percent) ? ` -${toNum(i.discount_percent)}%` : '';
        add(`${qty(i.quantity)} x ${money(i.unit_price)}${disc}`, { right: money(i.subtotal) });
    }
    sep();
    if (toNum(sale.discount_amount)) add('Descuento', { right: `-${money(sale.discount_amount)}` });
    add('TOTAL', { right: money(sale.total), bold: true, size: 11 });
    for (const p of sale.payments ?? []) add(methods[p.method] ?? p.method, { right: money(p.amount) });
    if (toNum(sale.change_amount)) add('Vuelto', { right: money(sale.change_amount) });
    sep();
    (settings.ticket_footer || '').split('\n').filter(Boolean).forEach((l) => add(l, { center: true }));

    // ---- medir con la MISMA fuente con la que se dibuja ----
    const measure = new jsPDF({ unit: 'mm', format: [W, 200] });
    const setFont = (doc, r) => {
        doc.setFont(FONT, r.bold ? 'bold' : 'normal');
        doc.setFontSize(r.size);
    };
    let height = M;
    const prepared = rows.map((r) => {
        if (r.sep) {
            height += 4;
            return r;
        }
        r = { ...r, size: r.size ?? 8.5 };
        setFont(measure, r);
        const rightWidth = r.right ? measure.getTextWidth(r.right) : 0;
        // si el texto de la izquierda no entra junto al importe, el importe baja a la línea siguiente
        let wrapped = measure.splitTextToSize(r.text, R - M);
        let rightOwnLine = false;
        if (r.right) {
            const last = wrapped[wrapped.length - 1];
            if (measure.getTextWidth(last) + rightWidth + 2 > R - M) rightOwnLine = true;
        }
        const lh = r.size * LINE;
        height += (wrapped.length + (rightOwnLine ? 1 : 0)) * lh + 0.6;
        return { ...r, wrapped, lh, rightOwnLine };
    });
    height += M;

    // ---- dibujar ----
    const doc = new jsPDF({ unit: 'mm', format: [W, Math.max(height, 50)] });
    let y = M;
    for (const r of prepared) {
        if (r.sep) {
            // línea punteada centrada en el espacio entre bloques (no pisa el texto)
            doc.setLineDashPattern([0.8, 0.8], 0);
            doc.setLineWidth(0.2);
            doc.line(M, y + 1.6, R, y + 1.6);
            y += 4;
            continue;
        }
        setFont(doc, r);
        r.wrapped.forEach((t, idx) => {
            y += r.lh;
            if (r.center) doc.text(t, W / 2, y, { align: 'center' });
            else doc.text(t, M, y);
            if (r.right && idx === r.wrapped.length - 1) {
                if (r.rightOwnLine) y += r.lh;
                doc.text(r.right, R, y, { align: 'right' });
            }
        });
        y += 0.6;
    }

    const prefix = sale.type === 'quote' ? 'presupuesto' : 'ticket';
    doc.save(`${prefix}-${String(sale.number).padStart(8, '0')}.pdf`);
}
