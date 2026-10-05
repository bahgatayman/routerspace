<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Owner;
use App\Models\Plan;
use App\Services\AdminAuditLogger;
use App\Services\SubscriptionRenewalService;
use Carbon\Carbon;
use Illuminate\Http\Request;

class SubscriptionController extends Controller
{
    public function renew(Request $request, $ownerId, SubscriptionRenewalService $renewals)
    {
        $validated = $request->validate([
            'plan_id' => 'required|exists:plans,id',
            'months' => 'nullable|integer|min:1|max:24',
            'expires_at' => 'nullable|date|after:today',
            'notes' => 'nullable|string|max:500',
        ]);

        if (! $request->filled('months') && ! $request->filled('expires_at')) {
            return back()->withInput()->with('error', 'Please provide either months or a custom expiry date.');
        }

        $owner = Owner::findOrFail($ownerId);
        $plan = Plan::findOrFail($validated['plan_id']);
        $before = ['plan_id' => $owner->plan_id, 'expires_at' => $owner->subscription_expires_at?->toDateString()];

        $subscription = $renewals->renew(
            owner: $owner,
            plan: $plan,
            months: $validated['months'] ?? null,
            admin: auth('admin')->user(),
            notes: $validated['notes'] ?? null,
            until: $request->filled('expires_at') ? Carbon::parse($validated['expires_at']) : null,
        );

        $msg = "Subscription renewed: {$plan->name} plan — expires {$subscription->expires_at->format('Y-m-d')}. ";
        $msg .= $plan->isFree() ? 'Free plan — no charge.' : 'Amount: ج.م '.number_format($subscription->amount_paid, 2);

        app(AdminAuditLogger::class)->log('subscription.renewed', $owner,
            "Renewed on {$plan->name} until {$subscription->expires_at->format('Y-m-d')} (".number_format((float) $subscription->amount_paid, 2).')',
            ['before' => $before, 'plan_id' => $plan->id, 'subscription_id' => $subscription->id, 'amount' => (float) $subscription->amount_paid],
            $validated['notes'] ?? null);

        return redirect("/admin/owners/{$owner->id}/subscription")->with('success', $msg);
    }
}
