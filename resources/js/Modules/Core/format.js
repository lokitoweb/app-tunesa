// Formatos de pantalla compartidos (antes cada página de Finanzas tenía su propia copia, con diferencias)

const CURRENCY_SYMBOLS = { PEN: 'S/', USD: 'US$', EUR: '€' };

/** S/ 1,234.50 · US$ 10.00 · sin valor: — (siempre 2 decimales) */
export function money(value, currency = 'PEN') {
    if (value === null || value === undefined || value === '') return '—';
    const symbol = CURRENCY_SYMBOLS[currency] || currency || 'S/';
    return `${symbol} ${Number(value).toLocaleString('es-PE', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`;
}
