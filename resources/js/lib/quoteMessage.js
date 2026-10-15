import { money, date } from '@/lib/format';

/** Texto del WhatsApp con el resumen del presupuesto y el link para verlo / descargarlo */
export function quoteMessage(quote, company, publicUrl) {
    const hello = quote.customer ? `Hola ${quote.customer.name.split(' ')[0]}` : 'Hola';
    const lines = [
        `${hello}, te paso el presupuesto N° ${quote.number} de ${company.name} por ${money(quote.total)}.`,
        quote.valid_until ? `Es válido hasta el ${date(quote.valid_until)}.` : '',
        '',
        `Podés verlo y descargarlo acá: ${publicUrl}`,
        '',
        'Cualquier consulta, respondé este mensaje. ¡Gracias!',
    ];
    return lines.filter((l, i, a) => l !== '' || a[i - 1] !== '').join('\n').replace(/[  ]/g, ' ');
}
