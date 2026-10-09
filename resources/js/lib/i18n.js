/*
 * Translations for React pages — the same lang/{locale}/app.php strings the
 * Blade views use, delivered once per full page load as window.LS_LANG by the
 * Inertia root template. Mirrors Laravel's __() / trans_choice():
 *   t('nav.dashboard') or t('app.nav.dashboard')
 *   t('admin_platform.showing', { from: 1, to: 20, total: 90 })
 *   tc('admin_platform.months', 3, { count: 3 })   // "{1} :count month|[2,*] :count months"
 * A missing key returns the key itself (like Laravel).
 */
const table = () => (typeof window !== 'undefined' && window.LS_LANG) || {};

function lookup(key) {
    const path = key.startsWith('app.') ? key.slice(4) : key;
    return path.split('.').reduce((node, part) => (node != null && typeof node === 'object' ? node[part] : undefined), table());
}

function replace(line, params = {}) {
    // Longest keys first so ":count" never eats ":countdown".
    return Object.keys(params)
        .sort((a, b) => b.length - a.length)
        .reduce((out, k) => {
            const v = params[k] == null ? '' : String(params[k]);
            return out
                .split(':' + k.toUpperCase()).join(v.toUpperCase())
                .split(':' + k.charAt(0).toUpperCase() + k.slice(1)).join(v.charAt(0).toUpperCase() + v.slice(1))
                .split(':' + k).join(v);
        }, line);
}

export function t(key, params = {}) {
    const line = lookup(key);
    if (typeof line !== 'string') return key.startsWith('app.') ? key : 'app.' + key;
    return replace(line, params);
}

/** Laravel MessageSelector: explicit {n} / [a,b] / [a,*] ranges, else singular|plural. */
export function tc(key, count, params = {}) {
    const line = lookup(key);
    if (typeof line !== 'string') return key;
    const segments = line.split('|');
    const n = Number(count);
    let chosen = null;
    for (const seg of segments) {
        const m = seg.match(/^\s*(\{\s*(-?\d+)\s*\}|\[\s*(-?\d+|\*)\s*,\s*(-?\d+|\*)\s*\])\s*([\s\S]*)$/);
        if (!m) continue;
        if (m[2] !== undefined) {
            if (Number(m[2]) === n) { chosen = m[5]; break; }
        } else {
            const lo = m[3] === '*' ? -Infinity : Number(m[3]);
            const hi = m[4] === '*' ? Infinity : Number(m[4]);
            if (n >= lo && n <= hi) { chosen = m[5]; break; }
        }
    }
    if (chosen === null) {
        const plain = segments.map((s) => s.replace(/^\s*(\{[^}]*\}|\[[^\]]*\])\s*/, ''));
        chosen = plain.length > 1 && n !== 1 ? plain[1] : plain[0];
    }
    return replace(chosen.trim(), { count, ...params });
}

export const isRtl = () => typeof document !== 'undefined' && document.documentElement.dir === 'rtl';
export const locale = () => (typeof document !== 'undefined' && document.documentElement.lang) || 'en';
