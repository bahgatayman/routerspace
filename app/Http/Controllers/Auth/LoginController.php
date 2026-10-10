<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;
use Symfony\Component\HttpFoundation\Response;

class LoginController extends Controller
{
    public function showLogin(): InertiaResponse
    {
        return Inertia::render('Auth/Login');
    }

    public function login(Request $request): Response
    {
        $credentials = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required'],
        ]);

        $remember = $request->boolean('remember');

        // Single sign-in form for every account type. Accounts stay in separate
        // tables/guards; we try the platform operator first, then the tenant, and
        // route each to their own dashboard.
        if (Auth::guard('admin')->attempt($credentials, $remember)) {
            $request->session()->regenerate();

            return $this->enter($request, redirect()->intended('/admin/dashboard'));
        }

        if (Auth::guard('owner')->attempt($credentials, $remember)) {
            $request->session()->regenerate();

            return $this->enter($request, redirect()->intended('/dashboard'));
        }

        if (Auth::guard('staff')->attempt($credentials, $remember)) {
            $staff = Auth::guard('staff')->user();

            if (! $staff->is_active || ! $staff->owner->is_active) {
                Auth::guard('staff')->logout();

                return back()->withErrors([
                    'email' => 'Your account has been disabled. Contact your manager.',
                ])->onlyInput('email');
            }

            $request->session()->regenerate();
            $staff->forceFill(['last_login_at' => now()])->save();

            return $this->enter($request, redirect()->intended('/dashboard'));
        }

        return back()->withErrors([
            'email' => 'The provided credentials do not match our records.',
        ])->onlyInput('email');
    }

    /**
     * Signing in regenerates the session (and CSRF token), so leave with a
     * full page load: a client-side visit would keep the old token in the
     * document and break the plain logout / language forms.
     */
    private function enter(Request $request, RedirectResponse $redirect): Response
    {
        return $request->header('X-Inertia') ? Inertia::location($redirect->getTargetUrl()) : $redirect;
    }

    public function logout(Request $request): RedirectResponse
    {
        // Log out whichever guard this session belongs to.
        Auth::guard('owner')->logout();
        Auth::guard('admin')->logout();
        Auth::guard('staff')->logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        // Drop (encrypted) page data kept in browser history, so Back can't redisplay it.
        Inertia::clearHistory();

        return redirect('/login');
    }
}
