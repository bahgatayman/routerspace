import { locale } from './i18n';

/**
 * Money exactly like App\Support\Money::format (EN "EGP 1,948.00" · AR "1,948.00 ج.م").
 * Display only — every amount is calculated on the server.
 */
export function money(amount) {
    const n = Number(amount || 0).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    return locale() === 'ar' ? `${n} ج.م` : `EGP ${n}`;
}

/** Integer/number with thousands separators (PHP number_format). */
export function num(value, decimals = 0) {
    return Number(value || 0).toLocaleString('en-US', { minimumFractionDigits: decimals, maximumFractionDigits: decimals });
}

/** Build a query string from an object, dropping empty values. */
export function query(params) {
    const q = new URLSearchParams();
    Object.entries(params).forEach(([k, v]) => {
        if (v !== null && v !== undefined && v !== '' && v !== false) q.set(k, v === true ? '1' : v);
    });
    const s = q.toString();
    return s ? `?${s}` : '';
}

/** Same 32-bit CRC PHP's crc32() returns — keeps avatar tints identical to Blade. */
export function crc32(str) {
    let c, crc = 0xffffffff;
    const bytes = new TextEncoder().encode(str);
    for (let i = 0; i < bytes.length; i++) {
        c = (crc ^ bytes[i]) & 0xff;
        for (let k = 0; k < 8; k++) c = c & 1 ? (c >>> 1) ^ 0xedb88320 : c >>> 1;
        crc = (crc >>> 8) ^ c;
    }
    return (crc ^ 0xffffffff) >>> 0;
}
