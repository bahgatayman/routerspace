{{-- Landing "Product demo": a scaled, clickable replica of the owner app
     (same sidebar groups, labels, top bar and page layouts) filled with
     sample data. Purely static markup — nothing here reads tenant data. --}}
@php
    $d = fn ($k, $r = []) => __('app.landing.demo.'.$k, $r);
    $p = fn ($k) => __('app.landing.demo.people.'.$k);
    $r = fn ($k) => __('app.landing.demo.rooms.'.$k);
    $initials = fn ($name) => collect(preg_split('/\s+/u', trim($name)))->take(2)->map(fn ($w) => mb_substr($w, 0, 1))->implode('');

    // Sidebar: the real owner nav (layouts/app.blade.php). Items with a
    // 'screen' are clickable in the demo; the rest are shown for context only.
    $nav = [
        __('app.ui.nav_group.today') => [
            ['icon' => 'home', 'label' => __('app.nav.dashboard'), 'screen' => 'dashboard'],
            ['icon' => 'live', 'label' => __('app.nav.active_sessions'), 'screen' => 'sessions', 'count' => 3],
            ['icon' => 'calendar', 'label' => __('app.nav.bookings'), 'screen' => 'bookings'],
        ],
        __('app.ui.nav_group.manage') => [
            ['icon' => 'building', 'label' => __('app.nav.workspaces'), 'screen' => 'rooms'],
            ['icon' => 'users', 'label' => __('app.nav.users'), 'screen' => 'users'],
            ['icon' => 'clock', 'label' => __('app.nav.packages')],
            ['icon' => 'box', 'label' => __('app.nav.products')],
        ],
        __('app.ui.nav_group.money') => [
            ['icon' => 'money', 'label' => __('app.nav.financials')],
            ['icon' => 'receipt', 'label' => __('app.nav.expenses')],
        ],
        __('app.ui.nav_group.admin') => [
            ['icon' => 'team', 'label' => __('app.nav.staff')],
            ['icon' => 'gear', 'label' => __('app.nav.settings')],
        ],
    ];
    $screenTitles = [
        'dashboard' => __('app.nav.dashboard'), 'sessions' => __('app.nav.active_sessions'),
        'bookings' => __('app.nav.bookings'), 'rooms' => __('app.nav.workspaces'), 'users' => __('app.nav.users'),
    ];
    $screenPaths = ['dashboard' => 'dashboard', 'sessions' => 'active-sessions', 'bookings' => 'bookings/calendar', 'rooms' => 'workspaces', 'users' => 'users'];

    // Revenue trend: last 7 days ending today.
    $trend = collect([2150, 2780, 1960, 3320, 2890, 3610, 3240])->values()->map(fn ($v, $i) => [
        'value' => $v, 'day' => now()->subDays(6 - $i)->translatedFormat('D'), 'today' => $i === 6,
    ]);
    $trendMax = $trend->max('value');

    $schedule = [
        ['time' => '10:00 – 12:00', 'room' => $r('meeting'), 'who' => $p('ahmed'), 'tone' => 'info', 'status' => __('app.status.confirmed')],
        ['time' => '13:00 – 15:30', 'room' => $r('office'), 'who' => $p('sara'), 'tone' => 'success', 'status' => __('app.status.active')],
        ['time' => '16:00 – 18:00', 'room' => $r('training'), 'who' => $p('omar'), 'tone' => 'warning', 'status' => __('app.status.pending')],
    ];

    // Seconds already elapsed when the page loads — the timers tick from there.
    $sessions = [
        ['who' => $p('mona'), 'room' => $r('shared'), 'party' => 1, 'since' => 2 * 3600 + 14 * 60 + 9, 'bill' => 110, 'tint' => 'teal'],
        ['who' => $p('sara'), 'room' => $r('office'), 'party' => 3, 'since' => 1 * 3600 + 2 * 60 + 41, 'bill' => 220, 'tint' => 'blue'],
        ['who' => $p('youssef'), 'room' => $r('shared'), 'party' => 2, 'since' => 38 * 60 + 5, 'bill' => 50, 'tint' => 'violet'],
    ];

    // Day timeline 09:00–21:00 (12 one-hour columns); start/len in hours.
    $timeline = [
        ['room' => $r('meeting'), 'blocks' => [['start' => 10, 'len' => 2, 'who' => $p('ahmed'), 'tone' => 'info'], ['start' => 15, 'len' => 3, 'who' => $p('omar'), 'tone' => 'info']]],
        ['room' => $r('office'), 'blocks' => [['start' => 9, 'len' => 3, 'who' => $p('mona'), 'tone' => 'done'], ['start' => 13, 'len' => 2.5, 'who' => $p('sara'), 'tone' => 'success']]],
        ['room' => $r('training'), 'blocks' => [['start' => 16, 'len' => 2, 'who' => $p('omar'), 'tone' => 'warning']]],
        ['room' => $r('shared'), 'blocks' => [['start' => 11, 'len' => 6, 'who' => $p('youssef'), 'tone' => 'teal']]],
    ];
    $nowHour = 13.5; // the "now" line on the timeline

    $rooms = [
        ['name' => $r('meeting'), 'type' => __('app.room_type.meeting'), 'seats' => 8, 'price' => 150, 'busy' => false, 'tint' => 'blue', 'icon' => 'door'],
        ['name' => $r('office'), 'type' => __('app.room_type.office'), 'seats' => 4, 'price' => 200, 'busy' => true, 'tint' => 'violet', 'icon' => 'building'],
        ['name' => $r('shared'), 'type' => __('app.room_type.shared'), 'seats' => 20, 'price' => 50, 'busy' => false, 'free' => 17, 'tint' => 'teal', 'icon' => 'users'],
        ['name' => $r('training'), 'type' => __('app.room_type.training'), 'seats' => 16, 'price' => 300, 'busy' => false, 'tint' => 'amber', 'icon' => 'class'],
    ];

    $users = [
        ['who' => $p('ahmed'), 'phone' => '0100 123 4567', 'pkg' => $d('pkg_10h'), 'online' => true, 'tint' => 'blue'],
        ['who' => $p('sara'), 'phone' => '0111 234 5678', 'pkg' => $d('pkg_month'), 'online' => true, 'tint' => 'violet'],
        ['who' => $p('mona'), 'phone' => '0122 345 6789', 'pkg' => $d('pkg_payg'), 'online' => true, 'tint' => 'teal'],
        ['who' => $p('omar'), 'phone' => '0155 456 7890', 'pkg' => $d('pkg_10h'), 'online' => false, 'tint' => 'amber'],
        ['who' => $p('youssef'), 'phone' => '0106 567 8901', 'pkg' => $d('pkg_payg'), 'online' => false, 'tint' => 'blue'],
    ];
@endphp

<style>
    .lsd { --lsd-bg: #f3f3f1; --lsd-sheet: #fdfdfc; --lsd-card: #fff; --lsd-line: #e7e5e4; --lsd-line-2: #f0efed; --lsd-text: #1c1917; --lsd-text-2: #57534e; --lsd-muted: #8a8580;
           --lsd-primary: #2e4f8f; --lsd-success: #2f6e4f; --lsd-success-soft: #e8f3ec; --lsd-warning: #9a5b0b; --lsd-warning-soft: #fdf3e2; --lsd-danger: #b42318; --lsd-danger-soft: #fdecea;
           --lsd-info: #2e4f8f; --lsd-info-soft: #eaf0fa; --lsd-teal: #0f6e6e; --lsd-teal-soft: #e2f3f2; --lsd-violet: #5b3fa8; --lsd-violet-soft: #efeafb; --lsd-amber-soft: #fbf0dd;
           background: #fff; border: 1px solid var(--lsd-line); border-radius: 18px; overflow: hidden; box-shadow: 0 30px 70px -30px rgba(28,25,23,.35), 0 2px 6px rgba(28,25,23,.04); text-align: start; }
    .lsd .ls-icon { width: 16px; height: 16px; flex-shrink: 0; }
    .lsd-chrome { display: flex; align-items: center; gap: 12px; padding: 10px 14px; background: #f7f7f5; border-bottom: 1px solid var(--lsd-line); }
    .lsd-dots { display: flex; gap: 6px; } .lsd-dots i { width: 10px; height: 10px; border-radius: 50%; background: #e2e0dd; } .lsd-dots i:nth-child(1) { background: #f6a29b; } .lsd-dots i:nth-child(2) { background: #f7d27a; } .lsd-dots i:nth-child(3) { background: #8fd6a1; }
    .lsd-url { flex: 1; max-width: 420px; margin-inline: auto; display: flex; align-items: center; gap: 6px; height: 26px; padding: 0 12px; border-radius: 8px; background: #fff; border: 1px solid var(--lsd-line); font-size: 11.5px; color: var(--lsd-muted); direction: ltr; justify-content: center; }
    .lsd-url .ls-icon { width: 12px; height: 12px; }
    .lsd-sample { font-size: 10.5px; font-weight: 600; color: var(--lsd-primary); background: var(--lsd-info-soft); padding: 3px 9px; border-radius: 999px; white-space: nowrap; }

    .lsd-app { display: grid; grid-template-columns: 200px minmax(0, 1fr); background: var(--lsd-bg); height: 620px; }
    .lsd-side { display: flex; flex-direction: column; padding: 14px 10px 10px; min-height: 0; }
    .lsd-brand { padding: 2px 8px 12px; } .lsd-brand img { height: 22px; width: auto; }
    .lsd-nav { flex: 1; overflow: hidden; }
    .lsd-group + .lsd-group { margin-top: 6px; padding-top: 6px; border-top: 1px solid var(--lsd-line-2); }
    .lsd-group-label { font-size: 10.5px; font-weight: 500; color: var(--lsd-muted); padding: 3px 10px; }
    .lsd-item { width: 100%; display: flex; align-items: center; gap: 10px; padding: 6px 10px; border-radius: 9px; font-size: 12.5px; font-weight: 500; color: var(--lsd-text-2); text-align: start; transition: background-color .15s, color .15s; }
    button.lsd-item { cursor: pointer; } button.lsd-item:hover { background: rgba(28,25,23,.05); color: var(--lsd-text); }
    .lsd-item.is-active { background: var(--lsd-primary); color: #fff; font-weight: 600; box-shadow: 0 4px 12px -6px rgba(46,79,143,.6); }
    .lsd-item.is-inert { opacity: .55; cursor: default; }
    .lsd-count { margin-inline-start: auto; min-width: 18px; padding: 0 6px; height: 18px; border-radius: 999px; display: inline-flex; align-items: center; justify-content: center; font-size: 10.5px; font-weight: 700; background: var(--lsd-success-soft); color: var(--lsd-success); }
    .lsd-item.is-active .lsd-count { background: rgba(255,255,255,.22); color: #fff; }
    .lsd-who { display: flex; align-items: center; gap: 9px; padding: 10px 8px 2px; border-top: 1px solid var(--lsd-line-2); }
    .lsd-who b { display: block; font-size: 12px; color: var(--lsd-text); } .lsd-who small { display: block; font-size: 10.5px; color: var(--lsd-muted); }

    .lsd-main { margin: 8px; margin-inline-start: 0; background: var(--lsd-sheet); border: 1px solid var(--lsd-line); border-radius: 14px; display: flex; flex-direction: column; min-width: 0; min-height: 0; }
    .lsd-top { display: flex; align-items: center; gap: 12px; padding: 0 20px; height: 50px; border-bottom: 1px solid var(--lsd-line-2); flex-shrink: 0; }
    .lsd-crumbs { font-size: 12.5px; color: var(--lsd-muted); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; } .lsd-crumbs b { color: var(--lsd-text); font-weight: 500; }
    .lsd-top-end { margin-inline-start: auto; display: flex; align-items: center; gap: 14px; color: var(--lsd-text-2); font-size: 12px; }
    .lsd-clock { display: inline-flex; align-items: center; gap: 5px; font-variant-numeric: tabular-nums; }
    .lsd-bell { position: relative; display: inline-flex; } .lsd-bell em { position: absolute; top: -6px; inset-inline-end: -7px; min-width: 15px; height: 15px; border-radius: 999px; background: var(--lsd-danger); color: #fff; font-size: 9px; font-style: normal; font-weight: 700; display: flex; align-items: center; justify-content: center; }
    .lsd-body { flex: 1; overflow-y: auto; padding: 18px 20px 22px; min-height: 0; scrollbar-width: thin; }

    .lsd-screen { display: none; animation: lsdIn .25s ease; } .lsd-screen.is-active { display: block; }
    @keyframes lsdIn { from { opacity: 0; transform: translateY(4px); } to { opacity: 1; transform: none; } }
    .lsd-head { display: flex; align-items: flex-end; justify-content: space-between; gap: 12px; margin-bottom: 14px; }
    .lsd-eyebrow { font-size: 11.5px; color: var(--lsd-muted); }
    .lsd-h1 { font-size: 19px; font-weight: 700; color: var(--lsd-text); letter-spacing: -.01em; display: flex; align-items: center; gap: 8px; }
    .lsd-sub { font-size: 12px; color: var(--lsd-text-2); margin-top: 2px; }
    .lsd-pill { font-size: 11px; font-weight: 600; min-width: 20px; height: 20px; padding: 0 7px; border-radius: 999px; background: var(--lsd-info-soft); color: var(--lsd-primary); display: inline-flex; align-items: center; justify-content: center; }
    .lsd-btn { display: inline-flex; align-items: center; gap: 6px; height: 32px; padding: 0 12px; border-radius: 9px; background: var(--lsd-primary); color: #fff; font-size: 12px; font-weight: 600; white-space: nowrap; }
    .lsd-btn .ls-icon { width: 14px; height: 14px; }
    .lsd-btn.is-quiet { background: #fff; color: var(--lsd-text); border: 1px solid var(--lsd-line); }

    .lsd-card { background: var(--lsd-card); border: 1px solid var(--lsd-line); border-radius: 12px; padding: 14px 16px; min-width: 0; }
    .lsd-card-title { font-size: 12.5px; font-weight: 600; color: var(--lsd-text); display: flex; align-items: center; justify-content: space-between; gap: 8px; }
    .lsd-card-title span { font-size: 11px; font-weight: 500; color: var(--lsd-muted); }
    .lsd-grid-2 { display: grid; grid-template-columns: 1.35fr 1fr; gap: 12px; }
    .lsd-kpis { display: grid; grid-template-columns: repeat(4, minmax(0, 1fr)); gap: 12px; margin-top: 12px; }
    .lsd-kpi { display: flex; justify-content: space-between; gap: 8px; }
    .lsd-kpi-label { font-size: 11.5px; color: var(--lsd-text-2); }
    .lsd-kpi-value { font-size: 22px; font-weight: 700; color: var(--lsd-text); margin-top: 2px; font-variant-numeric: tabular-nums; }
    .lsd-kpi-note { font-size: 10.5px; color: var(--lsd-muted); margin-top: 2px; }
    .lsd-ico { width: 34px; height: 34px; border-radius: 10px; display: inline-flex; align-items: center; justify-content: center; flex-shrink: 0; }
    .tone-info { background: var(--lsd-info-soft); color: var(--lsd-info); } .tone-success { background: var(--lsd-success-soft); color: var(--lsd-success); }
    .tone-warning { background: var(--lsd-warning-soft); color: var(--lsd-warning); } .tone-danger { background: var(--lsd-danger-soft); color: var(--lsd-danger); }
    .tone-teal { background: var(--lsd-teal-soft); color: var(--lsd-teal); } .tone-violet { background: var(--lsd-violet-soft); color: var(--lsd-violet); }
    .tone-amber { background: var(--lsd-amber-soft); color: var(--lsd-warning); } .tone-blue { background: var(--lsd-info-soft); color: var(--lsd-info); }
    .tone-done { background: #f1f0ee; color: var(--lsd-text-2); }
    .is-revenue { color: var(--lsd-success) !important; } .is-danger { color: var(--lsd-danger) !important; } .is-info { color: var(--lsd-info) !important; }

    .lsd-chart-card { display: flex; flex-direction: column; }
    .lsd-chart { display: flex; align-items: flex-end; gap: 10px; flex: 1; min-height: 132px; margin-top: 14px; padding-bottom: 2px; border-bottom: 1px solid var(--lsd-line-2); }
    .lsd-bar { flex: 1; display: flex; flex-direction: column; align-items: center; justify-content: flex-end; gap: 6px; height: 100%; position: relative; }
    .lsd-bar i { width: 100%; max-width: 30px; border-radius: 5px 5px 2px 2px; background: #c9d6ee; transition: background-color .15s; }
    .lsd-bar.is-today i, .lsd-bar:hover i { background: var(--lsd-primary); }
    .lsd-bar b { position: absolute; bottom: calc(var(--h) + 6px); font-size: 10px; font-weight: 600; color: var(--lsd-text); background: #fff; border: 1px solid var(--lsd-line); padding: 1px 5px; border-radius: 5px; opacity: 0; transition: opacity .15s; white-space: nowrap; pointer-events: none; }
    .lsd-bar:hover b, .lsd-bar.is-today b { opacity: 1; }
    .lsd-days { display: flex; gap: 10px; margin-top: 6px; } .lsd-days span { flex: 1; text-align: center; font-size: 10.5px; color: var(--lsd-muted); }
    .lsd-big { font-size: 26px; font-weight: 700; letter-spacing: -.01em; font-variant-numeric: tabular-nums; margin-top: 6px; }
    .lsd-trend { display: inline-flex; align-items: center; gap: 4px; font-size: 11px; font-weight: 600; color: var(--lsd-success); background: var(--lsd-success-soft); padding: 2px 8px; border-radius: 999px; margin-top: 6px; }
    .lsd-list { margin-top: 10px; display: grid; gap: 6px; }
    .lsd-row { display: flex; align-items: center; gap: 10px; padding: 8px 10px; border-radius: 9px; background: #fafaf9; border: 1px solid var(--lsd-line-2); }
    .lsd-row-time { font-size: 11px; color: var(--lsd-text-2); width: 86px; flex-shrink: 0; font-variant-numeric: tabular-nums; direction: ltr; text-align: start; }
    [dir="rtl"] .lsd-row-time { text-align: end; }
    .lsd-row-main { flex: 1; min-width: 0; } .lsd-row-main b { display: block; font-size: 12px; font-weight: 600; color: var(--lsd-text); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; } .lsd-row-main small { font-size: 11px; color: var(--lsd-muted); }
    .lsd-badge { font-size: 10.5px; font-weight: 600; padding: 2px 8px; border-radius: 999px; white-space: nowrap; }
    .lsd-avatar { width: 30px; height: 30px; border-radius: 50%; display: inline-flex; align-items: center; justify-content: center; font-size: 10.5px; font-weight: 700; flex-shrink: 0; }

    .lsd-sessions { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: 12px; }
    .lsd-session-top { display: flex; align-items: center; gap: 10px; }
    .lsd-live { display: inline-flex; align-items: center; gap: 5px; font-size: 10.5px; font-weight: 600; color: var(--lsd-success); margin-inline-start: auto; }
    .lsd-live::before { content: ''; width: 7px; height: 7px; border-radius: 50%; background: currentColor; box-shadow: 0 0 0 3px var(--lsd-success-soft); animation: lsdPulse 1.6s ease-in-out infinite; }
    @keyframes lsdPulse { 50% { opacity: .35; } }
    .lsd-timer { font-size: 24px; font-weight: 700; color: var(--lsd-text); font-variant-numeric: tabular-nums; margin: 14px 0 2px; direction: ltr; text-align: start; }
    [dir="rtl"] .lsd-timer { text-align: end; }
    .lsd-meta { display: flex; flex-wrap: wrap; gap: 4px 10px; font-size: 11px; color: var(--lsd-text-2); }
    .lsd-meta span { display: inline-flex; align-items: center; gap: 4px; } .lsd-meta .ls-icon { width: 13px; height: 13px; }
    .lsd-bill { display: flex; align-items: center; justify-content: space-between; margin-top: 12px; padding: 9px 11px; border-radius: 9px; background: var(--lsd-info-soft); font-size: 11.5px; color: var(--lsd-text-2); }
    .lsd-bill b { font-size: 14px; color: var(--lsd-primary); font-variant-numeric: tabular-nums; }
    .lsd-session-actions { display: flex; gap: 6px; margin-top: 10px; } .lsd-session-actions .lsd-btn { flex: 1; justify-content: center; height: 30px; }

    .lsd-tl-wrap { overflow-x: auto; scrollbar-width: thin; }
    /* 24 half-hour columns (09:00–21:00) so :30 bookings sit exactly. */
    .lsd-tl { display: grid; grid-template-columns: 128px repeat(24, minmax(19px, 1fr)); min-width: 600px; position: relative; }
    .lsd-tl-h { grid-column: span 2; font-size: 10.5px; color: var(--lsd-muted); padding: 0 0 6px; border-inline-start: 1px solid var(--lsd-line-2); padding-inline-start: 4px; }
    .lsd-tl-room { grid-column: 1; font-size: 11.5px; font-weight: 600; color: var(--lsd-text); padding: 0 8px 0 0; display: flex; align-items: center; height: 46px; border-top: 1px solid var(--lsd-line-2); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
    .lsd-tl-cell { grid-column: span 2; height: 46px; border-top: 1px solid var(--lsd-line-2); border-inline-start: 1px solid var(--lsd-line-2); }
    .lsd-tl-block { align-self: center; height: 34px; margin: 0 2px; border-radius: 7px; padding: 4px 8px; font-size: 10.5px; font-weight: 600; overflow: hidden; white-space: nowrap; text-overflow: ellipsis; z-index: 2; border-inline-start: 3px solid currentColor; line-height: 1.25; }
    .lsd-tl-block small { display: block; font-weight: 500; opacity: .8; direction: ltr; text-align: start; }
    [dir="rtl"] .lsd-tl-block small { text-align: end; }
    .lsd-tl-now { width: 2px; background: var(--lsd-danger); z-index: 1; justify-self: start; position: relative; margin-inline-start: -1px; }
    .lsd-tl-now::before { content: ''; position: absolute; top: -3px; left: -3px; width: 8px; height: 8px; border-radius: 50%; background: var(--lsd-danger); }
    .lsd-legend { display: flex; flex-wrap: wrap; gap: 6px 14px; margin-top: 12px; font-size: 11px; color: var(--lsd-text-2); }
    .lsd-legend span { display: inline-flex; align-items: center; gap: 5px; } .lsd-legend i { width: 9px; height: 9px; border-radius: 3px; }

    .lsd-rooms { display: grid; grid-template-columns: repeat(4, minmax(0, 1fr)); gap: 12px; }
    .lsd-room { background: var(--lsd-card); border: 1px solid var(--lsd-line); border-radius: 12px; overflow: hidden; }
    .lsd-room-photo { height: 84px; position: relative; display: flex; align-items: center; justify-content: center; }
    .lsd-room-photo .ls-icon { width: 26px; height: 26px; opacity: .55; }
    .lsd-room-photo .lsd-badge { position: absolute; top: 8px; inset-inline-start: 8px; background: #fff; box-shadow: 0 1px 2px rgba(0,0,0,.08); display: inline-flex; align-items: center; gap: 4px; }
    .lsd-room-photo .lsd-badge::before { content: ''; width: 6px; height: 6px; border-radius: 50%; background: currentColor; }
    .lsd-room-body { padding: 10px 12px 12px; } .lsd-room-body b { font-size: 12.5px; color: var(--lsd-text); display: block; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
    .lsd-chip { display: inline-block; font-size: 10.5px; font-weight: 600; padding: 2px 7px; border-radius: 6px; margin-top: 6px; }
    .lsd-room-price { font-size: 13px; font-weight: 700; color: var(--lsd-text); margin-top: 8px; font-variant-numeric: tabular-nums; }
    .lsd-room-price span { font-size: 11px; font-weight: 500; color: var(--lsd-muted); }

    .lsd-search { display: flex; align-items: center; gap: 8px; height: 34px; padding: 0 12px; border-radius: 9px; background: #fff; border: 1px solid var(--lsd-line); color: var(--lsd-muted); font-size: 12px; max-width: 320px; margin-bottom: 12px; }
    .lsd-table { width: 100%; border-collapse: collapse; font-size: 12px; }
    .lsd-table th { text-align: start; font-size: 11px; font-weight: 500; color: var(--lsd-muted); padding: 9px 14px; background: #fafaf9; border-bottom: 1px solid var(--lsd-line); }
    .lsd-table td { padding: 9px 14px; border-bottom: 1px solid var(--lsd-line-2); color: var(--lsd-text-2); white-space: nowrap; }
    .lsd-table tr:last-child td { border-bottom: 0; }
    .lsd-table td:first-child { color: var(--lsd-text); font-weight: 600; }
    .lsd-person { display: flex; align-items: center; gap: 9px; }
    .lsd-status { display: inline-flex; align-items: center; gap: 6px; font-size: 11.5px; } .lsd-status::before { content: ''; width: 7px; height: 7px; border-radius: 50%; background: #c9c5c0; }
    .lsd-status.is-on { color: var(--lsd-success); } .lsd-status.is-on::before { background: var(--lsd-success); }

    /* Below tablet width the sidebar becomes a swipeable strip of the demo screens. */
    @media (max-width: 860px) {
        .lsd-app { grid-template-columns: minmax(0, 1fr); height: auto; }
        .lsd-side { padding: 10px 10px 0; min-width: 0; }
        .lsd-brand, .lsd-group-label, .lsd-who, .lsd-item.is-inert { display: none; }
        .lsd-nav { display: flex; gap: 6px; overflow-x: auto; scrollbar-width: none; padding-bottom: 2px; }
        .lsd-group { display: contents; }
        .lsd-item { width: auto; white-space: nowrap; background: #fff; border: 1px solid var(--lsd-line); }
        .lsd-item.is-active { border-color: var(--lsd-primary); }
        .lsd-main { margin: 10px; }
        .lsd-body { height: 560px; }
        .lsd-grid-2 { grid-template-columns: 1fr; }
        .lsd-kpis { grid-template-columns: repeat(2, minmax(0, 1fr)); }
        .lsd-sessions { grid-template-columns: 1fr; }
        .lsd-rooms { grid-template-columns: repeat(2, minmax(0, 1fr)); }
        .lsd-clock, .lsd-url span.lsd-hide-sm { display: none; }
        .lsd-table .lsd-hide-sm { display: none; }
    }
    @media (max-width: 480px) { .lsd-top { padding: 0 14px; } .lsd-body { padding: 14px; } .lsd-head .lsd-btn span { display: none; } .lsd-sample { display: none; } }
    @media (prefers-reduced-motion: reduce) { .lsd-screen, .lsd-live::before { animation: none; } }
</style>

<div class="lsd" data-lsd>
    <div class="lsd-chrome">
        <div class="lsd-dots" aria-hidden="true"><i></i><i></i><i></i></div>
        <div class="lsd-url" aria-hidden="true"><x-ui.icon name="lock" /><span>panel.link-space.net/<span data-lsd-path>dashboard</span></span></div>
        <span class="lsd-sample">{{ $d('sample_data') }}</span>
    </div>

    <div class="lsd-app">
        <aside class="lsd-side">
            <div class="lsd-brand"><img src="/logo.webp" alt="Link Space"></div>
            <nav class="lsd-nav" aria-label="{{ __('app.landing.product_demo') }}" role="tablist">
                @foreach ($nav as $group => $items)
                    <div class="lsd-group">
                        <div class="lsd-group-label">{{ $group }}</div>
                        @foreach ($items as $item)
                            @if (isset($item['screen']))
                                <button type="button" role="tab" class="lsd-item {{ $item['screen'] === 'dashboard' ? 'is-active' : '' }}"
                                        data-lsd-tab="{{ $item['screen'] }}" aria-selected="{{ $item['screen'] === 'dashboard' ? 'true' : 'false' }}" aria-controls="lsd-{{ $item['screen'] }}">
                                    <x-ui.icon :name="$item['icon']" /><span>{{ $item['label'] }}</span>
                                    @isset($item['count'])<span class="lsd-count">{{ $item['count'] }}</span>@endisset
                                </button>
                            @else
                                <div class="lsd-item is-inert" aria-hidden="true"><x-ui.icon :name="$item['icon']" /><span>{{ $item['label'] }}</span></div>
                            @endif
                        @endforeach
                    </div>
                @endforeach
            </nav>
            <div class="lsd-who">
                <span class="lsd-avatar tone-violet">{{ $initials($d('owner')) }}</span>
                <div><b>{{ $d('owner') }}</b><small>{{ $d('business') }}</small></div>
            </div>
        </aside>

        <div class="lsd-main">
            <div class="lsd-top">
                <div class="lsd-crumbs">{{ $d('business') }} / <b data-lsd-title>{{ $screenTitles['dashboard'] }}</b></div>
                <div class="lsd-top-end" aria-hidden="true">
                    <span class="lsd-clock"><x-ui.icon name="clock" /><span data-lsd-clock>{{ now()->format('g:i A') }}</span></span>
                    <span class="lsd-bell"><x-ui.icon name="bell" /><em>1</em></span>
                    <span class="lsd-avatar tone-violet" style="width:26px;height:26px;font-size:9.5px">{{ $initials($d('owner')) }}</span>
                </div>
            </div>

            <div class="lsd-body">
                {{-- ── Dashboard ── --}}
                <section class="lsd-screen is-active" id="lsd-dashboard" role="tabpanel">
                    <div class="lsd-head">
                        <div>
                            <div class="lsd-eyebrow">{{ now()->translatedFormat('l, j F') }}</div>
                            <div class="lsd-h1">{{ __('app.dashboard.welcome', ['business' => $d('business')]) }}</div>
                        </div>
                    </div>

                    <div class="lsd-grid-2">
                        <div class="lsd-card lsd-chart-card">
                            <div class="lsd-card-title">{{ __('app.dashboard.revenue_trend') }}<span>{{ $d('last_7_days') }}</span></div>
                            <div class="lsd-chart">
                                @foreach ($trend as $bar)
                                    @php $h = round($bar['value'] / $trendMax * 100); @endphp
                                    <div class="lsd-bar {{ $bar['today'] ? 'is-today' : '' }}" style="--h: {{ $h }}%">
                                        <b>{{ \App\Support\Money::format($bar['value']) }}</b>
                                        <i style="height: {{ $h }}%"></i>
                                    </div>
                                @endforeach
                            </div>
                            <div class="lsd-days">@foreach ($trend as $bar)<span>{{ $bar['day'] }}</span>@endforeach</div>
                        </div>
                        <div class="lsd-card">
                            <div class="lsd-kpi">
                                <div>
                                    <div class="lsd-kpi-label">{{ __('app.dashboard.revenue_today') }}</div>
                                    <div class="lsd-big is-revenue">{{ \App\Support\Money::format(3240) }}</div>
                                    <div class="lsd-kpi-note">{{ __('app.dashboard.revenue_this_month') }}: {{ \App\Support\Money::format(48600) }}</div>
                                    <span class="lsd-trend"><svg width="10" height="10" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" aria-hidden="true"><path d="M5 12l7-7 7 7M12 5v14"/></svg>{{ $d('vs_last_week') }}</span>
                                </div>
                                <span class="lsd-ico tone-success"><x-ui.icon name="money" /></span>
                            </div>
                            <div class="lsd-card-title" style="margin-top:16px">{{ __('app.dashboard.todays_schedule') }}</div>
                            <div class="lsd-list">
                                @foreach (array_slice($schedule, 0, 2) as $s)
                                    <div class="lsd-row">
                                        <span class="lsd-row-time">{{ $s['time'] }}</span>
                                        <div class="lsd-row-main"><b>{{ $s['room'] }}</b><small>{{ $s['who'] }}</small></div>
                                        <span class="lsd-badge tone-{{ $s['tone'] }}">{{ $s['status'] }}</span>
                                    </div>
                                @endforeach
                            </div>
                        </div>
                    </div>

                    <div class="lsd-kpis">
                        <div class="lsd-card lsd-kpi">
                            <div><div class="lsd-kpi-label">{{ __('app.label.today_bookings') }}</div><div class="lsd-kpi-value">8</div></div>
                            <span class="lsd-ico tone-warning"><x-ui.icon name="calendar" /></span>
                        </div>
                        <div class="lsd-card lsd-kpi">
                            <div>
                                <div class="lsd-kpi-label">{{ __('app.dashboard.current_occupancy') }}</div><div class="lsd-kpi-value is-info">62%</div>
                                <div class="lsd-kpi-note">{{ __('app.dashboard.seats_occupied', ['occupied' => 18, 'capacity' => 29]) }}</div>
                            </div>
                            <span class="lsd-ico tone-info"><x-ui.icon name="users" /></span>
                        </div>
                        <div class="lsd-card lsd-kpi">
                            <div><div class="lsd-kpi-label">{{ __('app.label.available_rooms') }}</div><div class="lsd-kpi-value is-revenue">3</div></div>
                            <span class="lsd-ico tone-success"><x-ui.icon name="door" /></span>
                        </div>
                        <div class="lsd-card lsd-kpi">
                            <div><div class="lsd-kpi-label">{{ __('app.dashboard.needs_attention') }}</div><div class="lsd-kpi-value is-danger">1</div><div class="lsd-kpi-note">{{ $d('attention_text') }}</div></div>
                            <span class="lsd-ico tone-danger"><x-ui.icon name="bell" /></span>
                        </div>
                    </div>
                </section>

                {{-- ── Active Sessions ── --}}
                <section class="lsd-screen" id="lsd-sessions" role="tabpanel">
                    <div class="lsd-head">
                        <div>
                            <div class="lsd-h1">{{ __('app.nav.active_sessions') }} <span class="lsd-pill">{{ count($sessions) }}</span></div>
                            <div class="lsd-sub">{{ __('app.ui.sessions.subtitle') }}</div>
                        </div>
                        <span class="lsd-btn"><x-ui.icon name="play" /><span>{{ __('app.ui.sessions.start_session') }}</span></span>
                    </div>
                    <div class="lsd-sessions">
                        @foreach ($sessions as $s)
                            <div class="lsd-card">
                                <div class="lsd-session-top">
                                    <span class="lsd-avatar tone-{{ $s['tint'] }}">{{ $initials($s['who']) }}</span>
                                    <div class="lsd-row-main"><b>{{ $s['who'] }}</b><small>{{ $s['room'] }}</small></div>
                                    <span class="lsd-live">{{ __('app.status.active') }}</span>
                                </div>
                                <div class="lsd-timer" data-lsd-since="{{ $s['since'] }}">{{ gmdate('H:i:s', $s['since']) }}</div>
                                <div class="lsd-meta">
                                    <span><x-ui.icon name="users" />{{ $d('party', ['count' => $s['party']]) }}</span>
                                    <span><x-ui.icon name="clock" />{{ now()->subSeconds($s['since'])->format('g:i A') }}</span>
                                </div>
                                <div class="lsd-bill">{{ $d('bill_so_far') }}<b>{{ \App\Support\Money::format($s['bill']) }}</b></div>
                                <div class="lsd-session-actions">
                                    <span class="lsd-btn is-quiet"><x-ui.icon name="plus" />{{ __('app.nav.products') }}</span>
                                    <span class="lsd-btn">{{ $d('check_out') }}</span>
                                </div>
                            </div>
                        @endforeach
                    </div>
                </section>

                {{-- ── Bookings: today's timeline ── --}}
                <section class="lsd-screen" id="lsd-bookings" role="tabpanel">
                    <div class="lsd-head">
                        <div>
                            <div class="lsd-eyebrow">{{ now()->translatedFormat('l, j F') }}</div>
                            <div class="lsd-h1">{{ __('app.nav.bookings') }} <span class="lsd-pill">{{ $d('bookings_today', ['count' => 6]) }}</span></div>
                        </div>
                        <span class="lsd-btn"><x-ui.icon name="plus" /><span>{{ __('app.booking.new_booking') }}</span></span>
                    </div>
                    <div class="lsd-card">
                        <div class="lsd-tl-wrap">
                            <div class="lsd-tl">
                                <div></div>
                                @for ($h = 9; $h < 21; $h++)<div class="lsd-tl-h">{{ sprintf('%02d:00', $h) }}</div>@endfor
                                @foreach ($timeline as $i => $row)
                                    @php $line = $i + 2; @endphp
                                    <div class="lsd-tl-room" style="grid-row: {{ $line }}">{{ $row['room'] }}</div>
                                    @for ($h = 0; $h < 12; $h++)<div class="lsd-tl-cell" style="grid-row: {{ $line }}; grid-column: {{ $h * 2 + 2 }} / span 2"></div>@endfor
                                    @foreach ($row['blocks'] as $b)
                                        @php
                                            $col = (int) (($b['start'] - 9) * 2) + 2;
                                            $span = (int) ($b['len'] * 2);
                                            $end = $b['start'] + $b['len'];
                                            $fmt = fn ($t) => sprintf('%02d:%02d', floor($t), ($t - floor($t)) * 60);
                                        @endphp
                                        <div class="lsd-tl-block tone-{{ $b['tone'] }}" style="grid-row: {{ $line }}; grid-column: {{ $col }} / span {{ $span }}">
                                            {{ $b['who'] }}<small>{{ $fmt($b['start']) }} – {{ $fmt($end) }}</small>
                                        </div>
                                    @endforeach
                                @endforeach
                                <div class="lsd-tl-now" style="grid-row: 2 / span {{ count($timeline) }}; grid-column: {{ (int) (($nowHour - 9) * 2) + 2 }}" aria-hidden="true"></div>
                            </div>
                        </div>
                        <div class="lsd-legend">
                            <span><i style="background:var(--lsd-info)"></i>{{ __('app.status.confirmed') }}</span>
                            <span><i style="background:var(--lsd-success)"></i>{{ __('app.status.active') }}</span>
                            <span><i style="background:var(--lsd-warning)"></i>{{ __('app.status.pending') }}</span>
                            <span><i style="background:#a8a29e"></i>{{ __('app.status.completed') }}</span>
                        </div>
                    </div>
                </section>

                {{-- ── Rooms ── --}}
                <section class="lsd-screen" id="lsd-rooms" role="tabpanel">
                    <div class="lsd-head">
                        <div>
                            <div class="lsd-h1">{{ __('app.nav.workspaces') }} <span class="lsd-pill">{{ count($rooms) }}</span></div>
                            <div class="lsd-sub">{{ $d('rooms_subtitle') }}</div>
                        </div>
                        <span class="lsd-btn"><x-ui.icon name="plus" /><span>{{ __('app.btn.add_room') }}</span></span>
                    </div>
                    <div class="lsd-rooms">
                        @foreach ($rooms as $room)
                            <div class="lsd-room">
                                <div class="lsd-room-photo tone-{{ $room['tint'] }}">
                                    <span class="lsd-badge" style="color: var({{ $room['busy'] ? '--lsd-warning' : '--lsd-success' }})">{{ $room['busy'] ? $d('in_use') : $d('available') }}</span>
                                    <x-ui.icon :name="$room['icon']" />
                                </div>
                                <div class="lsd-room-body">
                                    <b>{{ $room['name'] }}</b>
                                    <span class="lsd-chip tone-{{ $room['tint'] }}">{{ $room['type'] }}</span>
                                    <div class="lsd-meta" style="margin-top:6px">
                                        <span><x-ui.icon name="users" />{{ isset($room['free']) ? $d('seats_free', ['count' => $room['free']]) : trans_choice('app.workspace.seats_count', $room['seats'], ['count' => $room['seats']]) }}</span>
                                    </div>
                                    <div class="lsd-room-price">{{ \App\Support\Money::format($room['price']) }} <span>{{ __('app.common.slash_hr') }}</span></div>
                                </div>
                            </div>
                        @endforeach
                    </div>
                </section>

                {{-- ── Users ── --}}
                <section class="lsd-screen" id="lsd-users" role="tabpanel">
                    <div class="lsd-head">
                        <div>
                            <div class="lsd-h1">{{ __('app.nav.users') }} <span class="lsd-pill">128</span></div>
                            <div class="lsd-sub">{{ $d('users_subtitle') }}</div>
                        </div>
                        <span class="lsd-btn"><x-ui.icon name="plus" /><span>{{ __('app.btn.add_user') }}</span></span>
                    </div>
                    <div class="lsd-search"><x-ui.icon name="search" />{{ $d('search_users') }}</div>
                    <div class="lsd-card" style="padding:0;overflow:hidden">
                        <table class="lsd-table">
                            <thead><tr><th>{{ $d('col_name') }}</th><th class="lsd-hide-sm">{{ $d('col_phone') }}</th><th class="lsd-hide-sm">{{ $d('col_package') }}</th><th>{{ $d('col_status') }}</th></tr></thead>
                            <tbody>
                                @foreach ($users as $u)
                                    <tr>
                                        <td><span class="lsd-person"><span class="lsd-avatar tone-{{ $u['tint'] }}" style="width:26px;height:26px;font-size:9.5px">{{ $initials($u['who']) }}</span>{{ $u['who'] }}</span></td>
                                        <td class="lsd-hide-sm"><bdi dir="ltr">{{ $u['phone'] }}</bdi></td>
                                        <td class="lsd-hide-sm">{{ $u['pkg'] }}</td>
                                        <td><span class="lsd-status {{ $u['online'] ? 'is-on' : '' }}">{{ $u['online'] ? $d('online') : $d('offline') }}</span></td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                </section>
            </div>
        </div>
    </div>
</div>

@php $lsdTitles = $screenTitles; $lsdPaths = $screenPaths; @endphp
<script>
    (() => {
        const root = document.querySelector('[data-lsd]');
        if (!root) return;
        const titles = @json($lsdTitles);
        const paths = @json($lsdPaths);
        const tabs = [...root.querySelectorAll('[data-lsd-tab]')];

        const show = (key) => {
            tabs.forEach(t => {
                const on = t.dataset.lsdTab === key;
                t.classList.toggle('is-active', on);
                t.setAttribute('aria-selected', String(on));
                if (on && t.scrollIntoView && window.matchMedia('(max-width: 860px)').matches) t.scrollIntoView({ block: 'nearest', inline: 'center', behavior: 'smooth' });
            });
            root.querySelectorAll('.lsd-screen').forEach(s => s.classList.toggle('is-active', s.id === 'lsd-' + key));
            root.querySelector('[data-lsd-title]').textContent = titles[key];
            root.querySelector('[data-lsd-path]').textContent = paths[key];
            root.querySelector('.lsd-body').scrollTop = 0;
        };
        tabs.forEach(t => t.addEventListener('click', () => show(t.dataset.lsdTab)));

        // Live session timers + top-bar clock, ticking like the real app.
        const started = Date.now();
        const pad = n => String(n).padStart(2, '0');
        const clock = root.querySelector('[data-lsd-clock]');
        const tick = () => {
            const extra = Math.floor((Date.now() - started) / 1000);
            root.querySelectorAll('[data-lsd-since]').forEach(el => {
                const s = +el.dataset.lsdSince + extra;
                el.textContent = `${pad(Math.floor(s / 3600))}:${pad(Math.floor(s / 60) % 60)}:${pad(s % 60)}`;
            });
            if (clock) clock.textContent = new Date().toLocaleTimeString('en-US', { hour: 'numeric', minute: '2-digit' });
        };
        setInterval(tick, 1000);
    })();
</script>
