<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Coupon;
use App\Models\Owner;
use App\Services\AdminAuditLogger;
use App\Services\CouponService;
use App\Support\Coupons\CouponForm;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\View\View;

/**
 * Super Admin coupon management across every business. Routed only inside the
 * `auth:admin` group, so it is unreachable for owners and staff. Coupons stay
 * per business: each one is created for a chosen business, and its rooms and
 * products are validated against THAT business through the same CouponForm the
 * owner screen uses — no second coupon system and no cross-business targeting.
 */
class CouponController extends Controller
{
    public function __construct(
        private CouponService $coupons,
        private CouponForm $form,
        private AdminAuditLogger $audit,
    ) {}

    public function index(Request $request): View
    {
        $search = trim((string) $request->query('q', ''));
        $status = in_array($request->query('status'), ['active', 'inactive', 'scheduled', 'expired', 'limit_reached'], true) ? $request->query('status') : null;
        $ownerId = $request->integer('owner') ?: null;

        $coupons = Coupon::query()
            ->with('owner:id,name,business_name')
            ->withCount(['usages', 'rooms', 'products'])
            ->when($search !== '', fn ($q) => $q->where('code', 'like', '%'.addcslashes($this->coupons->normalizeCode($search), '%_\\').'%'))
            ->when($status, fn ($q) => $q->withStatus($status))
            ->when($ownerId, fn ($q) => $q->where('owner_id', $ownerId))
            ->orderByDesc('created_at')
            ->paginate(25)
            ->withQueryString();

        return view('admin.coupons.index', [
            'coupons' => $coupons,
            'search' => $search,
            'status' => $status,
            'ownerId' => $ownerId,
            'businesses' => $this->businesses(),
            'total' => Coupon::count(),
        ]);
    }

    public function show(int $coupon): View
    {
        $coupon = Coupon::with(['owner:id,name,business_name', 'rooms.workspace', 'products'])
            ->withCount(['usages', 'rooms', 'products'])
            ->findOrFail($coupon);

        return view('admin.coupons.show', [
            'coupon' => $coupon,
            'usages' => $coupon->usages()->with(['hotspotUser:id,name', 'booking:id'])->latest('used_at')->take(15)->get(),
        ]);
    }

    /** Step 1 picks the business (?owner=), step 2 is the coupon form for it. */
    public function create(Request $request): View
    {
        $owner = $request->filled('owner') ? Owner::find($request->integer('owner')) : null;

        if (! $owner) {
            return view('admin.coupons.create', ['owner' => null, 'businesses' => $this->businesses()]);
        }

        return view('admin.coupons.create', [
            'owner' => $owner,
            'coupon' => $this->form->blank($owner),
            'roomGroups' => $this->form->roomsGrouped($owner),
            'productGroups' => $this->form->productsGrouped($owner),
            'selectedRoomIds' => [],
            'selectedProductIds' => [],
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $request->validate(['owner_id' => 'required|integer|exists:owners,id']);
        $owner = Owner::findOrFail($request->integer('owner_id'));

        $validated = $this->form->validate($request, $owner);
        $coupon = new Coupon(['owner_id' => $owner->id]);
        $this->form->save($coupon, $validated, $request);

        $this->audit->log('coupon.created', $owner, __('app.coupons.admin.audit_created', ['code' => $coupon->code, 'business' => $this->name($owner)]), ['coupon_id' => $coupon->id]);

        return redirect()->route('admin.coupons.show', $coupon->id)->with('success', __('app.coupons.created'));
    }

    public function edit(int $coupon): View
    {
        $coupon = Coupon::with(['owner', 'rooms', 'products'])->findOrFail($coupon);

        return view('admin.coupons.edit', [
            'coupon' => $coupon,
            'owner' => $coupon->owner,
            'roomGroups' => $this->form->roomsGrouped($coupon->owner),
            'productGroups' => $this->form->productsGrouped($coupon->owner),
            'selectedRoomIds' => $coupon->rooms->pluck('id')->all(),
            'selectedProductIds' => $coupon->products->pluck('id')->all(),
        ]);
    }

    public function update(Request $request, int $coupon): RedirectResponse
    {
        $coupon = Coupon::with('owner')->findOrFail($coupon);

        // The business is fixed: validation (code uniqueness, rooms, products) runs against the coupon's own business.
        $validated = $this->form->validate($request, $coupon->owner, $coupon->id);
        $this->form->save($coupon, $validated, $request);

        $this->audit->log('coupon.updated', $coupon->owner, __('app.coupons.admin.audit_updated', ['code' => $coupon->code]), ['coupon_id' => $coupon->id]);

        return redirect()->route('admin.coupons.show', $coupon->id)->with('success', __('app.coupons.updated'));
    }

    public function toggle(int $coupon): RedirectResponse
    {
        $coupon = Coupon::with('owner')->findOrFail($coupon);
        $coupon->update(['is_active' => ! $coupon->is_active]);

        $state = __('app.coupons.admin.'.($coupon->is_active ? 'state_on' : 'state_off'));
        $this->audit->log('coupon.toggled', $coupon->owner, __('app.coupons.admin.audit_toggled', ['code' => $coupon->code, 'state' => $state]), ['coupon_id' => $coupon->id, 'is_active' => $coupon->is_active]);

        return back()->with('success', __('app.coupons.toggled'));
    }

    public function destroy(int $coupon): RedirectResponse
    {
        $coupon = Coupon::with('owner')->findOrFail($coupon);

        // Same rule as the owner screen: a used coupon keeps its history — deactivate instead.
        if ($coupon->usages()->exists()) {
            return back()->with('error', __('app.coupons.delete_blocked'));
        }

        $code = $coupon->code;
        $owner = $coupon->owner;
        $coupon->delete();

        $this->audit->log('coupon.deleted', $owner, __('app.coupons.admin.audit_deleted', ['code' => $code]), ['code' => $code]);

        return redirect()->route('admin.business.coupons', $owner->id)->with('success', __('app.coupons.deleted'));
    }

    /** @return Collection<int, Owner> */
    private function businesses()
    {
        return Owner::orderByRaw('COALESCE(business_name, name)')->get(['id', 'name', 'business_name']);
    }

    private function name(Owner $owner): string
    {
        return $owner->business_name ?: $owner->name;
    }
}
