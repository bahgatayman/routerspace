<?php

namespace App\Http\Controllers;

use App\Exports\FinancialsExport;
use App\Models\Booking;
use App\Models\SharedSession;
use App\Models\StaffActivityLog;
use App\Services\AnalyticsPeriod;
use App\Services\ExpenseAnalyticsService;
use App\Services\RevenueAnalyticsService;
use App\Support\TenantContext;
use App\Support\TransactionsQuery;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Lang;
use Inertia\Inertia;
use Inertia\Response;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Throwable;

class FinancialController extends Controller
{
    private const STATUSES = ['all', 'completed', 'pending', 'confirmed', 'checked_in', 'cancelled', 'no_show'];

    private const SOURCES = ['all', 'direct_booking', 'shared_session', 'with_products'];

    public function __construct(
        private RevenueAnalyticsService $revenueAnalytics,
        private ExpenseAnalyticsService $expenseAnalytics,
    ) {}

    public function index(Request $request): Response
    {
        $owner = TenantContext::user();
        [$period, $periodKey, $customStart, $customEnd] = $this->resolvePeriod($request);

        $staff = auth('staff')->user();
        $canViewExpenses = ! $staff || $staff->hasPermission('expenses.view');

        $trend = $this->revenueAnalytics->dailyRevenueTrend($owner, $period);

        return Inertia::render('Financials/Index', [
            'periodKey' => $periodKey,
            'customStart' => $customStart,
            'customEnd' => $customEnd,
            'exportUrl' => route('financials.export', $request->query()),
            'canViewExpenses' => $canViewExpenses,
            'revenueToday' => $this->revenueAnalytics->totalRevenue($owner, AnalyticsPeriod::today()),
            'revenueThisWeek' => $this->revenueAnalytics->totalRevenue($owner, AnalyticsPeriod::thisWeek()),
            'revenueThisMonth' => $this->revenueAnalytics->totalRevenue($owner, AnalyticsPeriod::thisMonth()),
            'comparison' => $this->revenueAnalytics->revenueWithComparison($owner, $period),
            'bookingRevenue' => $this->revenueAnalytics->bookingRevenue($owner, $period),
            'saleRevenue' => $this->revenueAnalytics->saleRevenue($owner, $period),
            'averageBookingValue' => $this->revenueAnalytics->averageBookingValue($owner, $period),
            'trendTotal' => array_sum($trend),
            'trend' => collect($trend)->map(fn ($amount, $date) => [
                'date' => (string) $date,
                'label' => Carbon::parse($date)->format('j M'),
                'amount' => (float) $amount,
            ])->values()->all(),
            'byRoom' => collect($this->revenueAnalytics->revenueByRoom($owner, $period))
                ->map(fn ($row) => ['name' => $row['room_name'], 'revenue' => (float) $row['revenue']])->values()->all(),
            'byRoomType' => collect($this->revenueAnalytics->revenueByRoomType($owner, $period))
                ->map(fn ($row) => [
                    'name' => Lang::has('app.room_type.'.$row['type']) ? __('app.room_type.'.$row['type']) : ucfirst($row['type']),
                    'revenue' => (float) $row['revenue'],
                ])->values()->all(),
            'byProduct' => collect($this->revenueAnalytics->revenueByProduct($owner, $period))
                ->map(fn ($row) => ['name' => $row['name'], 'revenue' => (float) $row['revenue']])->values()->all(),
            'totalExpenses' => $this->expenseAnalytics->totalExpenses($owner, $period),
            'netTotal' => $this->revenueAnalytics->totalRevenue($owner, $period) - $this->expenseAnalytics->totalExpenses($owner, $period),
        ]);
    }

    public function transactions(Request $request): Response
    {
        $owner = TenantContext::user();
        [$period, $periodKey, $customStart, $customEnd] = $this->resolvePeriod($request);
        $status = $this->resolveStatus($request);
        $source = $this->resolveSource($request);

        $bookings = TransactionsQuery::build($owner->id, $period, $status, $source)
            ->orderByDesc('booking_date')
            ->orderByDesc('start_time')
            ->paginate(20)
            ->withQueryString();

        return Inertia::render('Financials/Transactions', [
            'bookings' => $bookings->through(fn (Booking $b) => [
                'id' => $b->id,
                'ref' => '#'.str_pad((string) $b->id, 4, '0', STR_PAD_LEFT),
                'date' => $b->booking_date->format('M d, Y'),
                'customer' => $b->hotspotUser?->name,
                'room' => $b->room?->name,
                'origin' => $b->sharedSession
                    ? __('app.financials.origin_shared_session', ['id' => $b->sharedSession->id])
                    : __('app.financials.origin_direct'),
                'status' => $b->status,
                'status_label' => $b->statusLabel(),
                'status_class' => $b->statusBadgeClass(),
                'payment_status' => $b->payment_status,
                'payment_label' => $b->paymentStatusLabel(),
                'amount_paid' => (float) $b->amount_paid,
                'grand_total' => (float) $b->grandTotal(),
            ]),
            'exportUrl' => route('financials.export', $request->query()),
            'periodKey' => $periodKey,
            'customStart' => $customStart,
            'customEnd' => $customEnd,
            'status' => $status,
            'source' => $source,
        ]);
    }

    public function show(int $id): Response
    {
        $booking = Booking::where('owner_id', TenantContext::id())
            ->with(['room.workspace', 'hotspotUser', 'sale.items.product', 'sharedSession'])
            ->findOrFail($id);

        $sale = $booking->sale;
        $showSale = $sale && $sale->status === 'completed' && $sale->items->isNotEmpty();

        return Inertia::render('Financials/TransactionShow', [
            'booking' => [
                'id' => $booking->id,
                'ref' => '#'.str_pad((string) $booking->id, 4, '0', STR_PAD_LEFT),
                'date' => $booking->booking_date->format('l, M d, Y'),
                'time_range' => $booking->timeRange(),
                'status' => $booking->status,
                'status_label' => $booking->statusLabel(),
                'status_class' => $booking->statusBadgeClass(),
                'customer' => $booking->hotspotUser?->name,
                'room' => $booking->room?->name,
                'origin' => $booking->sharedSession
                    ? __('app.financials.origin_shared_session', ['id' => $booking->sharedSession->id])
                    : __('app.financials.origin_direct'),
                'pricing_note' => $booking->pricing_note ?: $booking->total_hours.'h × ج.م '.number_format((float) $booking->price_per_hour, 2),
                'total_price' => (float) $booking->total_price,
                'coupon_code' => $booking->coupon_id && $booking->discount_total > 0 ? $booking->coupon?->code : null,
                'discount_total' => (float) $booking->discount_total,
                'grand_total' => (float) $booking->grandTotal(),
                'sale' => $showSale ? [
                    'items' => $sale->items->map(fn ($item) => [
                        'id' => $item->id,
                        'name' => $item->name,
                        'quantity' => (int) $item->quantity,
                        'line_total' => (float) $item->line_total,
                    ])->values()->all(),
                    'discount_total' => (float) $sale->discount_total,
                    'tax_total' => (float) $sale->tax_total,
                ] : null,
            ],
            'createdBy' => $this->resolveCreatedBy($booking),
        ]);
    }

    public function export(Request $request): BinaryFileResponse
    {
        $owner = TenantContext::user();
        [$period] = $this->resolvePeriod($request);
        $status = $this->resolveStatus($request);
        $source = $this->resolveSource($request);

        $filename = 'financials-'.$period->label.'-'.now()->format('Ymd-His').'.xlsx';

        return Excel::download(
            new FinancialsExport($owner, $period, $status, $source, $this->revenueAnalytics),
            $filename
        );
    }

    /**
     * Best-effort attribution (V1-lite, see architecture doc §6/§15). A
     * direct booking resolves via its own booking.created log entry. A
     * session-spawned booking has no such entry (SharedSessionController::
     * close() only logs shared_session.closed against the session itself),
     * so it's traced through to the session's shared_session.opened entry
     * instead. Returns null when nothing was ever logged for either path
     * (e.g. data predating the audit log).
     */
    private function resolveCreatedBy(Booking $booking): ?array
    {
        $log = $booking->sharedSession
            ? StaffActivityLog::where('subject_type', SharedSession::class)
                ->where('subject_id', $booking->sharedSession->id)
                ->where('action', 'shared_session.opened')
                ->first()
            : StaffActivityLog::where('subject_type', Booking::class)
                ->where('subject_id', $booking->id)
                ->where('action', 'booking.created')
                ->first();

        if (! $log) {
            return null;
        }

        return ['name' => $log->actor_name, 'type' => $log->actor_type];
    }

    /** @return array{0: AnalyticsPeriod, 1: string, 2: ?string, 3: ?string} */
    private function resolvePeriod(Request $request): array
    {
        $key = in_array($request->get('period'), ['today', 'this_week', 'this_month', 'custom'], true)
            ? $request->get('period')
            : 'this_month';

        $customStart = $request->get('start');
        $customEnd = $request->get('end');

        if ($key === 'custom') {
            $start = $this->parseDate($customStart) ?? now()->startOfMonth();
            $end = $this->parseDate($customEnd) ?? now();
            if ($end->lt($start)) {
                [$start, $end] = [$end, $start];
            }

            return [AnalyticsPeriod::custom($start, $end), $key, $start->toDateString(), $end->toDateString()];
        }

        $period = match ($key) {
            'today' => AnalyticsPeriod::today(),
            'this_week' => AnalyticsPeriod::thisWeek(),
            default => AnalyticsPeriod::thisMonth(),
        };

        return [$period, $key, $customStart, $customEnd];
    }

    private function parseDate(?string $value): ?Carbon
    {
        if (! $value) {
            return null;
        }

        try {
            return Carbon::parse($value);
        } catch (Throwable) {
            return null;
        }
    }

    private function resolveStatus(Request $request): string
    {
        return in_array($request->get('status'), self::STATUSES, true) ? $request->get('status') : 'completed';
    }

    private function resolveSource(Request $request): string
    {
        return in_array($request->get('source'), self::SOURCES, true) ? $request->get('source') : 'all';
    }
}
