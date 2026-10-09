import { router } from '@inertiajs/react';

/**
 * Whole-row navigation for admin tables (the old layouts/admin.blade.php
 * `tr.row-link[data-href]` script): `<tr {...rowLink(url)}>`. Clicks on a
 * real control inside the row, or while text is selected, are left alone;
 * Ctrl/Cmd-click opens a new tab.
 */
export function rowLink(href, className = '') {
    if (!href) return className ? { className } : {};
    return {
        className: ('row-link ' + className).trim(),
        'data-href': href,
        onClick: (e) => {
            if (e.target.closest('a, button, form, input, select, label') || String(window.getSelection() || '')) return;
            if (e.metaKey || e.ctrlKey) window.open(href, '_blank', 'noopener');
            else router.visit(href);
        },
    };
}

export default rowLink;
