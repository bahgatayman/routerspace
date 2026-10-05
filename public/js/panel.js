/* ==========================================================================
   Link Space Panel — shared UI runtime (vanilla, no build step)
   window.LS: toast · open/close (modal + drawer) · busy · money · duration · theme

   Declarative hooks:
     data-ls-open="id" / data-ls-close      open / close an overlay (modal or drawer)
     data-ls-menu="id"                       button that toggles popover #id (role=menu)
     data-ls-theme-option="light|dark|system"  theme menu items
     data-ls-nav-toggle                      collapse/expand the desktop sidebar
     data-ls-side-open / data-ls-side-close  open/close the mobile sidebar drawer
     data-ls-since / -until / -progress-*    live timers, amounts and progress
     data-ls-search="group"                  client filter over [data-ls-item="group"]

   Strings: window.LS_I18N (from lang files, set by the layout).
   ========================================================================== */
(() => {
  'use strict';
  const LS = (window.LS = window.LS || {});
  const T = Object.assign({ h: 'h', m: 'm', s: 's', left: ':d left', ended: 'Time is up', dismiss: 'Dismiss', expand: 'Expand sidebar', collapse: 'Collapse sidebar' }, window.LS_I18N || {});
  const root = document.documentElement;
  const rtl = root.dir === 'rtl';
  const store = {
    get: (k) => { try { return localStorage.getItem(k); } catch (e) { return null; } },
    set: (k, v) => { try { localStorage.setItem(k, v); } catch (e) { /* storage blocked */ } },
  };

  /* ------------------------------------------------------------- theme */
  const media = window.matchMedia ? window.matchMedia('(prefers-color-scheme: dark)') : null;
  LS.theme = {
    pref() { const p = store.get('ls-theme'); return p === 'light' || p === 'dark' ? p : 'system'; },
    resolved(pref = this.pref()) { return pref === 'system' ? (media && media.matches ? 'dark' : 'light') : pref; },
    apply() {
      const pref = this.pref();
      root.classList.add('ls-theme-switching');
      root.dataset.theme = this.resolved(pref);
      root.dataset.themePref = pref;
      requestAnimationFrame(() => requestAnimationFrame(() => root.classList.remove('ls-theme-switching')));
      document.querySelectorAll('[data-ls-theme-option]').forEach((b) => b.setAttribute('aria-checked', b.dataset.lsThemeOption === pref ? 'true' : 'false'));
      document.querySelectorAll('[data-ls-theme-icon]').forEach((i) => i.toggleAttribute('hidden', i.dataset.lsThemeIcon !== root.dataset.theme));
    },
    set(pref) {
      store.set('ls-theme', pref);
      // A short cross-fade where supported (and motion is welcome); otherwise instant.
      const calm = window.matchMedia && matchMedia('(prefers-reduced-motion: reduce)').matches;
      if (document.startViewTransition && !calm) document.startViewTransition(() => this.apply());
      else this.apply();
    },
  };
  if (media) (media.addEventListener ? media.addEventListener('change', () => LS.theme.pref() === 'system' && LS.theme.apply()) : media.addListener(() => LS.theme.pref() === 'system' && LS.theme.apply()));
  window.addEventListener('storage', (e) => { if (e.key === 'ls-theme') LS.theme.apply(); });
  // Fallback for browsers that miss the media change event: re-check when the tab comes back.
  const recheck = () => { if (LS.theme.pref() === 'system' && root.dataset.theme !== LS.theme.resolved()) LS.theme.apply(); };
  window.addEventListener('focus', recheck);
  document.addEventListener('visibilitychange', () => { if (!document.hidden) recheck(); });

  /* -------------------------------------------------------- formatting */
  LS.money = (n) => {
    const s = (Number(n) || 0).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    return rtl ? `${s} ج.م` : `EGP ${s}`;
  };
  LS.duration = (mins) => {
    mins = Math.max(0, Math.floor(mins));
    const h = Math.floor(mins / 60), m = mins % 60;
    return h ? `${h}${T.h} ${String(m).padStart(2, '0')}${T.m}` : `${m}${T.m}`;
  };
  // Elapsed-time display down to the second (a live "since" duration, not a
  // countdown) — used for the Active Sessions "Time" figure specifically;
  // LS.duration() above stays minute-granularity for countdowns ("X left"),
  // where second-level precision would just be noise.
  LS.durationSeconds = (totalSeconds) => {
    totalSeconds = Math.max(0, Math.floor(totalSeconds));
    const h = Math.floor(totalSeconds / 3600);
    const m = Math.floor((totalSeconds % 3600) / 60);
    const s = totalSeconds % 60;
    const mm = h ? String(m).padStart(2, '0') : String(m);
    return (h ? `${h}${T.h} ` : '') + `${mm}${T.m} ${String(s).padStart(2, '0')}${T.s}`;
  };

  /* ------------------------------------------------------------ toasts */
  const ICONS = {
    ok: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m5 12.5 4.5 4.5L19 7.5"/></svg>',
    danger: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><circle cx="12" cy="12" r="9"/><path d="M12 7.5v5.5M12 16.5h.01"/></svg>',
    x: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><path d="M6 6l12 12M18 6 6 18"/></svg>',
  };
  ICONS.warn = ICONS.danger; ICONS.info = ICONS.ok;
  function stack() {
    let s = document.querySelector('.ls-toasts');
    if (!s) { s = document.createElement('div'); s.className = 'ls-toasts'; s.setAttribute('role', 'status'); s.setAttribute('aria-live', 'polite'); document.body.appendChild(s); }
    return s;
  }
  LS.toast = (msg, opts = {}) => {
    const tone = opts.tone || 'ok';
    const el = document.createElement('div');
    el.className = `ls-toast ls-toast--${tone}`;
    if (tone === 'danger') el.setAttribute('role', 'alert');
    el.innerHTML = `${ICONS[tone] || ICONS.ok}<span></span>`;
    el.querySelector('span').textContent = msg;
    let timer;
    const dismiss = () => { clearTimeout(timer); el.classList.add('is-leaving'); setTimeout(() => el.remove(), 220); };
    if (opts.action) {
      const b = document.createElement('button');
      b.type = 'button'; b.textContent = opts.action.label;
      b.addEventListener('click', () => { opts.action.onClick(); dismiss(); });
      el.appendChild(b);
    }
    const x = document.createElement('button');
    x.type = 'button'; x.className = 'ls-toast-x'; x.innerHTML = ICONS.x; x.setAttribute('aria-label', T.dismiss);
    x.addEventListener('click', dismiss);
    el.appendChild(x);
    stack().appendChild(el);
    const ms = opts.timeout || (tone === 'danger' ? 8000 : 4500);
    timer = setTimeout(dismiss, ms);
    el.addEventListener('mouseenter', () => clearTimeout(timer));
    el.addEventListener('mouseleave', () => { timer = setTimeout(dismiss, 2000); });
    return dismiss;
  };
  LS.toastAfterReload = (msg, tone = 'ok') => { try { sessionStorage.setItem('ls-toast', JSON.stringify({ msg, tone })); } catch (e) { /* storage blocked */ } };
  LS.reloadWithToast = (msg, tone) => { LS.toastAfterReload(msg, tone); location.reload(); };

  /* ---------------------------------------------- modal / drawer + trap */
  const FOCUSABLE = 'a[href], button:not([disabled]), input:not([disabled]):not([type=hidden]), select:not([disabled]), textarea:not([disabled]), [tabindex]:not([tabindex="-1"])';
  const visible = (el) => !!(el.offsetWidth || el.offsetHeight || el.getClientRects().length);
  const openStack = [];
  // Background-scroll lock: shared between the modal/drawer stack above and
  // the mobile sidebar menu below (a separate, bespoke toggle — see side()),
  // so either one locks the body and neither can prematurely unlock it while
  // the other is still open.
  let sideOpen = false;
  const updateBodyLock = () => document.body.classList.toggle('ls-lock', sideOpen || openStack.length > 0);
  LS.open = (id) => {
    const ov = typeof id === 'string' ? document.getElementById(id) : id;
    if (!ov) return;
    if (ov.classList.contains('is-closing')) { clearTimeout(ov.__closeTimer); ov.classList.remove('is-open', 'is-closing'); }
    if (ov.classList.contains('is-open')) return;
    ov.__lastFocus = document.activeElement;
    ov.classList.add('is-open');
    ov.setAttribute('aria-hidden', 'false');
    openStack.push(ov);
    updateBodyLock();
    const dialog = ov.querySelector('.ls-dialog') || ov;
    const first = dialog.querySelector('[autofocus]') || [...dialog.querySelectorAll('.ls-dialog-body ' + FOCUSABLE.split(', ').join(', .ls-dialog-body '))].find(visible) || dialog.querySelector('[data-ls-close]');
    setTimeout(() => { if (first) first.focus({ preventScroll: true }); }, 30);
    ov.dispatchEvent(new CustomEvent('ls:open'));
  };
  LS.close = (id) => {
    const ov = typeof id === 'string' ? document.getElementById(id) : (id || openStack[openStack.length - 1]);
    if (!ov || !ov.classList.contains('is-open') || ov.classList.contains('is-closing')) return;
    // Brief fade/sink before hiding (CSS .is-closing); reduced motion makes it instant.
    ov.classList.add('is-closing');
    clearTimeout(ov.__closeTimer);
    ov.__closeTimer = setTimeout(() => ov.classList.remove('is-open', 'is-closing'), 140);
    ov.setAttribute('aria-hidden', 'true');
    openStack.splice(openStack.indexOf(ov), 1);
    updateBodyLock();
    if (ov.__lastFocus && ov.__lastFocus.focus && document.contains(ov.__lastFocus)) ov.__lastFocus.focus({ preventScroll: true });
    ov.dispatchEvent(new CustomEvent('ls:close'));
  };
  function trapTab(e, container) {
    const items = [...container.querySelectorAll(FOCUSABLE)].filter(visible);
    if (!items.length) return;
    const first = items[0], last = items[items.length - 1];
    if (e.shiftKey && document.activeElement === first) { e.preventDefault(); last.focus(); }
    else if (!e.shiftKey && document.activeElement === last) { e.preventDefault(); first.focus(); }
    else if (!container.contains(document.activeElement)) { e.preventDefault(); first.focus(); }
  }

  /* ------------------------------------------------------ popover menus */
  let openMenu = null;
  function closeMenu(returnFocus) {
    if (!openMenu) return;
    const { pop, trigger } = openMenu;
    pop.hidden = true; trigger.setAttribute('aria-expanded', 'false');
    openMenu = null;
    if (returnFocus) trigger.focus();
  }
  function toggleMenu(trigger) {
    const pop = document.getElementById(trigger.dataset.lsMenu);
    if (!pop) return;
    if (openMenu && openMenu.pop === pop) { closeMenu(false); return; }
    closeMenu(false);
    pop.hidden = false; trigger.setAttribute('aria-expanded', 'true');
    openMenu = { pop, trigger };
    const item = pop.querySelector('[aria-checked="true"]') || pop.querySelector('[role^=menuitem], a, button');
    if (item && trigger.dataset.lsMenuFocus !== 'none') item.focus();
  }

  /* ------------------------------------------------------ sidebar */
  function setNav(collapsed) {
    if (collapsed) root.dataset.nav = 'collapsed'; else delete root.dataset.nav;
    store.set('ls-nav', collapsed ? 'collapsed' : 'expanded');
    document.querySelectorAll('[data-ls-nav-toggle]').forEach((b) => {
      b.setAttribute('aria-expanded', collapsed ? 'false' : 'true');
      b.setAttribute('aria-label', collapsed ? T.expand : T.collapse);
      b.title = collapsed ? T.expand : T.collapse;
    });
  }
  function side(open) {
    const s = document.getElementById('sidebar'), scrim = document.getElementById('sidebar-overlay');
    if (!s) return;
    s.classList.toggle('is-open', open);
    if (scrim) scrim.classList.toggle('is-open', open);
    // Lock the background from scrolling while the mobile drawer is open —
    // without this, scrolling the nav list past its end chains into
    // scrolling the page behind the (fixed, overlaying) drawer.
    sideOpen = open;
    updateBodyLock();
    const btn = document.querySelector('[data-ls-side-open]');
    if (btn) btn.setAttribute('aria-expanded', open ? 'true' : 'false');
    if (open) { const f = s.querySelector('.ls-nav-item.is-active') || s.querySelector('a, button'); if (f) setTimeout(() => f.focus(), 60); }
    else if (btn && s.contains(document.activeElement)) btn.focus();
  }
  window.closeSidebar = () => side(false);

  /* ------------------------------------------------------ events */
  document.addEventListener('click', (e) => {
    const t = e.target;
    const opener = t.closest('[data-ls-open]');
    if (opener) { e.preventDefault(); LS.open(opener.dataset.lsOpen); return; }
    const closer = t.closest('[data-ls-close]');
    if (closer) { LS.close(closer.closest('.ls-overlay')); return; }
    if (t.classList && t.classList.contains('ls-overlay')) { LS.close(t); return; }
    const themeBtn = t.closest('[data-ls-theme-option]');
    if (themeBtn) { LS.theme.set(themeBtn.dataset.lsThemeOption); closeMenu(true); return; }
    const menuBtn = t.closest('[data-ls-menu]');
    if (menuBtn) { toggleMenu(menuBtn); return; }
    if (openMenu && !openMenu.pop.contains(t)) closeMenu(false);
    if (t.closest('[data-ls-nav-toggle]')) { setNav(root.dataset.nav !== 'collapsed'); return; }
    if (t.closest('[data-ls-side-open]')) { side(true); return; }
    if (t.closest('[data-ls-side-close]') || t.id === 'sidebar-overlay') { side(false); }
  });
  document.addEventListener('keydown', (e) => {
    if (e.key === 'Escape') {
      if (openMenu) { closeMenu(true); return; }
      if (openStack.length) { LS.close(); return; }
      const s = document.getElementById('sidebar');
      if (s && s.classList.contains('is-open')) { side(false); return; }
    }
    if (e.key === 'Tab' && openStack.length) { trapTab(e, openStack[openStack.length - 1].querySelector('.ls-dialog') || openStack[openStack.length - 1]); return; }
    if (openMenu && ['ArrowDown', 'ArrowUp', 'Home', 'End'].includes(e.key)) {
      const items = [...openMenu.pop.querySelectorAll('[role^=menuitem], a[href], button')].filter(visible);
      const i = items.indexOf(document.activeElement);
      const n = e.key === 'Home' ? 0 : e.key === 'End' ? items.length - 1 : (i + (e.key === 'ArrowDown' ? 1 : -1) + items.length) % items.length;
      if (items[n]) { e.preventDefault(); items[n].focus(); }
      return;
    }
    // "/" focuses the page search, unless the user is already typing.
    if (e.key === '/' && !e.target.closest('input, textarea, select, [contenteditable]')) {
      const s = document.querySelector('[data-ls-search]');
      if (s) { e.preventDefault(); s.focus(); }
    }
  });

  /* ------------------------------------------ password show / hide */
  // <x-ui.password>: toggles its input between hidden and visible, keeping
  // focus and cursor in place; always re-hidden on submit.
  document.addEventListener('click', (e) => {
    const btn = e.target.closest('[data-ls-reveal]');
    if (!btn) return;
    const input = document.getElementById(btn.dataset.lsReveal);
    if (!input) return;
    const show = input.type === 'password';
    input.type = show ? 'text' : 'password';
    btn.setAttribute('aria-pressed', show ? 'true' : 'false');
    const label = show ? btn.dataset.labelHide : btn.dataset.labelShow;
    btn.setAttribute('aria-label', label);
    btn.title = label;
  });
  document.addEventListener('submit', (e) => {
    e.target.querySelectorAll('[data-ls-reveal][aria-pressed="true"]').forEach((btn) => btn.click());
  }, true);

  /* ------------------------------------------------------ busy buttons */
  LS.busy = (btn, on = true) => {
    if (!btn) return;
    btn.classList.toggle('is-loading', on);
    btn.disabled = on;
    btn.setAttribute('aria-busy', on ? 'true' : 'false');
  };
  document.addEventListener('submit', (e) => { if (e.target.matches('[data-ls-busy]')) LS.busy(e.target.querySelector('[type=submit]')); });

  /* ------------------------------------------------------ live values */
  // Live timers measure against the SERVER's clock: the layout stamps the
  // server time on <html data-server-now> and we keep the device's offset
  // from it, so a fast/slow phone or PC can't make timers, bills or
  // countdowns disagree with what checkout will charge. Offsets under 2s
  // (page-load latency) are ignored.
  const SERVER_SKEW = (() => {
    const s = parseInt(root.dataset.serverNow || '', 10);
    if (!s) return 0;
    const skew = s - Date.now();
    return Math.abs(skew) > 2000 ? skew : 0;
  })();
  LS.now = () => Date.now() + SERVER_SKEW;

  function tick() {
    const now = LS.now();
    // Navbar clock (#ls-clock) is server-rendered once at page load, so it
    // otherwise goes stale the moment a tab is left open — keep it ticking
    // with the browser's own clock, same "g:i A" shape as the PHP default.
    const clock = document.getElementById('ls-clock');
    if (clock) {
      const d = new Date(now);
      let h = d.getHours();
      const ampm = h >= 12 ? 'PM' : 'AM';
      h = h % 12 || 12;
      clock.textContent = `${h}:${String(d.getMinutes()).padStart(2, '0')} ${ampm}`;
    }
    document.querySelectorAll('[data-ls-since]').forEach((el) => {
      const secs = (now - Date.parse(el.dataset.lsSince)) / 1000;
      if (el.dataset.lsRate !== undefined) {
        // Never negative: a start "after now" bills nothing yet (server does the same).
        el.textContent = LS.money((Math.max(0, secs) / 3600) * parseFloat(el.dataset.lsRate) + parseFloat(el.dataset.lsExtra || 0));
      } else if (el.dataset.lsSeconds !== undefined) {
        el.textContent = LS.durationSeconds(secs);
      } else {
        el.textContent = LS.duration(secs / 60);
      }
    });
    document.querySelectorAll('[data-ls-until]').forEach((el) => {
      const left = (Date.parse(el.dataset.lsUntil) - now) / 60000;
      el.textContent = left > 0 ? T.left.replace(':d', LS.duration(left)) : T.ended;
      el.classList.toggle('is-soon', left <= 15);
    });
    // Countdown to a server-computed instant (e.g. a shared session's next
    // billable hour). When it passes, the page refreshes once so the new
    // amount comes from the server — never computed here.
    document.querySelectorAll('[data-ls-countdown]').forEach((el) => {
      const left = (Date.parse(el.dataset.lsCountdown) - now) / 60000;
      if (left > 0) { el.textContent = el.dataset.lsTemplate.replace(':d', LS.duration(Math.max(1, Math.ceil(left)))); return; }
      el.textContent = el.dataset.lsDone || '';
      // At most one such reload every 20s, even across reloads — so a clock
      // mismatch can never turn into a reload loop.
      let last = 0;
      try { last = parseInt(sessionStorage.getItem('ls-countdown-reload') || '0', 10); } catch (e) { /* storage blocked */ }
      if (!LS._countdownReload && Date.now() - last > 20000 && !document.querySelector('.ls-overlay.is-open') && !(document.activeElement && document.activeElement.matches('input, textarea, select'))) {
        LS._countdownReload = true;
        try { sessionStorage.setItem('ls-countdown-reload', String(Date.now())); } catch (e) { /* storage blocked */ }
        setTimeout(() => location.reload(), 1500);
      }
    });
    document.querySelectorAll('[data-ls-progress-from]').forEach((el) => {
      const a = Date.parse(el.dataset.lsProgressFrom), b = Date.parse(el.dataset.lsProgressTo);
      el.style.width = Math.max(2, Math.min(100, ((now - a) / (b - a)) * 100)) + '%';
      const meter = el.parentElement;
      if (meter) meter.classList.toggle('is-warn', (b - now) / 60000 <= 15);
    });
  }
  LS.tick = tick;

  /* ------------------------------------------------------ client search */
  function applySearch(input) {
    const group = input.dataset.lsSearch;
    const q = input.value.trim().toLowerCase();
    let shown = 0;
    document.querySelectorAll(`[data-ls-item="${group}"]`).forEach((it) => {
      const hit = !q || (it.dataset.lsText || it.textContent).toLowerCase().includes(q);
      it.hidden = !hit;
      if (hit) shown++;
    });
    const empty = document.querySelector(`[data-ls-empty="${group}"]`);
    if (empty) empty.hidden = shown > 0;
    const wrap = input.closest('.ls-search');
    if (wrap) wrap.classList.toggle('has-value', !!q);
  }
  document.addEventListener('input', (e) => { if (e.target.matches('[data-ls-search]')) applySearch(e.target); });
  document.addEventListener('click', (e) => {
    const c = e.target.closest('.ls-search .ls-clear');
    if (!c) return;
    const input = c.parentElement.querySelector('input');
    input.value = ''; applySearch(input); input.focus();
  });

  /* ------------------------------------------------------ boot */
  document.addEventListener('DOMContentLoaded', () => {
    LS.theme.apply();
    setNav(root.dataset.nav === 'collapsed');
    tick();
    setInterval(tick, 1000);
    try {
      const pending = sessionStorage.getItem('ls-toast');
      if (pending) { sessionStorage.removeItem('ls-toast'); const t = JSON.parse(pending); LS.toast(t.msg, { tone: t.tone }); }
    } catch (e) { /* storage blocked */ }
    document.querySelectorAll('[data-ls-flash]').forEach((el) => LS.toast(el.dataset.lsFlash, { tone: el.dataset.lsTone || 'ok' }));
  });
})();
