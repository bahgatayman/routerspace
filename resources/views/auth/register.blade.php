@extends('layouts.auth')

@section('title', __('app.auth.register'))
@section('heading', __('app.auth.create_your_account'))
@section('subheading', ($trialAvailable ?? false) ? __('app.auth.signup.trial') : __('app.auth.signup.subtitle'))

@php
    $s = fn (string $key, array $r = []) => __('app.auth.signup.'.$key, $r);
    $fields = ['name', 'email', 'password'];
    // Errors that don't belong to a visible field still get a banner.
    $otherErrors = collect($errors->getMessages())->except($fields)->flatten();
    $focus = collect($fields)->first(fn ($f) => $errors->has($f)) ?? 'name';
    $emailTaken = $errors->first('email') === __('app.auth.signup.email_taken');
    $jsText = ['creating' => $s('creating')];
    $alert = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>';
@endphp

@section('content')
    @if ($otherErrors->isNotEmpty())
        <div class="mb-5 rounded-xl bg-red-50 border border-red-100 px-4 py-3 space-y-1" role="alert">
            @foreach ($otherErrors as $error)
                <p class="field-msg" style="margin: 0">{!! $alert !!}<span>{{ $error }}</span></p>
            @endforeach
        </div>
    @endif

    <form method="POST" action="/register" class="space-y-5" id="signup-form">
        @csrf

        <div>
            <label for="name" class="lbl">{{ __('app.auth.name') }}</label>
            <input type="text" name="name" id="name" value="{{ old('name') }}" required maxlength="255" autocomplete="name"
                   placeholder="{{ $s('name_ph') }}" @if ($focus === 'name') autofocus @endif
                   class="field {{ $errors->has('name') ? 'is-invalid' : '' }}"
                   @error('name') aria-invalid="true" aria-describedby="name-msg" @enderror>
            @error('name')<p class="field-msg" id="name-msg">{!! $alert !!}<span>{{ $message }}</span></p>@enderror
        </div>

        <div>
            <label for="email" class="lbl">{{ __('app.auth.email') }}</label>
            <input type="email" name="email" id="email" value="{{ old('email') }}" required autocomplete="email" inputmode="email" dir="ltr"
                   placeholder="you@example.com" @if ($focus === 'email') autofocus @endif
                   class="field {{ $errors->has('email') ? 'is-invalid' : '' }}"
                   @error('email') aria-invalid="true" aria-describedby="email-msg" @enderror>
            @error('email')
                <p class="field-msg" id="email-msg">{!! $alert !!}<span>{{ $message }}
                    @if ($emailTaken)<a href="/login" class="font-semibold underline">{{ $s('sign_in_instead') }}</a>@endif
                </span></p>
            @enderror
        </div>

        <div>
            <label for="password" class="lbl">{{ __('app.auth.password') }}</label>
            <div class="relative">
                <input type="password" name="password" id="password" required minlength="8" autocomplete="new-password"
                       @if ($focus === 'password') autofocus @endif
                       class="field pe-10 {{ $errors->has('password') ? 'is-invalid' : '' }}" aria-describedby="password-msg">
                <button type="button" class="pw-toggle absolute inset-y-0 end-0 flex items-center pe-3 text-surface-400 hover:text-surface-600"
                        aria-label="{{ $s('show_password') }}" aria-pressed="false"
                        data-label-show="{{ $s('show_password') }}" data-label-hide="{{ $s('hide_password') }}">
                    <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.7" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.7" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"/></svg>
                    <svg class="w-4 h-4 hidden" fill="none" viewBox="0 0 24 24" stroke="currentColor" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.7" d="M13.875 18.825A10.05 10.05 0 0112 19c-4.478 0-8.268-2.943-9.543-7a9.97 9.97 0 011.563-3.029m5.858.908a3 3 0 114.243 4.243M9.878 9.878l4.242 4.242M9.88 9.88l-3.29-3.29m7.532 7.532l3.29 3.29M3 3l18 18"/></svg>
                </button>
            </div>
            @error('password')
                <p class="field-msg" id="password-msg">{!! $alert !!}<span>{{ $message }}</span></p>
            @else
                <p class="field-hint" id="password-msg">{{ $s('password_rule') }}</p>
            @enderror
        </div>

        <button type="submit" class="btn-primary" id="signup-submit">
            <span>{{ __('app.auth.create_account') }}</span>
            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M17 8l4 4m0 0l-4 4m4-4H3"/></svg>
        </button>
    </form>

    <p class="text-center text-sm text-surface-500 mt-7">
        {{ __('app.auth.already_have_account') }}
        <a href="/login" class="text-indigo-600 font-semibold hover:text-indigo-700">{{ __('app.auth.login') }}</a>
    </p>

    <script>
    (function () {
        const form = document.getElementById('signup-form');
        const T = @json($jsText);

        // Clear a field's server error styling as soon as it's edited.
        form.querySelectorAll('.field.is-invalid').forEach((el) => el.addEventListener('input', () => {
            el.classList.remove('is-invalid');
            el.removeAttribute('aria-invalid');
        }, { once: true }));

        // One submit only: show progress and lock the button (native validation runs first).
        form.addEventListener('submit', (e) => {
            const btn = document.getElementById('signup-submit');
            if (btn.disabled) { e.preventDefault(); return; }
            btn.disabled = true;
            btn.innerHTML = '<span class="btn-spin" aria-hidden="true"></span><span>' + T.creating + '</span>';
        });
    })();
    </script>
@endsection
