<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\SubscriptionRequest;
use App\Services\AdminAuditLogger;
use App\Services\NotificationService;
use App\Services\SubscriptionRenewalService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Admin queue for owner-initiated renewal requests. Payment is settled out of
 * band; approving here is what actually extends the subscription.
 */
class SubscriptionRequestController extends Controller
{
    public function index(): View
    {
        return view('admin.subscription-requests.index', [
            'pending' => SubscriptionRequest::with(['owner', 'plan'])->pending()->oldest()->get(),
            'handled' => SubscriptionRequest::with(['owner', 'plan', 'admin'])
                ->where('status', '!=', SubscriptionRequest::STATUS_PENDING)
                ->latest('handled_at')
                ->take(20)
                ->get(),
        ]);
    }

    public function approve(int $id, SubscriptionRenewalService $renewals, NotificationService $notifications): RedirectResponse
    {
        $req = SubscriptionRequest::with(['owner', 'plan'])->pending()->findOrFail($id);

        $subscription = $renewals->renew(
            owner: $req->owner,
            plan: $req->plan,
            months: $req->months,
            admin: auth('admin')->user(),
            notes: "Approved renewal request #{$req->id}".($req->note ? " — {$req->note}" : ''),
        );

        $req->update([
            'status' => SubscriptionRequest::STATUS_APPROVED,
            'admin_id' => auth('admin')->id(),
            'handled_at' => now(),
        ]);

        app(AdminAuditLogger::class)->log('subscription_request.approved', $req->owner,
            "Approved renewal request #{$req->id}: {$req->plan->name} × {$req->months} month(s), now expires {$subscription->expires_at->format('Y-m-d')}",
            ['request_id' => $req->id, 'subscription_id' => $subscription->id, 'amount' => (float) $subscription->amount_paid]);

        return back()->with('success', "Approved: {$req->owner->business_name} — {$req->plan->name}, expires {$subscription->expires_at->format('Y-m-d')}.");
    }

    public function reject(Request $request, int $id, NotificationService $notifications): RedirectResponse
    {
        $validated = $request->validate([
            'admin_note' => 'nullable|string|max:500',
        ]);

        $req = SubscriptionRequest::with(['owner', 'plan'])->pending()->findOrFail($id);

        $req->update([
            'status' => SubscriptionRequest::STATUS_REJECTED,
            'admin_note' => $validated['admin_note'] ?? null,
            'admin_id' => auth('admin')->id(),
            'handled_at' => now(),
        ]);

        // The owner is locked out of the panel while expired, so tell them in-app
        // rather than leaving the request silently stuck.
        $notifications->notify($req->owner, [
            'type' => 'subscription_request_rejected',
            'level' => 'warning',
            'reference' => "subscription_request_rejected:{$req->id}",
            'title' => __('app.subscription.request_rejected_title'),
            'body' => $req->admin_note ?: __('app.subscription.request_rejected_body'),
            'action_url' => '/subscription/plans',
        ]);

        app(AdminAuditLogger::class)->log('subscription_request.rejected', $req->owner, "Rejected renewal request #{$req->id}",
            ['request_id' => $req->id], $req->admin_note);

        return back()->with('success', "Rejected request from {$req->owner->business_name}.");
    }
}
