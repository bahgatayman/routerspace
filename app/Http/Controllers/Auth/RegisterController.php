<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\Owner;
use App\Models\Plan;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\View\View;

class RegisterController extends Controller
{
    public function showRegister(): View
    {
        return view('auth.register');
    }

    public function register(Request $request): RedirectResponse
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

        // Start every new owner on the default (free) plan with a full one-month
        // trial from their actual registration moment, so they land in the panel
        // instead of the subscription-expired wall. addMonthNoOverflow() (not
        // addMonth()) is required here: this app's Carbon config overflows short
        // months by default (Jan 31 + 1 month would land on Mar 3, not Feb 28).
        if ($plan = Plan::defaultForSignup()) {
            $owner->update([
                'plan_id' => $plan->id,
                'subscription_starts_at' => now(),
                'subscription_expires_at' => now()->addMonthNoOverflow(),
                'is_active' => true,
            ]);
            $owner->applyPlanFeatures();
        }

        Auth::guard('owner')->login($owner);

        return redirect('/dashboard');
    }
}
