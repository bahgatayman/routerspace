<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\Owner;
use App\Models\Plan;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;
use Symfony\Component\HttpFoundation\Response;

class RegisterController extends Controller
{
    public function showRegister(): InertiaResponse
    {
        return Inertia::render('Auth/Register');
    }

    public function register(Request $request): Response
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'unique:owners,email'],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
            'business_name' => ['required', 'string', 'max:255'],
            'mikrotik_host' => ['nullable', 'string'],
            'mikrotik_port' => ['nullable', 'integer', 'min:1', 'max:65535'],
            'mikrotik_username' => ['nullable', 'string'],
            'mikrotik_password' => ['nullable', 'string'],
        ]);

        $owner = Owner::create([
            'name' => $validated['name'],
            'email' => $validated['email'],
            'password' => Hash::make($validated['password']),
            'business_name' => $validated['business_name'],
            'mikrotik_host' => $validated['mikrotik_host'] ?? null,
            'mikrotik_port' => $validated['mikrotik_port'] ?? 8728,
            'mikrotik_username' => $validated['mikrotik_username'] ?? null,
            'mikrotik_password' => $validated['mikrotik_password'] ?? null,
        ]);

        // Start every new owner on the default (free) plan with a 2-week trial so
        // they land in the panel instead of the subscription-expired wall.
        if ($plan = Plan::defaultForSignup()) {
            $owner->update([
                'plan_id' => $plan->id,
                'subscription_starts_at' => now(),
                'subscription_expires_at' => now()->addWeeks(2),
                'is_active' => true,
            ]);
            $owner->applyPlanFeatures();
        }

        Auth::guard('owner')->login($owner);

        // The session changes on sign-in: leave with a full page load (see LoginController::enter).
        return $request->header('X-Inertia') ? Inertia::location(url('/dashboard')) : redirect('/dashboard');
    }
}
