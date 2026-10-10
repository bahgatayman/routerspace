/*
  Super Admin charts (Chart.js 4, loaded from the CDN by admin/_chart).

  Every chart is a <canvas data-ls-chart="id"> next to <script type="application/json" id="id-spec">:
    { type: 'line'|'bar'|'doughnut', labels: [...], money: bool, stacked: bool, horizontal: bool,
      datasets: [{ label, data: [...], color: 'c1'..'c4' | 'success'|'info'|'warning'|'danger'|'neutral' }],
      links: [url per label] }            // optional click-through (a bucket, a status, a workspace)
  Colours come from CSS tokens (--chart-*), so light/dark each use their own validated steps;
  charts are rebuilt when the theme changes. Values are never invented here — empty specs
  render the page's empty state instead of a canvas.
*/
(function () {
  'use strict';
  const charts = new Map();
  const css = (name) => getComputedStyle(document.documentElement).getPropertyValue(name).trim();
  const color = (key) => css('--chart-' + key) || css('--color-accent');
  const locale = document.documentElement.lang === 'ar' ? 'ar-EG-u-nu-latn' : 'en-US';
  const fmt = (v, money) => money
    ? new Intl.NumberFormat(locale, { maximumFractionDigits: 2 }).format(v) + (document.documentElement.lang === 'ar' ? ' ج.م' : ' EGP')
    : new Intl.NumberFormat(locale).format(v);

  function build(canvas) {
    const id = canvas.dataset.lsChart;
    const specEl = document.getElementById(id + '-spec');
    if (!specEl || !window.Chart) return;
    const spec = JSON.parse(specEl.textContent);
    const isDonut = spec.type === 'doughnut';
    const ink = css('--color-text-muted');
    const grid = css('--color-border-subtle');
    const surface = css('--color-surface');
    const rtl = document.documentElement.dir === 'rtl';

    const datasets = spec.datasets.map((d) => {
      const c = Array.isArray(d.color) ? d.color.map(color) : color(d.color || 'c1');
      const base = { label: d.label, data: d.data };
      if (isDonut) return { ...base, backgroundColor: c, borderColor: surface, borderWidth: 2, hoverOffset: 4 };
      if (spec.type === 'line') {
        return { ...base, borderColor: c, backgroundColor: c, borderWidth: 2, pointRadius: d.data.length > 40 ? 0 : 3, pointHoverRadius: 5, pointBackgroundColor: c, pointBorderColor: surface, pointBorderWidth: 2, tension: 0.25, fill: false };
      }
      return { ...base, backgroundColor: c, borderColor: surface, borderWidth: spec.stacked ? { top: 2 } : 0, borderRadius: 4, borderSkipped: 'start', maxBarThickness: 28 };
    });

    const old = charts.get(id);
    if (old) old.destroy();

    const chart = new Chart(canvas, {
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
          if (url) window.location.href = url;
        },
        plugins: {
          legend: {
            display: isDonut || datasets.length > 1,
            position: isDonut ? 'bottom' : 'top',
            align: 'start',
            rtl,
            labels: { color: ink, boxWidth: 10, boxHeight: 10, usePointStyle: true, pointStyle: 'rectRounded', padding: 14, font: { size: 12 } },
          },
          tooltip: {
            rtl,
            backgroundColor: css('--color-surface-elevated'),
            titleColor: css('--color-text'),
            bodyColor: css('--color-text-secondary'),
            borderColor: css('--color-border'),
            borderWidth: 1,
            padding: 10,
            boxPadding: 4,
            usePointStyle: true,
            callbacks: {
              label: (ctx) => {
                const text = ' ' + (ctx.dataset.label ? ctx.dataset.label + ': ' : '') + fmt(isDonut ? ctx.parsed : (spec.horizontal ? ctx.parsed.x : ctx.parsed.y), spec.money);
                if (!isDonut) return text;
                // A doughnut slice is a share of a whole — the tooltip is the natural place for that percentage.
                const total = ctx.dataset.data.reduce((sum, v) => sum + v, 0);
                const pct = total > 0 ? Math.round((ctx.parsed / total) * 1000) / 10 : 0;
                return text + ` (${pct}%)`;
              },
              footer: (items) => (spec.links && items.length && spec.links[items[0].dataIndex]) ? canvas.dataset.clickHint || '' : '',
            },
          },
        },
        scales: isDonut ? {} : {
          x: { stacked: !!spec.stacked, reverse: rtl && !spec.horizontal, grid: { display: !!spec.horizontal, color: grid }, border: { display: false }, ticks: { color: ink, maxRotation: 0, autoSkipPadding: 12, font: { size: 11.5 }, ...(spec.horizontal ? { callback: (v) => fmt(v, spec.money) } : {}) } },
          y: { stacked: !!spec.stacked, position: rtl ? 'right' : 'left', beginAtZero: true, grid: { display: !spec.horizontal, color: grid }, border: { display: false }, ticks: { color: ink, precision: spec.money ? undefined : 0, font: { size: 11.5 }, ...(spec.horizontal ? {} : { callback: (v) => fmt(v, spec.money) }) } },
        },
      },
    });
    charts.set(id, chart);
    canvas.parentElement.classList.add('is-ready');
  }

  function buildAll() { document.querySelectorAll('canvas[data-ls-chart]').forEach(build); }

  // Chart ⇄ table toggle (each chart keeps an accessible table of the same numbers).
  document.addEventListener('click', (e) => {
    const btn = e.target.closest('[data-ls-chart-toggle]');
    if (!btn) return;
    const box = document.getElementById(btn.dataset.lsChartToggle);
    const showTable = box.classList.toggle('is-table');
    btn.setAttribute('aria-pressed', showTable ? 'true' : 'false');
  });

  function start() {
    if (!window.Chart) {
      document.querySelectorAll('.ls-chart').forEach((el) => el.classList.add('is-table', 'is-failed'));
      return;
    }
    Chart.defaults.font.family = getComputedStyle(document.body).fontFamily;
    buildAll();
    new MutationObserver(() => buildAll()).observe(document.documentElement, { attributes: true, attributeFilter: ['data-theme'] });
  }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', start); else start();
})();
