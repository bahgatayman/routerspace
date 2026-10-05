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
use App\Support\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class HotspotUserController extends Controller
{
    public function __construct(
        private HotspotSyncService $sync,
        private ActivityLogger $activityLogger,
        private MemberSearchService $memberSearch,
    ) {}

    /**
     * Live search: the search box debounces on the client and fetches this
     * same route with X-Requested-With set, getting back just the table
     * partial to swap in — a normal (non-AJAX) request still renders the
     * full page. Ranking/pagination is MemberSearchService's job (case-
     * insensitive, partial, fuzzy-tolerant — see its docblock); this method
     * only re-hydrates the full rows for whichever page it decided on.
     */
    public function index(Request $request): View
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

        return view($request->ajax() ? 'users._table' : 'users.index', [
            'users' => $paginator,
            'search' => $search,
        ]);
    }

    public function create(): View
    {
        return view('users.create');
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

    public function show(int $id): View
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

        return view('users.show', [
            'user' => $user,
            'speedProfiles' => $speedProfiles,
            'recentBookings' => $recentBookings,
            'openSession' => $openSession,
            'stats' => $stats,
            'activity' => $this->activityFeed($owner, $user),
            'showPackages' => $showPackages,
            'packages' => $packages,
            'packageUsages' => $showPackages
                ? PackageUsage::where('owner_id', $owner->id)->where('hotspot_user_id', $user->id)
                    ->with(['memberPackage:id,name', 'booking:id,booking_date'])->latest('id')->take(30)->get()
                : collect(),
            'packageTemplates' => $showPackages
                ? PackageTemplate::where('owner_id', $owner->id)->where('is_active', true)->orderBy('name')->get()
                : collect(),
            'canAssignPackages' => $showPackages && (! $staff || $staff->hasPermission('packages.assign')),
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

    public function edit(int $id): View
    {
        $user = HotspotUser::where('id', $id)
            ->where('owner_id', TenantContext::id())
            ->firstOrFail();

        return view('users.edit', [
            'user' => $user,
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
