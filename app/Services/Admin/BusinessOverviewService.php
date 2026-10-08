<?php

namespace App\Services\Admin;

use App\Models\AdminAuditLog;
use App\Models\Booking;
use App\Models\HotspotUser;
use App\Models\Owner;
use App\Models\Product;
use App\Models\Room;
use App\Models\SharedSession;
use App\Models\StaffActivityLog;
use App\Models\SubscriptionRequest;
use App\Models\Workspace;
use App\Services\AnalyticsPeriod;
use App\Services\ExpenseAnalyticsService;
use App\Services\RevenueAnalyticsService;
use App\Support\ActiveSessionsQuery;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Super Admin → one business at a glance. Every figure is scoped to this
 * Owner (the tenant) and comes from the services the owner app itself uses
 * (RevenueAnalyticsService, ExpenseAnalyticsService, ActiveSessionsQuery) —
 * nothing is re-derived for admin. Counts are aggregate queries, never
 * collections, so the page stays fast for large businesses.
 *
 * $workspace (a location of this owner, already scoped by the caller)
 * narrows the location-bound figures: rooms, bookings, active sessions,
 * booking revenue. Members, products, staff, expenses etc. belong to the
 * business and are always business-wide.
 */
class BusinessOverviewService
{
    public function __construct(
        private RevenueAnalyticsService $revenue,
        private ExpenseAnalyticsService $expenses,
    ) {}

    /** @return array<string, mixed> */
    public function summary(Owner $owner, ?Workspace $workspace = null): array
    {
        $wsId = $workspace?->id;
        $today = AnalyticsPeriod::today();
        $month = AnalyticsPeriod::thisMonth();
        $inLocation = fn ($q) => $wsId ? $q->whereHas('room', fn ($r) => $r->where('workspace_id', $wsId)) : $q;

        $sessions = ActiveSessionsQuery::build($owner->id);
        if ($wsId) {
            $sessions = $sessions->filter(fn ($row) => (int) $row->room->workspace_id === $wsId);
        }

        return [
            'locations' => $owner->workspaces()->count(),
            'rooms' => Room::where('owner_id', $owner->id)->when($wsId, fn ($q) => $q->where('workspace_id', $wsId))->count(),
            'members' => HotspotUser::where('owner_id', $owner->id)->count(),
            'staff' => $owner->staff()->count(),
            'active_sessions' => $sessions->count(),
            'bookings_today' => $inLocation(Booking::where('owner_id', $owner->id)
                ->whereDate('booking_date', today())
                ->whereNotIn('status', ['cancelled']))->count(),
            'revenue_today' => $this->revenue->bookingRevenue($owner, $today, $wsId) + ($wsId ? 0 : $this->revenue->saleRevenue($owner, $today)),
            'revenue_month' => $this->revenue->bookingRevenue($owner, $month, $wsId) + ($wsId ? 0 : $this->revenue->saleRevenue($owner, $month)),
            'expenses_month' => $this->expenses->totalExpenses($owner, $month),
            'outstanding' => $this->outstanding($owner, $wsId),
        ];
    }

    /**
     * Money still owed on bookings: net room charge (after coupon) minus what
     * was paid, for bookings that are still due (not cancelled / no-show /
     * covered by an hour package) — Booking::balanceDue() in one query.
     */
    public function outstanding(Owner $owner, ?int $workspaceId = null): float
    {
        return (float) Booking::where('owner_id', $owner->id)
            ->outstanding()
            ->when($workspaceId, fn ($q, $id) => $q->whereHas('room', fn ($r) => $r->where('workspace_id', $id)))
            ->sum(DB::raw(Booking::OUTSTANDING_SQL));
    }

    /**
     * Things the Super Admin should act on, most severe first. Each item has a
     * level (danger|warning|info), a message and, where possible, a link.
     *
     * @return array<int, array{level: string, key: string, text: string, url: ?string}>
     */
    public function health(Owner $owner): array
    {
        $items = [];
        $add = function (string $level, string $key, array $replace = [], ?string $url = null) use (&$items) {
            $items[] = ['level' => $level, 'key' => $key, 'text' => __('app.admin_biz.health.'.$key, $replace), 'url' => $url];
        };
        $sub = "/admin/owners/{$owner->id}/subscription";

        match ($owner->subscriptionStatus()) {
            'expired' => $add('danger', 'sub_expired', ['date' => $owner->subscription_expires_at?->format('M j, Y')], $sub),
            'expiring_soon' => $add('warning', 'sub_expiring', ['days' => max(0, $owner->daysUntilExpiry())], $sub),
            'disabled' => $add('danger', 'suspended', [], $sub),
            'never' => $add('warning', 'no_subscription', [], $sub),
            default => null,
        };

        if (SubscriptionRequest::where('owner_id', $owner->id)->where('status', 'pending')->exists()) {
            $add('info', 'renewal_request', [], '/admin/subscription-requests');
        }

        $rooms = Room::where('owner_id', $owner->id)->count();
        if (! $owner->workspaces()->exists()) {
            $add('warning', 'no_locations');
        } elseif ($rooms === 0) {
            $add('warning', 'no_rooms');
        }

        $unpriced = Room::where('owner_id', $owner->id)
            ->where(fn ($q) => $q->whereNull('pricing_model')->orWhere('pricing_model', 'hourly'))
            ->where(fn ($q) => $q->whereNull('price_per_hour')->orWhere('price_per_hour', '<=', 0))
            ->count();
        if ($unpriced) {
            $add('warning', 'rooms_unpriced', ['count' => $unpriced]);
        }

        if ($owner->hasFeature('hotspot') && ! $owner->hasRouterConfigured()) {
            $add('warning', 'router_missing');
        }

        $max = (int) ($owner->plan?->max_members ?? 0);
        $members = HotspotUser::where('owner_id', $owner->id)->count();
        if ($max > 0 && $members > $max) {
            $add('warning', 'over_member_limit', ['count' => $members, 'max' => $max]);
        }

        $tracked = Product::where('owner_id', $owner->id)->where('is_active', true)->where('track_stock', true)->where('type', '!=', 'service');
        $out = (clone $tracked)->where('stock_quantity', '<=', 0)->count();
        $low = Product::where('owner_id', $owner->id)->where('is_active', true)->lowStock()->count();
        if ($out) {
            $add('danger', 'out_of_stock', ['count' => $out]);
        }
        if ($low) {
            $add('warning', 'low_stock', ['count' => $low]);
        }

        $unpaid = Booking::where('owner_id', $owner->id)
            ->whereIn('status', ['completed', 'confirmed', 'checked_in'])
            ->whereDate('booking_date', '<', today())
            ->where(fn ($q) => $q->whereNull('payment_method')->orWhere('payment_method', '!=', 'package'))
            ->whereRaw('(total_price - COALESCE(discount_total, 0) - COALESCE(amount_paid, 0)) > 0.004')
            ->count();
        if ($unpaid) {
            $add('warning', 'unpaid_bookings', ['count' => $unpaid]);
        }

        // Abnormal: sessions left open for more than 12 hours (forgotten checkouts keep accruing).
        $stale = SharedSession::where('owner_id', $owner->id)->where('status', 'open')->where('opened_at', '<', now()->subHours(12))->count()
            + Booking::where('owner_id', $owner->id)->where('status', 'open')->whereDate('booking_date', '<', today()->subDay())->count();
        if ($stale) {
            $add('danger', 'stale_sessions', ['count' => $stale]);
        }

        if (! $owner->staff()->exists()) {
            $add('info', 'no_staff');
        }

        $order = ['danger' => 0, 'warning' => 1, 'info' => 2];
        usort($items, fn ($a, $b) => $order[$a['level']] <=> $order[$b['level']]);

        return $items;
    }

    /**
     * Latest things that happened in this business: staff/owner activity and
     * Super Admin actions, newest first.
     *
     * @return Collection<int, array{at: Carbon, actor: string, text: string, kind: string, url: ?string}>
     */
    public function recentActivity(Owner $owner, int $limit = 12): Collection
    {
        $activity = StaffActivityLog::where('owner_id', $owner->id)->latest('created_at')->take($limit)->get()
            ->map(fn (StaffActivityLog $l) => [
                'at' => $l->created_at,
                'actor' => $l->actor_name ?: ($l->actor_type === 'owner' ? __('app.admin_biz.owner') : __('app.admin_biz.staff_member')),
                'text' => $l->description ?: $l->action,
                'kind' => $l->actor_type ?? 'staff',
                'url' => $l->subject_type === Booking::class && $l->subject_id ? '/admin/bookings/'.$l->subject_id : null,
            ]);

        $admin = AdminAuditLog::where('owner_id', $owner->id)->latest('created_at')->take($limit)->get()
            ->map(fn (AdminAuditLog $l) => [
                'at' => $l->created_at,
                'actor' => ($l->admin_name ?: 'Admin').' · '.__('app.admin_biz.super_admin'),
                'text' => $l->description ?: $l->action,
                'kind' => 'admin',
                'url' => null,
            ]);

        return $activity->concat($admin)->sortByDesc('at')->take($limit)->values();
    }
}
