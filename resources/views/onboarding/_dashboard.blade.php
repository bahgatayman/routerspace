{{--
    Onboarding on the owner dashboard (data from the view composer in
    AppServiceProvider; staff get $onboardingOwner = null and see nothing):
      1. Welcome Wizard — a 3-step pop-up that opens once for a new owner. Closing
         it in any way records onboarding_welcomed_at on the server, so it never
         opens by itself again (multiple tabs/sessions included).
      2. Setup checklist — compact card: progress, the one "Next step", and the
         full step list. Every "done" comes from real data (OnboardingService).
         Can be hidden and reopened; hiding never touches progress.
--}}
@if ($onboardingOwner)
    @php
        $o = fn (string $key, array $r = []) => __('app.onboarding.'.$key, $r);
        $sum = $onboardingSummary;
        $next = $sum['next'];
        $icons = ['location' => 'pin', 'rooms' => 'door', 'pricing' => 'tag', 'router' => 'wifi', 'products' => 'box',
            'staff' => 'team', 'first_booking' => 'calendar', 'first_member' => 'user'];
        $kindTag = fn (array $t) => match ($t['kind']) { 'optional' => $o('optional'), 'activation' => $o('first_action'), default => null };
        $onbJs = ['stepOf' => $o('step_of', ['current' => ':current', 'total' => ':total']), 'saveFailed' => $o('save_failed'),
            'greeting' => __('app.dashboard.welcome', ['business' => ':name'])];
    @endphp

    {{-- ================= Setup checklist ================= --}}
    @if (! $checklistHidden && $sum['all_complete'])
        {{-- Everything done: a slim celebration instead of the full card. --}}
        <section class="ls-onb-done" id="onboarding-checklist" aria-labelledby="onb-title">
            <span class="ls-onb-done-icon" aria-hidden="true"><x-ui.icon name="check" /></span>
            <div class="ls-onb-done-main">
                <h2 class="ls-onb-done-title" id="onb-title">{{ $o('complete_title') }}</h2>
                <p>{{ $o('complete_text') }}</p>
            </div>
            <form method="POST" action="{{ route('onboarding.checklist.hide') }}" data-onb-hide>
                @csrf
                <button type="submit" class="ls-btn ls-btn--secondary ls-btn--sm">{{ $o('checklist_hide') }}</button>
            </form>
        </section>
    @elseif (! $checklistHidden)
        <section class="ls-card ls-onb" id="onboarding-checklist" aria-labelledby="onb-title">
            <header class="ls-onb-head">
                <div class="ls-onb-head-main">
                    <span class="ls-onb-eyebrow">{{ $o('eyebrow') }}</span>
                    <h2 class="ls-onb-title" id="onb-title">{{ $sum['essential_complete'] ? $o('ready_title') : $o('checklist_title') }}</h2>
                </div>
                <form method="POST" action="{{ route('onboarding.checklist.hide') }}" data-onb-hide>
                    @csrf
                    <button type="submit" class="ls-btn ls-btn--ghost ls-btn--sm" aria-label="{{ $o('checklist_hide_aria') }}">{{ $o('checklist_hide') }}</button>
                </form>
            </header>

            {{-- Segmented progress: one segment per applicable step. --}}
            <div class="ls-onb-progress">
                <div class="ls-onb-segs" role="img" aria-label="{{ $o('checklist_progress', ['done' => $sum['done'], 'total' => $sum['total']]) }}">
                    @foreach ($onboardingTasks as $task)<i class="{{ $task['done'] ? 'is-done' : '' }}"></i>@endforeach
                </div>
                <span class="ls-onb-count">{{ $o('checklist_progress', ['done' => $sum['done'], 'total' => $sum['total']]) }}</span>
            </div>

            <div class="ls-onb-body">
                {{-- The one thing to do now. --}}
                @if ($next)
                    <div class="ls-onb-next">
                        <span class="ls-onb-next-label">{{ $o('next_step') }}</span>
                        <div class="ls-onb-next-head">
                            <span class="ls-onb-next-icon" aria-hidden="true"><x-ui.icon :name="$icons[$next['key']] ?? 'check-circle'" /></span>
                            <h3 class="ls-onb-next-title">{{ $next['title'] }}@if ($kindTag($next))<em class="ls-onb-tag">{{ $kindTag($next) }}</em>@endif</h3>
                        </div>
                        <p class="ls-onb-next-text">{{ $next['text'] }}</p>
                        <a href="{{ $next['url'] }}" class="ls-btn ls-btn--primary ls-onb-next-cta">
                            <span>{{ $next['action'] }}</span><x-ui.icon name="arrow-right" class="ls-arrow" />
                        </a>
                    </div>
                @endif

                {{-- Every step, in order. --}}
                <ol class="ls-onb-steps">
                    @foreach ($onboardingTasks as $i => $task)
                        @php $isNext = $next && $next['key'] === $task['key']; @endphp
                        <li class="{{ $task['done'] ? 'is-done' : '' }} {{ $isNext ? 'is-next' : '' }}">
                            @if ($task['done'])
                                <span class="ls-onb-step">
                                    <span class="ls-onb-num" aria-hidden="true"><x-ui.icon name="check" /></span>
                                    <span class="ls-onb-step-title">{{ $task['title'] }}</span>
                                    <span class="ls-onb-step-state">{{ $o('done') }}</span>
                                </span>
                            @else
                                <a href="{{ $task['url'] }}" class="ls-onb-step">
                                    <span class="ls-onb-num" aria-hidden="true">{{ $i + 1 }}</span>
                                    <span class="ls-onb-step-title">{{ $task['title'] }}@if ($kindTag($task))<em class="ls-onb-tag">{{ $kindTag($task) }}</em>@endif</span>
                                    <span class="ls-onb-step-go"><span class="ls-sr">{{ $task['action'] }}</span><x-ui.icon name="chevron-right" /></span>
                                </a>
                            @endif
                        </li>
                    @endforeach
                </ol>
            </div>

            <footer class="ls-onb-foot">
                <span class="ls-faint">{{ $o('autosave') }}</span>
                <form method="POST" action="{{ route('onboarding.welcome.restart') }}">
                    @csrf
                    <button type="submit" class="ls-link ls-onb-replay">{{ $o('replay') }}</button>
                </form>
            </footer>
        </section>
    @else
        {{-- Hidden: one slim, unobtrusive way back. --}}
        <form method="POST" action="{{ route('onboarding.checklist.show') }}" class="ls-onb-reopen">
            @csrf
            <button type="submit" class="ls-onb-reopen-btn">
                <span class="ls-onb-mini" style="--p: {{ $sum['percent'] }}" aria-hidden="true"></span>
                <span>{{ $sum['all_complete'] ? $o('checklist_show') : $o('checklist_show_progress', ['done' => $sum['done'], 'total' => $sum['total']]) }}</span>
            </button>
        </form>
    @endif

    {{-- ================= Welcome Wizard (first visit only) ================= --}}
    @if ($showWelcome)
        @php
            $values = array_values(array_filter([
                $onboardingOwner->hasFeature('workspace') || $onboardingOwner->hasFeature('booking') ? ['door', $o('value.rooms')] : null,
                $onboardingOwner->hasFeature('hotspot') ? ['wifi', $o('value.hotspot')] : null,
                $onboardingOwner->hasFeature('sales') ? ['box', $o('value.sales')] : null,
                ['team', $o('value.team')],
            ]));
            $firstName = \Illuminate\Support\Str::of($onboardingOwner->name)->trim()->explode(' ')->first();
        @endphp
        <x-ui.modal id="welcome-wizard" :title="$o('welcome_title', ['name' => $firstName])" :subtitle="$o('step_of', ['current' => 1, 'total' => 3])" size="wide" class="ls-onb-wizard">
            <div class="ls-onb-bar" aria-hidden="true"><i class="is-on"></i><i></i><i></i></div>

            {{-- Step 1: welcome + what the panel does for THEIR modules --}}
            <div class="ls-onb-pane" data-step="1" data-title="{{ $o('welcome_title', ['name' => $firstName]) }}">
                <p class="ls-onb-lead">{{ $o('welcome_text') }}</p>
                <ul class="ls-onb-values">
                    @foreach ($values as [$icon, $text])
                        <li><span class="ls-onb-value-icon" aria-hidden="true"><x-ui.icon :name="$icon" /></span><span>{{ $text }}</span></li>
                    @endforeach
                </ul>
                <p class="ls-onb-time"><x-ui.icon name="clock" /><span>{{ $o('welcome_time') }}</span></p>
            </div>

            {{-- Step 2: the one detail we ask for (sign-up no longer collects it) --}}
            <form class="ls-onb-pane" data-step="2" data-title="{{ $o('profile_title') }}" hidden method="POST" action="{{ route('onboarding.profile') }}" id="onb-profile-form" novalidate>
                @csrf
                <p class="ls-onb-lead">{{ $o('profile_text') }}</p>
                <div class="ls-field">
                    <label class="ls-label" for="onb-business">{{ $o('business_name') }}</label>
                    <input id="onb-business" name="business_name" class="ls-input ls-onb-input" maxlength="255" required autocomplete="organization"
                           value="{{ $onboardingOwner->business_name }}" placeholder="{{ $o('business_ph') }}" aria-describedby="onb-business-err">
                    <p class="ls-error" id="onb-business-err" hidden></p>
                </div>
                {{-- Live preview of how the dashboard will greet them. --}}
                <div class="ls-onb-preview" aria-live="polite">
                    <span class="ls-onb-preview-label">{{ $o('preview') }}</span>
                    <b id="onb-preview">{{ __('app.dashboard.welcome', ['business' => $onboardingOwner->business_name]) }}</b>
                </div>
            </form>

            {{-- Step 3: the plan — their applicable steps, with real done states --}}
            <div class="ls-onb-pane" data-step="3" data-title="{{ $o('plan_title') }}" hidden>
                <p class="ls-onb-lead">{{ $next ? $o('plan_text') : $o('all_set') }}</p>
                <ol class="ls-onb-plan">
                    @foreach ($onboardingTasks as $i => $task)
                        <li class="{{ $task['done'] ? 'is-done' : '' }}">
                            <span class="ls-onb-plan-icon" aria-hidden="true">
                                @if ($task['done'])<x-ui.icon name="check" />@else<x-ui.icon :name="$icons[$task['key']] ?? 'check-circle'" />@endif
                            </span>
                            <span class="ls-onb-plan-main">
                                <span class="ls-onb-plan-title">{{ $task['title'] }}@if (! $task['done'] && $kindTag($task))<em class="ls-onb-tag">{{ $kindTag($task) }}</em>@endif</span>
                                <span class="ls-onb-plan-text">{{ $task['text'] }}</span>
                            </span>
                        </li>
                    @endforeach
                </ol>
            </div>

            <x-slot:footer>
                {{-- Step 1 --}}
                <button type="button" class="ls-btn ls-btn--ghost" data-onb-show="1" data-onb-dismiss>{{ $o('explore') }}</button>
                {{-- Steps 2–3 --}}
                <button type="button" class="ls-btn ls-btn--ghost" data-onb-show="2" data-onb-go="1" hidden>{{ $o('back') }}</button>
                <button type="button" class="ls-btn ls-btn--ghost" data-onb-show="3" data-onb-go="2" hidden>{{ $o('back') }}</button>
                <div class="ls-push">
                    <button type="button" class="ls-btn ls-btn--primary" data-onb-show="1" data-onb-go="2"><span>{{ $o('start') }}</span><x-ui.icon name="arrow-right" class="ls-arrow" /></button>
                    <button type="button" class="ls-btn ls-btn--secondary" data-onb-show="2" data-onb-go="3" hidden>{{ $o('skip') }}</button>
                    <button type="submit" form="onb-profile-form" class="ls-btn ls-btn--primary" data-onb-show="2" hidden>{{ $o('save_continue') }}</button>
                    <button type="button" class="ls-btn ls-btn--secondary" data-onb-show="3" data-onb-dismiss hidden>{{ $o('go_dashboard') }}</button>
                    @if ($next)
                        <button type="button" class="ls-btn ls-btn--primary" data-onb-show="3" data-onb-dismiss data-onb-next="{{ $next['url'] }}" hidden><span>{{ $o('start_with', ['task' => $next['title']]) }}</span><x-ui.icon name="arrow-right" class="ls-arrow" /></button>
                    @endif
                </div>
            </x-slot:footer>
        </x-ui.modal>

        {{-- No-JS fallback: a plain form that records the dismissal. --}}
        <noscript>
            <form method="POST" action="{{ route('onboarding.welcome.dismiss') }}" class="ls-onb-reopen">@csrf<button class="ls-btn ls-btn--secondary ls-btn--sm">{{ $o('go_dashboard') }}</button></form>
        </noscript>
    @endif

    <script>
    // panel.js (window.LS) is deferred, so start once the DOM is parsed.
    document.addEventListener('DOMContentLoaded', function () {
        const token = document.querySelector('meta[name="csrf-token"]').content;
        const post = (url, body) => fetch(url, {
            method: 'POST', keepalive: true,
            headers: { 'Accept': 'application/json', 'Content-Type': 'application/json', 'X-CSRF-TOKEN': token },
            body: JSON.stringify(body || {}),
        });

        // Hide the checklist without a page jump (form still works without JS).
        document.querySelectorAll('[data-onb-hide]').forEach((hideForm) => hideForm.addEventListener('submit', (e) => {
            e.preventDefault();
            const card = document.getElementById('onboarding-checklist');
            if (card) card.classList.add('is-leaving');
            post(hideForm.action).finally(() => location.reload());
        }));

        const wizard = document.getElementById('welcome-wizard');
        if (!wizard || !window.LS) return;
        const T = @json($onbJs);
        const dismissUrl = @json(route('onboarding.welcome.dismiss'));
        let dismissed = false, nameChanged = false;

        function show(n) {
            wizard.querySelectorAll('[data-step]').forEach((el) => { el.hidden = el.dataset.step !== String(n); });
            wizard.querySelectorAll('[data-onb-show]').forEach((el) => { el.hidden = el.dataset.onbShow !== String(n); });
            wizard.querySelectorAll('.ls-onb-bar i').forEach((d, i) => d.classList.toggle('is-on', i < n));
            const pane = wizard.querySelector(`[data-step="${n}"]`);
            document.getElementById('welcome-wizard-title').textContent = pane.dataset.title;
            document.getElementById('welcome-wizard-sub').textContent = T.stepOf.replace(':current', n).replace(':total', 3);
            const focus = pane.querySelector('input') || wizard.querySelector(`[data-onb-show="${n}"].ls-btn--primary`);
            if (focus) setTimeout(() => focus.focus(), 30);
        }

        // Record "welcomed" exactly once, however the wizard is left.
        function dismiss(next) {
            if (dismissed) return Promise.resolve();
            dismissed = true;
            return post(dismissUrl, next ? { next } : {}).then((r) => r.json()).catch(() => ({ next: next || null }));
        }

        wizard.querySelectorAll('[data-onb-go]').forEach((b) => b.addEventListener('click', () => show(Number(b.dataset.onbGo))));
        wizard.querySelectorAll('[data-onb-dismiss]').forEach((b) => b.addEventListener('click', () => {
            b.disabled = true;
            dismiss(b.dataset.onbNext).then((res) => {
                if (b.dataset.onbNext) window.location.href = (res && res.next) || b.dataset.onbNext;
                else { LS.close('welcome-wizard'); if (nameChanged) location.reload(); }
            });
        }));
        // Esc, the ✕ or a click outside also count as "closed".
        wizard.addEventListener('ls:close', () => { dismiss().then(() => { if (nameChanged) location.reload(); }); });

        // Step 2: live greeting preview + save the space name (server-validated).
        const form = document.getElementById('onb-profile-form');
        const err = document.getElementById('onb-business-err');
        const input = document.getElementById('onb-business');
        const preview = document.getElementById('onb-preview');
        input.addEventListener('input', () => { preview.textContent = T.greeting.replace(':name', input.value.trim() || '…'); });
        form.addEventListener('submit', (e) => {
            e.preventDefault();
            err.hidden = true; input.classList.remove('is-invalid');
            const btn = wizard.querySelector('[form="onb-profile-form"]');
            LS.busy(btn, true);
            fetch(form.action, {
                method: 'POST',
                headers: { 'Accept': 'application/json', 'Content-Type': 'application/json', 'X-CSRF-TOKEN': token },
                body: JSON.stringify({ business_name: input.value }),
            }).then((r) => r.json().then((b) => ({ ok: r.ok, b })))
              .then(({ ok, b }) => {
                  if (!ok) {
                      err.textContent = (b.errors && b.errors.business_name && b.errors.business_name[0]) || T.saveFailed;
                      err.hidden = false; input.classList.add('is-invalid'); input.focus();
                      return;
                  }
                  nameChanged = true;
                  show(3);
              })
              .catch(() => { err.textContent = T.saveFailed; err.hidden = false; })
              .finally(() => LS.busy(btn, false));
        });

        show(1);
        LS.open('welcome-wizard');
    });
    </script>
@endif
