/** Link de WhatsApp para un teléfono argentino (si no hay teléfono, WhatsApp pide elegir el contacto) */
export function waNumber(phone) {
    const d = String(phone ?? '').replace(/\D/g, '');
    if (!d) return '';
    return d.startsWith('54') ? d : `549${d.replace(/^0/, '')}`;
}

export const waLink = (phone, text) => `https://wa.me/${waNumber(phone)}?text=${encodeURIComponent(text)}`;
