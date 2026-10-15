import { money, date, datetime } from '@/lib/format';

/**
 * PDF A4 del portal de clientes: resumen de cuenta corriente y recibo de pago.
 * Se dibujan como texto con jsPDF (livianos y nítidos al imprimir).
 */
const FONT = 'helvetica';
const PW = 210;
export const PH = 297;
export const M = 15;
export const R = PW - M;

// Las fuentes estándar de PDF no tienen los espacios "finos" que usa Intl (es-AR)
export const clean = (t) => String(t ?? '').replace(/[    ]/g, ' ');
const TYPES = { sale: 'Venta', payment: 'Pago', return: 'Devolución', adjustment: 'Ajuste', cancel: 'Anulación' };

export async function newDoc() {
    const { jsPDF } = await import('jspdf');
    return new jsPDF({ unit: 'mm', format: 'a4' });
}

export function text(doc, t, x, y, { size = 9, bold = false, align = 'left', color = [28, 36, 48] } = {}) {
    doc.setFont(FONT, bold ? 'bold' : 'normal');
    doc.setFontSize(size);
    doc.setTextColor(...color);
    doc.text(clean(t), x, y, { align });
}

/** Encabezado con los datos de la empresa y el título del documento. Devuelve la Y siguiente */
export function header(doc, company, title, subtitle) {
    doc.setFillColor(242, 183, 5);
    doc.rect(0, 0, PW, 3, 'F');
    let y = M + 2;
    text(doc, company.name, M, y, { size: 14, bold: true });
    text(doc, title, R, y, { size: 13, bold: true, align: 'right' });
    y += 5;
    const lines = [company.legal_name, company.cuit && `CUIT ${company.cuit}`, company.address, [company.phone, company.email].filter(Boolean).join(' · ')].filter(Boolean);
    lines.forEach((l, i) => text(doc, l, M, y + i * 4, { size: 8.5, color: [74, 85, 99] }));
    if (subtitle) text(doc, subtitle, R, y, { size: 8.5, align: 'right', color: [74, 85, 99] });
    y += Math.max(lines.length, 1) * 4 + 3;
    doc.setDrawColor(195, 202, 211);
    doc.line(M, y, R, y);
    return y + 6;
}

export function customerBlock(doc, customer, y) {
    text(doc, 'Cliente', M, y, { size: 8, color: [102, 114, 127] });
    text(doc, customer.name, M, y + 5, { size: 11, bold: true });
    const doc2 = [customer.document_number && `${customer.document_type} ${customer.document_number}`, customer.tax_condition].filter(Boolean).join(' · ');
    if (doc2) text(doc, doc2, M, y + 9.5, { size: 8.5, color: [74, 85, 99] });
    const addr = [customer.address, customer.city].filter(Boolean).join(', ');
    if (addr) text(doc, addr, M, y + 13.5, { size: 8.5, color: [74, 85, 99] });
    return y + (addr ? 20 : 16);
}

export function footer(doc) {
    const n = doc.getNumberOfPages();
    for (let i = 1; i <= n; i++) {
        doc.setPage(i);
        text(doc, 'Documento no válido como factura', M, PH - 8, { size: 7.5, color: [143, 154, 168] });
        text(doc, `Página ${i} de ${n}`, R, PH - 8, { size: 7.5, align: 'right', color: [143, 154, 168] });
    }
}

/**
 * Tabla simple con salto de página. cols: [{ label, w, align, get(row) }]
 * Devuelve la Y final.
 */
export function table(doc, cols, rows, y, { onRowStyle } = {}) {
    const drawHead = () => {
        doc.setFillColor(238, 240, 242);
        doc.rect(M, y - 4, R - M, 6.5, 'F');
        let x = M + 1.5;
        cols.forEach((c) => {
            text(doc, c.label, c.align === 'right' ? x + c.w - 3 : x, y, { size: 8, bold: true, align: c.align === 'right' ? 'right' : 'left', color: [74, 85, 99] });
            x += c.w;
        });
        y += 6;
    };
    drawHead();
    rows.forEach((row) => {
        // la descripción puede ocupar varias líneas
        doc.setFont(FONT, 'normal');
        doc.setFontSize(8.5);
        const cells = cols.map((c) => {
            const v = clean(c.get(row));
            return c.wrap ? doc.splitTextToSize(v, c.w - 3) : [v];
        });
        const h = Math.max(...cells.map((l) => l.length)) * 3.8 + 1.6;
        if (y + h > PH - 18) {
            doc.addPage();
            y = M + 4;
            drawHead();
        }
        const style = onRowStyle?.(row) ?? {};
        let x = M + 1.5;
        cols.forEach((c, i) => {
            cells[i].forEach((line, k) => {
                text(doc, line, c.align === 'right' ? x + c.w - 3 : x, y + k * 3.8, { size: 8.5, align: c.align === 'right' ? 'right' : 'left', bold: style.bold, color: (c.color?.(row)) ?? style.color ?? [28, 36, 48] });
            });
            x += c.w;
        });
        y += h;
        doc.setDrawColor(238, 240, 242);
        doc.line(M, y - 3, R, y - 3);
    });
    return y;
}

export const RED = [180, 35, 24];

/** Resumen de cuenta corriente */
export async function downloadStatementPdf({ company, customer, summary, movements, previousBalance, pending, months, methods }) {
    const doc = await newDoc();
    const period = months ? `Últimos ${months} meses` : 'Todos los movimientos';
    let y = header(doc, company, 'RESUMEN DE CUENTA', `Emitido el ${datetime(new Date().toISOString())}`);
    y = customerBlock(doc, customer, y);

    // Recuadros con los totales
    const boxes = [
        ['Saldo actual', money(summary.balance), summary.balance > 0.009],
        ['Vencido', money(summary.overdue), summary.overdue > 0.009],
        ['A vencer', money(summary.not_due), false],
    ];
    const bw = (R - M - 8) / 3;
    boxes.forEach(([label, value, red], i) => {
        const x = M + i * (bw + 4);
        doc.setDrawColor(221, 225, 230);
        doc.roundedRect(x, y, bw, 15, 1.5, 1.5);
        text(doc, label, x + 3, y + 5, { size: 8, color: [102, 114, 127] });
        text(doc, value, x + 3, y + 11.5, { size: 12, bold: true, color: red ? RED : [28, 36, 48] });
    });
    y += 23;

    if (pending.length) {
        text(doc, 'Comprobantes pendientes de pago', M, y, { size: 10.5, bold: true });
        y += 6;
        y = table(
            doc,
            [
                { label: 'Fecha', w: 24, get: (r) => date(r.date) },
                { label: 'Comprobante', w: 72, wrap: true, get: (r) => r.description },
                { label: 'Vence', w: 24, get: (r) => date(r.due_date), color: (r) => (r.overdue ? RED : null) },
                { label: 'Importe', w: 30, align: 'right', get: (r) => money(r.debit) },
                { label: 'Pendiente', w: 30, align: 'right', get: (r) => money(r.pending), color: (r) => (r.overdue ? RED : null) },
            ],
            pending,
            y,
        );
        y += 6;
    }

    if (y > PH - 50) {
        doc.addPage();
        y = M + 4;
    }
    text(doc, `Movimientos · ${period}`, M, y, { size: 10.5, bold: true });
    y += 6;
    const rows = [{ _prev: true, date: null, description: 'Saldo anterior', balance: previousBalance }, ...movements];
    table(
        doc,
        [
            { label: 'Fecha', w: 22, get: (r) => (r._prev ? '' : date(r.date)) },
            { label: 'Concepto', w: 70, wrap: true, get: (r) => (r._prev ? r.description : `${r.description.startsWith(TYPES[r.type] ?? '\u0000') ? '' : `${TYPES[r.type] ?? r.type}: `}${r.description}${r.method && methods[r.method] ? ` (${methods[r.method]})` : ''}`) },
            { label: 'Vence', w: 20, get: (r) => (r.debit && r.due_date ? date(r.due_date) : '') },
            { label: 'Debe', w: 23, align: 'right', get: (r) => (r.debit ? money(r.debit) : '') },
            { label: 'Haber', w: 23, align: 'right', get: (r) => (r.credit ? money(r.credit) : '') },
            { label: 'Saldo', w: 22, align: 'right', get: (r) => money(r.balance) },
        ],
        rows,
        y,
        { onRowStyle: (r) => (r._prev ? { bold: true, color: [74, 85, 99] } : null) },
    );

    footer(doc);
    doc.save(`resumen-cuenta-${slug(customer.name)}.pdf`);
}

/** Recibo de un pago recibido */
export async function downloadReceiptPdf({ company, customer, payment, methods }) {
    const doc = await newDoc();
    let y = header(doc, company, 'RECIBO DE PAGO', `N° ${String(payment.id).padStart(8, '0')}`);
    y = customerBlock(doc, customer, y);
    text(doc, `Fecha: ${datetime(payment.date)}`, M, y, { size: 10 });
    y += 10;
    doc.setFillColor(246, 247, 249);
    doc.roundedRect(M, y, R - M, 34, 2, 2, 'F');
    text(doc, 'Recibimos la suma de', M + 5, y + 8, { size: 9, color: [102, 114, 127] });
    text(doc, money(payment.credit), M + 5, y + 18, { size: 20, bold: true });
    text(doc, `Medio de pago: ${methods[payment.method] ?? payment.method ?? '—'}`, M + 5, y + 27, { size: 9.5 });
    text(doc, 'a cuenta de su saldo en cuenta corriente.', R - 5, y + 27, { size: 9.5, align: 'right' });
    y += 44;
    text(doc, `Concepto: ${payment.description}`, M, y, { size: 9.5 });
    y += 6;
    text(doc, `Saldo luego de este pago: ${money(payment.balance)}`, M, y, { size: 9.5, bold: true });
    footer(doc);
    doc.save(`recibo-${String(payment.id).padStart(8, '0')}.pdf`);
}

export const slug = (s) =>
    String(s ?? 'cliente')
        .normalize('NFD')
        .replace(/[̀-ͯ]/g, '')
        .replace(/[^a-zA-Z0-9]+/g, '-')
        .replace(/^-|-$/g, '')
        .toLowerCase();
