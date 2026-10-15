import { money, qty, date } from '@/lib/format';
import { newDoc, text, header, customerBlock, footer, table, clean, slug, M, R, PH, RED } from '@/lib/accountPdf';

/**
 * Presupuesto en hoja A4: encabezado de la empresa, cliente, validez, detalle, totales y condiciones.
 * Se usa en la ficha interna (descargar / imprimir) y en el link público que recibe el cliente.
 */
export const quoteNumber = (q) => String(q.number).padStart(8, '0');

async function build({ quote, company, settings, publicUrl }) {
    const doc = await newDoc();
    let y = header(doc, company, 'PRESUPUESTO', `N° ${quoteNumber(quote)}`);

    // Cliente a la izquierda, fechas a la derecha
    const top = y;
    if (quote.customer) {
        y = customerBlock(doc, quote.customer, y);
    } else {
        text(doc, 'Cliente', M, y, { size: 8, color: [102, 114, 127] });
        text(doc, 'A quien corresponda', M, y + 5, { size: 11, bold: true });
        y += 16;
    }
    const expired = quote.quote_state === 'expired';
    const rows = [
        ['Fecha', date(quote.created_at)],
        ['Válido hasta', quote.valid_until ? date(quote.valid_until) : '—'],
        ['Atendido por', quote.user?.name ?? ''],
        ['Sucursal', quote.branch?.name ?? ''],
    ];
    rows.forEach(([l, v], i) => {
        text(doc, l, R - 48, top + i * 4.6, { size: 8, color: [102, 114, 127] });
        text(doc, v, R, top + i * 4.6, { size: 8.5, align: 'right', bold: l === 'Válido hasta', color: l === 'Válido hasta' && expired ? RED : [28, 36, 48] });
    });
    y = Math.max(y, top + rows.length * 4.6 + 2) + 4;

    y = table(
        doc,
        [
            { label: 'Código', w: 24, get: (r) => r.code ?? '' },
            { label: 'Descripción', w: 72, wrap: true, get: (r) => r.description },
            { label: 'Cant.', w: 18, align: 'right', get: (r) => qty(r.quantity) },
            { label: 'P. unitario', w: 26, align: 'right', get: (r) => money(r.unit_price) },
            { label: 'Bonif.', w: 14, align: 'right', get: (r) => (Number(r.discount_percent) ? `${Number(r.discount_percent)}%` : '') },
            { label: 'Importe', w: 26, align: 'right', get: (r) => money(r.subtotal) },
        ],
        quote.items,
        y,
    );

    // Totales
    if (y > PH - 70) {
        doc.addPage();
        y = M + 6;
    }
    y += 2;
    const tl = R - 70;
    const line = (label, value, opts = {}) => {
        text(doc, label, tl, y, { size: opts.size ?? 9.5, bold: opts.bold, color: opts.color ?? [74, 85, 99] });
        text(doc, value, R, y, { size: opts.size ?? 9.5, bold: opts.bold, align: 'right' });
        y += opts.gap ?? 5.5;
    };
    if (Number(quote.discount_amount)) {
        line('Subtotal', money(quote.subtotal));
        line(`Descuento ${Number(quote.discount_percent)}%`, `-${money(quote.discount_amount)}`);
    }
    doc.setDrawColor(28, 36, 48);
    doc.line(tl, y - 3, R, y - 3);
    y += 2;
    line('TOTAL', money(quote.total), { size: 13, bold: true, color: [28, 36, 48], gap: 9 });

    // Observaciones y condiciones
    const block = (title, body) => {
        if (!body) return;
        const lines = doc.splitTextToSize(clean(body), R - M);
        if (y + lines.length * 4 + 8 > PH - 16) {
            doc.addPage();
            y = M + 6;
        }
        text(doc, title, M, y, { size: 9, bold: true });
        y += 4.5;
        lines.forEach((l) => {
            text(doc, l, M, y, { size: 8.5, color: [74, 85, 99] });
            y += 4;
        });
        y += 3;
    };
    block('Observaciones', quote.notes);
    block('Condiciones', quote.terms ?? settings?.quote_terms);
    if (quote.valid_until) block('Validez', `Este presupuesto es válido hasta el ${date(quote.valid_until)}. Pasada esa fecha, los precios pueden variar.`);
    if (publicUrl) {
        text(doc, `Ver online: ${publicUrl}`, M, Math.min(y + 2, PH - 14), { size: 7.5, color: [47, 93, 138] });
    }

    footer(doc);
    return doc;
}

export const quoteFileName = (quote) => `presupuesto-${quoteNumber(quote)}${quote.customer ? '-' + slug(quote.customer.name) : ''}.pdf`;

export async function downloadQuotePdf(data) {
    const doc = await build(data);
    doc.save(quoteFileName(data.quote));
}

/** Abre el PDF en una pestaña nueva para imprimir (el navegador muestra su visor) */
export async function openQuotePdf(data) {
    const win = window.open('', '_blank');
    const doc = await build(data);
    const url = doc.output('bloburl');
    if (win) win.location.href = url;
    else window.location.href = url;
}
