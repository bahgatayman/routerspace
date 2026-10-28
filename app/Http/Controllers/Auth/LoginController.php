<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\View\View;

class LoginController extends Controller
{
    /** Failed sign-ins allowed per email + IP, and per IP alone, within DECAY_SECONDS. */
    public const MAX_ATTEMPTS_PER_EMAIL = 5;

    public const MAX_ATTEMPTS_PER_IP = 30;

    public const DECAY_SECONDS = 60;

    public function showLogin(): View
    {
        return view('auth.login');
    }

    public function login(Request $request): RedirectResponse
    {
        $credentials = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required'],
        ]);

        $remember = $request->boolean('remember');

        // Brute-force guard, counted on failed credentials only. Keyed by email + IP
        // (one person retrying) and, more loosely, by IP alone (one source trying
        // many emails) — never a blunt per-IP route throttle, since a whole café
        // can share one public IP. Checked before any guard is tried.
        $emailKey = 'login:'.Str::lower(trim((string) $credentials['email'])).'|'.$request->ip();
        $ipKey = 'login-ip:'.$request->ip();
        if (RateLimiter::tooManyAttempts($emailKey, self::MAX_ATTEMPTS_PER_EMAIL) || RateLimiter::tooManyAttempts($ipKey, self::MAX_ATTEMPTS_PER_IP)) {
            $seconds = max(RateLimiter::availableIn($emailKey), RateLimiter::availableIn($ipKey));

            return back()->withErrors([
                'email' => __('app.auth.throttle', ['seconds' => max(1, $seconds)]),
            ])->onlyInput('email');
        }

        // Single sign-in form for every account type. Accounts stay in separate
        // tables/guards; we try the platform operator first, then the tenant, and
        // route each to their own dashboard.
        if (Auth::guard('admin')->attempt($credentials, $remember)) {
            RateLimiter::clear($emailKey);
            $request->session()->regenerate();

            return redirect()->intended('/admin/dashboard');
        }

        if (Auth::guard('owner')->attempt($credentials, $remember)) {
            RateLimiter::clear($emailKey);
            $request->session()->regenerate();

            return redirect()->intended('/dashboard');
        }

        if (Auth::guard('staff')->attempt($credentials, $remember)) {
            // Correct password: not a guessing failure, even for a disabled account.
            RateLimiter::clear($emailKey);
            $staff = Auth::guard('staff')->user();

            if (! $staff->is_active || ! $staff->owner->is_active) {
                Auth::guard('staff')->logout();

                return back()->withErrors([
                    'email' => 'Your account has been disabled. Contact your manager.',
                ])->onlyInput('email');
            }

            $request->session()->regenerate();
            $staff->forceFill(['last_login_at' => now()])->save();

            return redirect()->intended('/dashboard');
        }

        RateLimiter::hit($emailKey, self::DECAY_SECONDS);
        RateLimiter::hit($ipKey, self::DECAY_SECONDS);

        return back()->withErrors([
            'email' => 'The provided credentials do not match our records.',
        ])->onlyInput('email');
    }

    public function logout(Request $request): RedirectResponse
    {
        // Log out whichever guard this session belongs to.
        Auth::guard('owner')->logout();
        Auth::guard('admin')->logout();
        Auth::guard('staff')->logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect('/login');
    }
}
