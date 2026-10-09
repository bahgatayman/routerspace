<?php

namespace App\Http\Controllers;

use App\Support\TenantContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;

class ProfileController extends Controller
{
    public function index(): Response
    {
        $owner = TenantContext::user()->load('plan');
        $usageCount = $owner->hotspotUsers()->count();

        $status = $owner->subscriptionStatus();
        $pct = $owner->plan ? ($usageCount / $owner->plan->max_members) * 100 : 0;

        // Explicit fields only — never the MikroTik password or other credentials.
        return Inertia::render('Profile/Index', [
            'owner' => [
                'name' => $owner->name,
                'email' => $owner->email,
                'business_name' => $owner->business_name,
                'logo_url' => $owner->logoUrl(),
                'initials' => $owner->initials(),
                'has_hotspot' => $owner->hasFeature('hotspot'),
                'mikrotik_host' => $owner->mikrotik_host,
                'mikrotik_port' => $owner->mikrotik_port,
                'mikrotik_username' => $owner->mikrotik_username,
            ],
            'plan' => [
                'name' => $owner->plan->name ?? __('app.profile.no_plan'),
                'price' => $owner->plan?->formattedPrice(),
                'max_members' => $owner->plan?->max_members ?? 0,
            ],
            'usageCount' => $usageCount,
            'usagePct' => min(100, $pct),
            'subscription' => [
                'status' => $status,
                'label' => ucfirst(str_replace('_', ' ', $status)),
                'expires' => $owner->subscription_expires_at?->format('Y-m-d'),
                'days_remaining' => $owner->subscription_expires_at ? $owner->daysUntilExpiry() : null,
            ],
        ]);
    }

    /**
     * Upload (or replace) the owner's brand image.
     *
     * Stored on the `public` disk under owner-logos/. The previous file is
     * removed only after the new path is saved, so a failed write never leaves
     * the owner with a broken image.
     */
    public function updateLogo(Request $request): RedirectResponse
    {
        $request->validate([
            'logo' => ['required', 'image', 'mimes:jpeg,jpg,png,webp', 'max:2048'],
        ]);

        $owner = TenantContext::user();
        $previous = $owner->logo_path;

        $path = $request->file('logo')->store('owner-logos', 'public');

        $owner->update(['logo_path' => $path]);

        if ($previous && $previous !== $path) {
            Storage::disk('public')->delete($previous);
        }

        return back()->with('success', __('app.profile.logo_updated'));
    }

    public function destroyLogo(): RedirectResponse
    {
        $owner = TenantContext::user();

        if ($owner->logo_path) {
            Storage::disk('public')->delete($owner->logo_path);
            $owner->update(['logo_path' => null]);
        }

        return back()->with('success', __('app.profile.logo_removed'));
    }
}
