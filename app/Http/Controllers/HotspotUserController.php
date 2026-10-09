<?php

namespace App\Http\Controllers;

use App\Models\Booking;
use App\Models\HotspotUser;
use App\Models\MemberPackage;
use App\Models\Owner;
use App\Models\PackageTemplate;
use App\Models\PackageUsage;
use App\Models\SharedSession;
use App\Models\SpeedProfile;
use App\Services\ActivityLogger;
use App\Services\HotspotSyncService;
use App\Services\MemberSearchService;
use App\Support\Duration;
use App\Support\Highlight;
use App\Support\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class HotspotUserController extends Controller
{
    public function __construct(
        private HotspotSyncService $sync,
        private ActivityLogger $activityLogger,
        private MemberSearchService $memberSearch,
    ) {}

    /**
     * Live search: the search box debounces on the client and re-requests
     * this same route as an Inertia partial reload (only `users` + `search`,
     * ?search= kept in the URL) — a normal request renders the full page.
     * Ranking/pagination is MemberSearchService's job (case-insensitive,
     * partial, fuzzy-tolerant — see its docblock); this method only
     * re-hydrates the full rows for whichever page it decided on.
     */
    public function index(Request $request): Response
    {
        $search = trim((string) $request->query('search', ''));
        $page = max(1, (int) $request->query('page', 1));

        $paginator = $this->memberSearch->paginate(TenantContext::id(), $search, 15, $page);

        // The search pass above only loads a lean id/name/phone/email
        // projection (kept cheap regardless of member count); re-fetch full
        // rows for just the (at most 15) ids actually being displayed, in
        // the order the search already decided.
        $ids = $paginator->getCollection()->pluck('id');
        $owner = TenantContext::user();
        $fullUsers = HotspotUser::whereIn('id', $ids)->get();

        // Hour package pill: each member's usable packages, eager-loaded in one query.
        if ($owner->hasFeature('booking')) {
            $fullUsers->load(['packages' => fn ($q) => $q->whereNull('cancelled_at')
                ->whereDate('starts_on', '<=', today())
                ->whereDate('expires_on', '>=', today())
                ->whereColumn('used_minutes', '<', 'total_minutes')
                ->orderBy('expires_on')]);
        }

        $byId = $fullUsers->keyBy('id');
        $paginator->setCollection($ids->map(fn ($id) => $byId[$id])->values());

        return Inertia::render('Users/Index', [
            'users' => $paginator->withQueryString()->through(fn (HotspotUser $u) => $this->indexRow($u, $search)),
            'search' => $search,
            'hasHotspot' => $owner->hasFeature('hotspot'),
        ]);
    }

    /**
     * One table row for Users/Index. Highlight::mark() returns HTML that is
     * already escaped (only its own <mark> tags are raw), so the page can
     * render name_html/phone_html as HTML safely.
     *
     * @return array<string, mixed>
     */
    private function indexRow(HotspotUser $user, string $search): array
    {
        $package = null;
        if ($user->relationLoaded('packages') && ($pkg = $user->packages->first())) {
            $left = Duration::label($user->packages->sum(fn ($p) => $p->remainingMinutes()));
            $soon = $pkg->isExpiringSoon();
            $package = [
                'soon' => $soon,
                'title' => $user->packages->pluck('name')->implode(', '),
                'text' => $soon ? __('app.packages.expiring_left', ['time' => $left]) : __('app.packages.left', ['time' => $left]),
            ];
        }

        return [
            'id' => $user->id,
            'name_html' => Highlight::mark($user->name, $search),
            'phone_html' => Highlight::mark($user->phone, $search),
            'speed_download' => $user->speed_download,
            'speed_upload' => $user->speed_upload,
            'status' => $user->status,
            'created' => $user->created_at?->format('M d, Y'),
            'package' => $package,
        ];
    }

    public function create(): Response
    {
        $owner = TenantContext::user();
        $plan = $owner->plan;

        return Inertia::render('Users/Create', [
            'hasHotspot' => $owner->hasFeature('hotspot'),
            'plan' => $plan ? [
                'name' => $plan->name,
                'max_members' => $plan->max_members,
                'members' => $owner->hotspotUsers()->count(),
                'percent' => $owner->usagePercentage(),
                'remaining' => $owner->remainingUserSlots(),
            ] : null,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $owner = TenantContext::user();

        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'phone' => ['required', 'string', 'max:20',
                Rule::unique('hotspot_users', 'phone')->where('owner_id', $owner->id)],
            'email' => 'nullable|email|max:255',
            'notes' => 'nullable|string|max:500',
        ]);

        try {
            $this->createMember($owner, $validated);
        } catch (\RuntimeException $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }

        $message = "User {$validated['name']} added successfully";
        if ($owner->hasFeature('hotspot') && ! $owner->hasRouterConfigured()) {
            $message .= ' — configure your MikroTik router in Settings to sync users.';
        }

        return redirect('/users')->with('success', $message);
    }

    /**
     * Create a member from the booking screens without leaving the form.
     *
     * Same rules as the full form (plan limit, router provisioning); only the
     * response shape differs so the picker can select the new member in place.
     */
    public function quickStore(Request $request): JsonResponse
    {
        $owner = TenantContext::user();

        // Validated by hand rather than via $request->validate(): the app only
        // renders JSON for api/* paths (bootstrap/app.php), so a thrown
        // ValidationException would reach the picker's fetch() as a 302 + HTML
        // login/back redirect instead of a 422 carrying the field errors.
        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:255',
            'phone' => ['required', 'string', 'max:20',
                Rule::unique('hotspot_users', 'phone')->where('owner_id', $owner->id)],
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()->toArray()], 422);
        }

        try {
            $user = $this->createMember($owner, $validator->validated());
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json([
            'id' => $user->id,
            'name' => $user->name,
            'phone' => $user->phone,
        ], 201);
    }

    /**
     * Shared member-creation path for the full form and the inline quick-add.
     *
     * Router provisioning + default speed profile apply to hotspot owners only;
     * booking-only owners get the customer record with no MikroTik interaction.
     *
     * @throws \RuntimeException with an owner-facing message when the member
     *                           cannot be created (plan, profile or router).
     */
    private function createMember(Owner $owner, array $data): HotspotUser
    {
        $phone = (string) $data['phone'];
        $password = $phone;

        if (! $owner->plan) {
            throw new \RuntimeException('No active plan assigned. Please contact your administrator.');
        }

        if (! $owner->canAddMoreUsers()) {
            throw new \RuntimeException("You have reached your plan limit of {$owner->plan->max_members} members. Please upgrade your plan to add more users.");
        }

        $defaultProfile = null;

        if ($owner->hasFeature('hotspot')) {
            $defaultProfile = SpeedProfile::where('owner_id', $owner->id)
                ->where('is_default', true)
                ->first();

            if (! $defaultProfile) {
                throw new \RuntimeException('Please set a default speed profile first before adding users.');
            }

            try {
                $this->sync->createUser($owner, $phone, $password, $defaultProfile->name);
            } catch (\Exception $e) {
                throw new \RuntimeException('MikroTik error: '.$e->getMessage());
            }
        }

        $member = HotspotUser::create([
            'owner_id' => $owner->id,
            'name' => $data['name'],
            'phone' => $phone,
            'password' => $password,
            'speed_download' => $defaultProfile->speed_download ?? '10M',
            'speed_upload' => $defaultProfile->speed_upload ?? '5M',
            'speed_profile_id' => $defaultProfile?->id,
            'status' => 'active',
            'email' => $data['email'] ?? null,
            'notes' => $data['notes'] ?? null,
        ]);

        $this->activityLogger->log('member.created', $member, "Added member {$member->name}");

        return $member;
    }

    public function show(int $id): Response
    {
        $owner = TenantContext::user();

        $user = HotspotUser::where('id', $id)
            ->where('owner_id', $owner->id)
            ->with('speedProfile')
            ->firstOrFail();

        $speedProfiles = $owner->hasFeature('hotspot')
            ? SpeedProfile::where('owner_id', $owner->id)->get()
            : collect();

        $recentBookings = collect();
        $openSession = null;
        $stats = null;

        if ($owner->hasFeature('booking')) {
            $recentBookings = $user->bookings()
                ->with('room.workspace')
                ->latest('booking_date')
                ->take(5)
                ->get();

            $openSession = $user->sharedSessions()
                ->with('room')
                ->where('status', 'open')
                ->latest('opened_at')
                ->first();

            // Closing a shared session auto-creates a *completed* booking holding
            // the same total, so lifetime spend counts completed bookings only —
            // adding session totals would double-count every pay-per-minute visit.
            // Product sales are a separate money stream from booking totals, so adding
            // them to lifetime spend is safe (they never inflate total_price).
            $stats = [
                'bookings' => $user->bookings()->where('status', '!=', 'cancelled')->count(),
                'spent' => (float) $user->bookings()->where('status', 'completed')->sum('total_price')
                            - (float) $user->bookings()->where('status', 'completed')->sum('discount_total')
                            + (float) $user->sales()->where('status', 'completed')->sum('total'),
                'minutes' => (float) $user->sharedSessions()->where('status', 'closed')->sum('total_minutes'),
                'last' => $user->bookings()
                    ->where('status', '!=', 'cancelled')
                    ->orderByDesc('booking_date')
                    ->first()?->booking_date,
            ];
        }

        // Hour Packages (booking feature + packages.view): every package with
        // its usage history, plus the active templates for "Add package".
        $staff = auth('staff')->user();
        $showPackages = $owner->hasFeature('booking') && (! $staff || $staff->hasPermission('packages.view'));
        $packages = $showPackages
            ? MemberPackage::where('owner_id', $owner->id)->where('hotspot_user_id', $user->id)->latest('id')->get()
            : collect();

        $packageUsages = $showPackages
            ? PackageUsage::where('owner_id', $owner->id)->where('hotspot_user_id', $user->id)
                ->with(['memberPackage:id,name', 'booking:id,booking_date'])->latest('id')->take(30)->get()
            : collect();
        $packageTemplates = $showPackages
            ? PackageTemplate::where('owner_id', $owner->id)->where('is_active', true)->orderBy('name')->get()
            : collect();
        $fmtDate = fn ($d) => $d?->translatedFormat('M j, Y');

        // Compact summary card: the active package expiring soonest.
        $activePackages = $packages->filter(fn (MemberPackage $p) => $p->status() === MemberPackage::STATUS_ACTIVE)
            ->sortBy('expires_on')->values();
        $mainPackage = $activePackages->first();

        return Inertia::render('Users/Show', [
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'phone' => $user->phone,
                'email' => $user->email,
                'notes' => $user->notes,
                'status' => $user->status,
                'speed_download' => $user->speed_download,
                'speed_upload' => $user->speed_upload,
                'speed_profile_id' => $user->speed_profile_id,
                'speed_profile_name' => $user->speedProfile?->name,
                'member_since' => $user->created_at?->translatedFormat('F Y'),
                'created' => $user->created_at?->format('M d, Y'),
            ],
            'hasHotspot' => $owner->hasFeature('hotspot'),
            'hasBooking' => $owner->hasFeature('booking'),
            'speedProfiles' => $speedProfiles->map(fn (SpeedProfile $p) => [
                'id' => $p->id,
                'name' => $p->name,
                'speed_download' => $p->speed_download,
                'speed_upload' => $p->speed_upload,
            ])->values(),
            'recentBookings' => $recentBookings->map(fn (Booking $b) => [
                'id' => $b->id,
                'place' => $b->room?->workspace?->name.' / '.$b->room?->name,
                'date' => $b->booking_date?->format('d M Y'),
                'total_hours' => $b->total_hours,
                'status_label' => $b->statusLabel(),
                'status_class' => $b->statusBadgeClass(),
                'total_price' => (float) $b->total_price,
            ])->values(),
            'hasOpenSession' => (bool) $openSession,
            'stats' => $stats ? [
                'bookings' => $stats['bookings'],
                'spent' => $stats['spent'],
                'minutes' => (int) $stats['minutes'],
                'last' => $stats['last']?->format('d M Y'),
            ] : null,
            'activity' => $this->activityFeed($owner, $user)->map(fn (array $item) => [
                'type' => $item['type'],
                'room' => $item['room'] ?? null,
                'price' => (float) ($item['price'] ?? 0),
                'minutes' => (int) ($item['minutes'] ?? 0),
                'ago' => $item['at']?->diffForHumans(),
            ])->values(),
            'showPackages' => $showPackages,
            'canAssignPackages' => $showPackages && (! $staff || $staff->hasPermission('packages.assign')),
            'packageSummary' => $mainPackage ? [
                'name' => $mainPackage->name,
                'soon' => $mainPackage->isExpiringSoon(),
                'percent' => $mainPackage->progressPercent(),
                'more' => $activePackages->count() - 1,
                'remaining_text' => __('app.packages.remaining_of', ['remaining' => $mainPackage->remainingLabel(), 'total' => $mainPackage->totalLabel()]),
                'used_label' => $mainPackage->usedLabel(),
                'expires_text' => __('app.packages.expires', ['date' => $fmtDate($mainPackage->expires_on)]),
            ] : null,
            'packages' => $packages->map(function (MemberPackage $pkg) use ($fmtDate) {
                $status = $pkg->status();

                return [
                    'id' => $pkg->id,
                    'name' => $pkg->name,
                    'status' => $status,
                    'status_tone' => $pkg->statusTone(),
                    'soon' => $pkg->isExpiringSoon(),
                    'percent' => $pkg->progressPercent(),
                    'remaining_minutes' => $pkg->remainingMinutes(),
                    'starts_text' => $status === MemberPackage::STATUS_SCHEDULED ? __('app.packages.starts', ['date' => $fmtDate($pkg->starts_on)]) : null,
                    'expires_text' => $status === MemberPackage::STATUS_EXPIRED
                        ? __('app.packages.expired_on', ['date' => $fmtDate($pkg->expires_on)])
                        : __('app.packages.expires', ['date' => $fmtDate($pkg->expires_on)]),
                    'used_of' => __('app.packages.used_of', ['used' => $pkg->usedLabel(), 'total' => $pkg->totalLabel()]),
                    'left_text' => __('app.packages.left', ['time' => $pkg->remainingLabel()]),
                    'price_paid' => (float) $pkg->price_paid,
                    'cancelled_reason' => $pkg->cancelled_reason,
                    'cancellable' => ! $pkg->cancelled_at && $status !== MemberPackage::STATUS_EXPIRED,
                    'cancel_url' => route('member-packages.cancel', $pkg->id),
                ];
            })->values(),
            'packageUsages' => $packageUsages->map(fn (PackageUsage $u) => [
                'id' => $u->id,
                'label' => $u->label(),
                'booking_id' => $u->booking?->id,
                'at' => $u->created_at?->translatedFormat('M j, g:i A'),
                'package_name' => $u->memberPackage?->name,
                'is_out' => $u->minutes > 0,
                'change' => $u->changeLabel(),
            ])->values(),
            'packageTemplates' => $packageTemplates->map(fn (PackageTemplate $t) => [
                'id' => $t->id,
                'name' => $t->name,
                'hours' => rtrim(rtrim(number_format($t->total_minutes / 60, 2, '.', ''), '0'), '.'),
                'hours_label' => $t->hoursLabel(),
                'price' => (string) $t->price,
                'days' => (int) $t->validity_days,
            ])->values(),
            'packageStoreUrl' => route('member-packages.store', $user->id),
            'packageDefaults' => [
                'starts_on' => today()->toDateString(),
                'expires_on' => today()->addDays(29)->toDateString(),
            ],
        ]);
    }

    /**
     * Newest-first timeline of everything this member has done in the space.
     *
     * Bookings that were auto-created when a shared session closed are skipped:
     * the session itself already reports that visit, so including both would
     * show the same event twice.
     *
     * @return Collection<int, array<string, mixed>>
     */
    private function activityFeed(Owner $owner, HotspotUser $user, int $limit = 6): Collection
    {
        $items = collect();

        if ($owner->hasFeature('booking')) {
            $sessionBookingIds = $user->sharedSessions()
                ->whereNotNull('booking_id')
                ->pluck('booking_id')
                ->all();

            $items = $items->concat(
                $user->bookings()
                    ->with('room')
                    ->whereNotIn('id', $sessionBookingIds)
                    ->latest('created_at')
                    ->take($limit)
                    ->get()
                    ->map(fn (Booking $b) => [
                        'type' => 'booking',
                        'at' => $b->created_at,
                        'room' => $b->room?->name,
                        'price' => (float) $b->total_price,
                        'when' => $b->booking_date,
                        'url' => "/bookings/{$b->id}",
                    ])
            );

            $items = $items->concat(
                $user->sharedSessions()
                    ->with('room')
                    ->latest('opened_at')
                    ->take($limit)
                    ->get()
                    ->map(fn (SharedSession $s) => [
                        'type' => $s->status === 'open' ? 'session_open' : 'session_closed',
                        'at' => $s->closed_at ?? $s->opened_at,
                        'room' => $s->room?->name,
                        'price' => (float) $s->total_price,
                        'minutes' => (float) $s->total_minutes,
                        'url' => null,
                    ])
            );
        }

        $items->push([
            'type' => 'created',
            'at' => $user->created_at,
            'url' => null,
        ]);

        return $items->sortByDesc('at')->take($limit)->values();
    }

    public function edit(int $id): Response
    {
        $user = HotspotUser::where('id', $id)
            ->where('owner_id', TenantContext::id())
            ->firstOrFail();

        return Inertia::render('Users/Edit', [
            'user' => $user->only(['id', 'name', 'phone', 'email', 'notes', 'status']),
        ]);
    }

    public function update(Request $request, int $id): RedirectResponse
    {
        $user = HotspotUser::where('id', $id)
            ->where('owner_id', TenantContext::id())
            ->firstOrFail();

        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'status' => 'required|in:active,inactive',
            'email' => 'nullable|email|max:255',
            'notes' => 'nullable|string|max:500',
        ]);

        $user->update([
            'name' => $validated['name'],
            'status' => $validated['status'],
            'email' => $validated['email'] ?? $user->email,
            'notes' => $validated['notes'] ?? $user->notes,
        ]);

        $this->activityLogger->log('member.updated', $user, "Updated member {$user->name}");

        return redirect("/users/{$user->id}")->with('success', 'User updated successfully');
    }

    public function destroy(int $id): RedirectResponse
    {
        $user = HotspotUser::where('id', $id)
            ->where('owner_id', TenantContext::id())
            ->firstOrFail();

        $owner = TenantContext::user();

        try {
            $this->sync->deleteUser($owner, $user->phone);
        } catch (\Exception $e) {
            return back()->with('error', "Could not delete user from MikroTik: {$e->getMessage()}");
        }

        $this->activityLogger->log('member.deleted', $user, "Deleted member {$user->name}");

        $user->delete();

        return redirect('/users')->with('success', 'User deleted successfully');
    }

    public function toggleStatus(int $id): RedirectResponse
    {
        $user = HotspotUser::where('id', $id)
            ->where('owner_id', TenantContext::id())
            ->firstOrFail();

        $user->update([
            'status' => $user->status === 'active' ? 'inactive' : 'active',
        ]);

        $this->activityLogger->log('member.status_toggled', $user, "{$user->name} marked {$user->status}");

        return back()->with('success', 'User status updated successfully');
    }

    public function updateSpeed(Request $request, int $id): RedirectResponse
    {
        $user = HotspotUser::where('id', $id)
            ->where('owner_id', TenantContext::id())
            ->firstOrFail();

        $validated = $request->validate([
            'speed_profile_id' => 'required|exists:speed_profiles,id',
        ]);

        $profile = SpeedProfile::where('id', $validated['speed_profile_id'])
            ->where('owner_id', TenantContext::id())
            ->firstOrFail();

        $owner = TenantContext::user();

        try {
            $this->sync->setUserSpeed($owner, $user->phone, $profile->name);
        } catch (\Exception $e) {
            return back()->with('error', 'MikroTik error: '.$e->getMessage());
        }

        $user->update([
            'speed_download' => $profile->speed_download,
            'speed_upload' => $profile->speed_upload,
            'speed_profile_id' => $profile->id,
        ]);

        return back()->with('success', 'Speed updated successfully');
    }

    public function search(Request $request): JsonResponse
    {
        $query = $request->get('q', '');

        $users = HotspotUser::where('owner_id', TenantContext::id())
            ->where('status', 'active')
            ->where(function ($q) use ($query) {
                $q->where('name', 'like', "%{$query}%")
                    ->orWhere('phone', 'like', "%{$query}%");
            })
            ->select('id', 'name', 'phone')
            ->limit(10)
            ->get();

        return response()->json($users);
    }
}
