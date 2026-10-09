<?php

namespace App\Http\Controllers;

use App\Models\Plan;
use App\Models\SubscriptionRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Lang;
use Inertia\Inertia;
use Inertia\Response;

class SubscriptionController extends Controller
{
    /**
     * Shown by CheckSubscription when the owner's subscription lapsed. Lists the
     * plans so they can ask to renew instead of hitting a dead end.
     */
    public function expired(): Response|RedirectResponse
    {
        if (auth('owner')->user()->isSubscriptionActive()) {
            return redirect('/dashboard');
        }

        return Inertia::render('Subscription/Expired', $this->planPickerData());
    }

    /**
     * Same plan picker for owners whose subscription is still running — renew
     * early or move to a different plan.
     */
    public function plans(): Response
    {
        return Inertia::render('Subscription/Plans', $this->planPickerData());
    }

    public function requestRenewal(Request $request): RedirectResponse
    {
        $owner = auth('owner')->user();

        $validated = $request->validate([
            'plan_id' => 'required|exists:plans,id',
            'months' => 'required|integer|min:1|max:24',
            'note' => 'nullable|string|max:500',
        ]);

        $plan = Plan::where('id', $validated['plan_id'])
            ->where('is_active', true)
            ->firstOrFail();

        // Free plan is self-serve: no payment, so no admin approval and no paid
        // Subscription record — just activate it and drop the owner into the panel.
        // Renewing early stacks on any remaining time.
        if ($plan->isFree()) {
            $startsFrom = ($owner->subscription_expires_at && $owner->subscription_expires_at->isFuture())
                ? $owner->subscription_expires_at
                : now();

            $owner->update([
                'plan_id' => $plan->id,
                'subscription_starts_at' => $owner->subscription_starts_at ?? now(),
                'subscription_expires_at' => $startsFrom->copy()->addMonths((int) $validated['months']),
                'is_active' => true,
            ]);
            $owner->applyPlanFeatures();

            return redirect('/dashboard')->with('success', __('app.subscription.free_activated'));
        }

        // Paid plans go through admin approval — one open request at a time, else an
        // impatient owner queues several up and an admin approves the same one twice.
        if ($this->pendingRequest()) {
            return back()->with('error', __('app.subscription.request_already_pending'));
        }

        SubscriptionRequest::create([
            'owner_id' => $owner->id,
            'plan_id' => $plan->id,
            'months' => $validated['months'],
            'amount' => $plan->price_per_month * $validated['months'],
            'status' => SubscriptionRequest::STATUS_PENDING,
            'note' => $validated['note'] ?? null,
        ]);

        return back()->with('success', __('app.subscription.request_sent'));
    }

    public function cancelRequest(int $id): RedirectResponse
    {
        $pending = SubscriptionRequest::where('id', $id)
            ->where('owner_id', auth('owner')->id())
            ->pending()
            ->firstOrFail();

        $pending->update(['status' => SubscriptionRequest::STATUS_CANCELLED]);

        return back()->with('success', __('app.subscription.request_cancelled'));
    }

    /** Shared payload for both plan screens — explicit arrays, every amount formatted here. */
    private function planPickerData(): array
    {
        $owner = auth('owner')->user()->load('plan');
        $status = $owner->subscriptionStatus();
        $usedSlots = $owner->hotspotUsers()->count();
        $maxSlots = $owner->plan?->max_members ?? 0;
        $egp = fn ($amount) => 'ج.م '.number_format((float) $amount, 2);

        $plans = Plan::where('is_active', true)
            ->orderBy('sort_order')
            ->orderBy('price_per_month')
            ->get();
        $pending = $this->pendingRequest();
        $recent = SubscriptionRequest::where('owner_id', $owner->id)
            ->with('plan')
            ->latest()
            ->take(5)
            ->get();

        return [
            'owner' => [
                'business_name' => $owner->business_name,
                'plan_id' => $owner->plan_id,
                'plan_name' => $owner->plan?->name,
                'status' => $status,
                'days_left' => $owner->daysUntilExpiry(),
                'expires_at' => $owner->subscription_expires_at?->format('d M Y'),
                'used_slots' => $usedSlots,
                'max_slots' => $maxSlots,
                'usage' => $maxSlots > 0 ? min(100, ($usedSlots / $maxSlots) * 100) : 0,
            ],
            'monthOptions' => [1, 3, 6, 12],
            'plans' => $plans->map(fn (Plan $plan) => [
                'id' => $plan->id,
                'name' => $plan->name,
                'is_free' => $plan->isFree(),
                'price_whole' => number_format((float) $plan->price_per_month, 0),
                'price' => $egp($plan->price_per_month),
                // Billed total for each selectable duration (the old picker script multiplied in the browser).
                'totals' => collect([1, 3, 6, 12])->mapWithKeys(fn ($m) => [$m => $egp($plan->price_per_month * $m)])->all(),
                // 0 / null on a plan limit means unlimited (see Owner::canAddMore*).
                'limits' => collect([
                    ['label' => __('app.common.members'), 'value' => $plan->max_members, 'unlimited' => false],
                    ['label' => __('app.nav.workspaces'), 'value' => $plan->max_workspaces, 'unlimited' => true],
                    ['label' => __('app.plan.max_rooms'), 'value' => $plan->max_rooms, 'unlimited' => true],
                    ['label' => __('app.plan.max_products'), 'value' => $plan->max_products, 'unlimited' => true],
                ])->reject(fn ($l) => $l['value'] === null && ! $l['unlimited'])
                    ->map(fn ($l) => [
                        'label' => $l['label'],
                        'value' => $l['unlimited'] && ! $l['value'] ? __('app.subscription.unlimited') : (string) $l['value'],
                    ])->values()->all(),
                // Unknown keys fall back to the raw name rather than printing "app.feature.x".
                'features' => collect($plan->defaultFeatures())
                    ->map(fn ($f) => Lang::has('app.feature.'.$f) ? __('app.feature.'.$f) : ucfirst($f))->values()->all(),
            ])->values(),
            'pendingRequest' => $pending ? [
                'id' => $pending->id,
                'plan_name' => $pending->plan?->name,
                'months' => $pending->months,
                'amount' => $egp($pending->amount),
                'requested_on' => $pending->created_at->format('d M Y, H:i'),
            ] : null,
            'recentRequests' => $recent->map(fn (SubscriptionRequest $req) => [
                'id' => $req->id,
                'plan_name' => $req->plan?->name,
                'months' => $req->months,
                'date' => $req->created_at->format('d M Y'),
                'admin_note' => $req->admin_note,
                'amount' => $egp($req->amount),
                'status' => $req->status,
                'status_label' => __('app.subscription.status_'.$req->status),
                'color' => $req->statusColor(),
            ])->values(),
        ];
    }

    private function pendingRequest(): ?SubscriptionRequest
    {
        return SubscriptionRequest::where('owner_id', auth('owner')->id())
            ->with('plan')
            ->pending()
            ->latest()
            ->first();
    }
}
