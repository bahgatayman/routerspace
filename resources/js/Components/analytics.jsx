/*
 * Analytics building blocks shared by the Super Admin pages and the owner
 * dashboard: KPI stat card, period filter, chart card (Chart.js from npm, same
 * spec format and colours as the former public/js/admin-charts.js), plus a
 * small hook for GET-param filter forms.
 */
import { Link, router } from '@inertiajs/react';
import Chart from 'chart.js/auto';
import { useEffect, useRef, useState } from 'react';
import { num } from '../lib/format';
import { locale, t } from '../lib/i18n';
import { Icon, cx } from './ui';

/** KPI card with optional change vs the previous period. */
export function Stat({ label, value, change = null, invert = false, help, tone, href, sub }) {
    const good = change === null || change === undefined ? null : (change >= 0) !== invert;
    const Tag = href ? Link : 'div';
    return (
        <Tag href={href} className={cx('ls-akpi', href && 'is-link')} title={help || undefined}>
            <span className="ls-akpi-label">
                {label}
                {help && <><i className="ls-akpi-help" aria-hidden="true">?</i><span className="sr-only">. {help}</span></>}
            </span>
            <b className={cx('ls-akpi-value', tone && `is-${tone}`)}>{value}</b>
            {change !== null && change !== undefined ? (
                <span className={cx('ls-akpi-delta', change === 0 ? 'is-flat' : good ? 'is-good' : 'is-bad')}>
                    <span aria-hidden="true">{change > 0 ? '▲' : change < 0 ? '▼' : '■'}</span>
                    {(change > 0 ? '+' : '') + num(change, 1)}%
                    <small>{t('admin_platform.vs_previous')}</small>
                </span>
            ) : sub ? <span className="ls-akpi-sub">{sub}</span> : null}
        </Tag>
    );
}

/**
 * GET filter state bound to the URL. `apply(next)` visits the current path with the merged
 * params (empty values dropped), keeping scroll and replacing history like the Blade forms did.
 */
export function useFilters(initial) {
    const [values, setValues] = useState(initial);
    const set = (k, v) => setValues((s) => ({ ...s, [k]: v }));
    const apply = (override = {}) => {
        const params = Object.fromEntries(Object.entries({ ...values, ...override }).filter(([, v]) => v !== '' && v !== null && v !== undefined && v !== false));
        router.get(window.location.pathname, params, { preserveScroll: true, preserveState: true, replace: true });
    };
    return { values, set, apply, setValues };
}

const PRESETS = ['today', 'last_7', 'last_30', 'last_90', 'this_month', 'previous_month', 'custom'];

/** Period preset select + custom from/to (only for "custom"). Changing a preset applies immediately. */
export function PeriodFields({ filters, allTime = false }) {
    const { values, set, apply } = filters;
    return (
        <>
            <div className="ls-field ls-filter-field">
                <label className="ls-label" htmlFor="f-preset">{t('admin_platform.period')}</label>
                <select id="f-preset" className="ls-select" value={values.preset || ''}
                    onChange={(e) => { set('preset', e.target.value); if (e.target.value !== 'custom') apply({ preset: e.target.value, from: '', to: '' }); }}>
                    {allTime && <option value="">{t('admin_platform.presets.all_time')}</option>}
                    {PRESETS.map((p) => <option key={p} value={p}>{t(`admin_platform.presets.${p}`)}</option>)}
                </select>
            </div>
            {values.preset === 'custom' && (
                <>
                    <div className="ls-field ls-filter-field">
                        <label className="ls-label" htmlFor="f-from">{t('admin_platform.from')}</label>
                        <input id="f-from" type="date" className="ls-input" dir="ltr" value={values.from || ''} onChange={(e) => set('from', e.target.value)} />
                    </div>
                    <div className="ls-field ls-filter-field">
                        <label className="ls-label" htmlFor="f-to">{t('admin_platform.to')}</label>
                        <input id="f-to" type="date" className="ls-input" dir="ltr" value={values.to || ''} onChange={(e) => set('to', e.target.value)} />
                    </div>
                </>
            )}
        </>
    );
}

/** The ls-adm-filters bar: children are fields; Apply submits, Reset clears to `resetHref`. */
export function FilterBar({ filters, children, resetHref, showReset, applyLabel, variant = 'primary', label }) {
    return (
        <form className="ls-adm-filters" aria-label={label || t('admin_platform.filters')} onSubmit={(e) => { e.preventDefault(); filters.apply(); }}>
            {children}
            <div className="ls-filter-actions">
                <button type="submit" className={`ls-btn ls-btn--${variant}`}>{applyLabel || t('admin_platform.apply')}</button>
                {showReset && resetHref && <Link href={resetHref} className="ls-btn ls-btn--ghost">{t('admin_platform.reset')}</Link>}
            </div>
        </form>
    );
}

/* ----------------------------------------------------------------- charts */

const css = (name) => getComputedStyle(document.documentElement).getPropertyValue(name).trim();
const colorOf = (key) => css('--chart-' + key) || css('--color-accent');
const fmtValue = (v, money) => money
    ? new Intl.NumberFormat(locale() === 'ar' ? 'ar-EG-u-nu-latn' : 'en-US', { maximumFractionDigits: 2 }).format(v) + (locale() === 'ar' ? ' ج.م' : ' EGP')
    : new Intl.NumberFormat(locale() === 'ar' ? 'ar-EG-u-nu-latn' : 'en-US').format(v);

/**
 * Chart.js canvas from a spec:
 *   { type: 'line'|'bar'|'doughnut', labels, money, stacked, horizontal,
 *     datasets: [{ label, data, color: 'c1'..'c4'|'success'|... or [colors] }], links: [url per label] }
 * Colours come from CSS tokens (--chart-*), rebuilt when the theme changes.
 */
export function ChartCanvas({ spec, height = 260, label, clickHint }) {
    const ref = useRef(null);
    useEffect(() => {
        const canvas = ref.current;
        if (!canvas) return undefined;
        let chart;
        const build = () => {
            if (chart) chart.destroy();
            const isDonut = spec.type === 'doughnut';
            const ink = css('--color-text-muted');
            const grid = css('--color-border-subtle');
            const surface = css('--color-surface');
            const rtl = document.documentElement.dir === 'rtl';
            const datasets = spec.datasets.map((d) => {
                const c = Array.isArray(d.color) ? d.color.map(colorOf) : colorOf(d.color || 'c1');
                const base = { label: d.label, data: d.data };
                if (isDonut) return { ...base, backgroundColor: c, borderColor: surface, borderWidth: 2, hoverOffset: 4 };
                if (spec.type === 'line') return { ...base, borderColor: c, backgroundColor: c, borderWidth: 2, pointRadius: d.data.length > 40 ? 0 : 3, pointHoverRadius: 5, pointBackgroundColor: c, pointBorderColor: surface, pointBorderWidth: 2, tension: 0.25, fill: false };
                return { ...base, backgroundColor: c, borderColor: surface, borderWidth: spec.stacked ? { top: 2 } : 0, borderRadius: 4, borderSkipped: 'start', maxBarThickness: 28 };
            });
            chart = new Chart(canvas, {
                type: spec.type,
                data: { labels: spec.labels, datasets },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    indexAxis: spec.horizontal ? 'y' : 'x',
                    animation: { duration: 250 },
                    interaction: isDonut ? { mode: 'nearest', intersect: true } : { mode: 'index', intersect: false },
                    cutout: isDonut ? '62%' : undefined,
                    onHover: (e, els) => { e.native.target.style.cursor = els.length && spec.links ? 'pointer' : 'default'; },
                    onClick: (e, els) => {
                        if (!spec.links || !els.length) return;
                        const url = spec.links[els[0].index];
                        if (url) router.visit(url);
                    },
                    plugins: {
                        legend: { display: isDonut || datasets.length > 1, position: isDonut ? 'bottom' : 'top', align: 'start', rtl, labels: { color: ink, boxWidth: 10, boxHeight: 10, usePointStyle: true, pointStyle: 'rectRounded', padding: 14, font: { size: 12 } } },
                        tooltip: {
                            rtl,
                            backgroundColor: css('--color-surface-elevated'),
                            titleColor: css('--color-text'),
                            bodyColor: css('--color-text-secondary'),
                            borderColor: css('--color-border'),
                            borderWidth: 1, padding: 10, boxPadding: 4, usePointStyle: true,
                            callbacks: {
                                label: (ctx) => ' ' + (ctx.dataset.label ? ctx.dataset.label + ': ' : '') + fmtValue(isDonut ? ctx.parsed : (spec.horizontal ? ctx.parsed.x : ctx.parsed.y), spec.money),
                                footer: (items) => (spec.links && items.length && spec.links[items[0].dataIndex] ? clickHint || '' : ''),
                            },
                        },
                    },
                    scales: isDonut ? {} : {
                        x: { stacked: !!spec.stacked, reverse: rtl && !spec.horizontal, grid: { display: !!spec.horizontal, color: grid }, border: { display: false }, ticks: { color: ink, maxRotation: 0, autoSkipPadding: 12, font: { size: 11.5 }, ...(spec.horizontal ? { callback: (v) => fmtValue(v, spec.money) } : {}) } },
                        y: { stacked: !!spec.stacked, position: rtl ? 'right' : 'left', beginAtZero: true, grid: { display: !spec.horizontal, color: grid }, border: { display: false }, ticks: { color: ink, precision: spec.money ? undefined : 0, font: { size: 11.5 }, ...(spec.horizontal ? {} : { callback: (v) => fmtValue(v, spec.money) }) } },
                    },
                },
            });
        };
        build();
        const obs = new MutationObserver(build);
        obs.observe(document.documentElement, { attributes: true, attributeFilter: ['data-theme'] });
        return () => { obs.disconnect(); if (chart) chart.destroy(); };
    }, [spec]);
    return (
        <div className="ls-chart-canvas is-ready" style={{ height }}>
            <canvas ref={ref} role="img" aria-label={label} />
        </div>
    );
}

/** Chart card: title + note, chart ⇄ table toggle, empty state when every value is 0. */
export function ChartCard({ id, title, note, spec, height = 260, empty }) {
    const [asTable, setAsTable] = useState(false);
    const total = spec.datasets.flatMap((d) => d.data).reduce((s, v) => s + Math.abs(Number(v) || 0), 0);
    const isEmpty = total === 0;
    const fmtCell = (v) => (spec.money ? num(v, 2) : num(v));
    return (
        <section className={cx('ls-card ls-chart', asTable && 'is-table')} id={id ? `${id}-box` : undefined} aria-label={title}>
            <div className="ls-card-head">
                <div>
                    <h2 className="ls-card-title">{title}</h2>
                    {note && <p className="ls-chart-note">{note}</p>}
                </div>
                {!isEmpty && (
                    <button type="button" className="ls-btn ls-btn--ghost ls-btn--sm ls-chart-toggle" aria-pressed={asTable} onClick={() => setAsTable((v) => !v)}>
                        {asTable ? t('admin_platform.chart.chart_view') : t('admin_platform.chart.table_view')}
                    </button>
                )}
            </div>
            <div className="ls-card-body">
                {isEmpty ? (
                    <div className="ls-chart-empty"><Icon name="alert" /><span>{empty || t('admin_platform.chart.empty')}</span></div>
                ) : asTable ? (
                    <div className="ls-table-wrap" style={{ maxHeight: 360, overflow: 'auto' }}>
                        <table className="ls-table">
                            <thead><tr>
                                <th scope="col">{spec.axis || ''}</th>
                                {spec.datasets.map((d) => <th key={d.label} scope="col" className="is-num">{d.label}</th>)}
                            </tr></thead>
                            <tbody>
                                {spec.labels.map((lbl, i) => (
                                    <tr key={i}>
                                        <th scope="row" style={{ fontWeight: 400 }}>{spec.links && spec.links[i] ? <Link href={spec.links[i]} className="ls-link">{lbl}</Link> : lbl}</th>
                                        {spec.datasets.map((d) => <td key={d.label} className="is-num">{fmtCell(d.data[i] || 0)}</td>)}
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                ) : (
                    <ChartCanvas spec={spec} height={height} label={title} clickHint={spec.links ? t('admin_platform.chart.click_hint') : ''} />
                )}
            </div>
        </section>
    );
}
