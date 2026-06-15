<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Owner;
use App\Models\Subscription;
use Illuminate\Http\Request;

class SubscriptionController extends Controller
{
    public function renew(Request $request, $ownerId)
    {
        $validated = $request->validate([
            'months' => 'required|integer|min:1|max:24',
            'notes'  => 'nullable|string|max:500',
        ]);

        $owner = Owner::findOrFail($ownerId);

        $startsFrom = ($owner->subscription_expires_at && $owner->subscription_expires_at->isFuture())
            ? $owner->subscription_expires_at
            : now();

        $newExpiry = $startsFrom->copy()->addMonths((int) $validated['months']);

        $owner->update([
            'subscription_starts_at'  => $owner->subscription_starts_at ?? now(),
            'subscription_expires_at' => $newExpiry,
            'is_active'               => true,
        ]);

        Subscription::create([
            'owner_id'   => $owner->id,
            'admin_id'   => auth('admin')->id(),
            'months'     => (int) $validated['months'],
            'starts_at'  => $startsFrom,
            'expires_at' => $newExpiry,
            'notes'      => $validated['notes'] ?? null,
        ]);

        return redirect("/admin/owners/{$owner->id}")
            ->with('success', 'Subscription renewed until ' . $newExpiry->format('Y-m-d') . '.');
    }
}
