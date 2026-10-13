<?php

namespace App\Http\Controllers;

use App\Models\Coupon;
use App\Services\CouponService;
use App\Support\Coupons\CouponForm;
use App\Support\TenantContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class CouponController extends Controller
{
    public function __construct(private CouponService $coupons, private CouponForm $form) {}

    public function index(Request $request): View
    {
        $owner = TenantContext::user();
        $search = $request->query('search');
        $status = $request->query('status');

        $coupons = Coupon::where('owner_id', $owner->id)
            ->withCount(['usages', 'rooms', 'products'])
            ->when($search, fn ($q) => $q->where('code', 'like', '%'.$this->coupons->normalizeCode($search).'%'))
            ->when($status, fn ($q) => $q->withStatus($status))
            ->orderByDesc('created_at')
            ->paginate(20)
            ->withQueryString();

        $staff = auth('staff')->user();
        $canCreate = ! $staff || $staff->hasPermission('coupons.create');

        return view('coupons.index', [
            'coupons' => $coupons,
            'search' => $search,
            'status' => $status,
            'canCreate' => $canCreate,
            'canEdit' => ! $staff || $staff->hasPermission('coupons.edit'),
            'canDelete' => ! $staff || $staff->hasPermission('coupons.delete'),
            'newCoupon' => $canCreate ? $this->form->blank() : null,
            'roomGroups' => $canCreate ? $this->form->roomsGrouped($owner) : collect(),
            'productGroups' => $canCreate ? $this->form->productsGrouped($owner) : collect(),
            'selectedRoomIds' => [],
            'selectedProductIds' => [],
        ]);
    }

    public function create(): View
    {
        $owner = TenantContext::user();

        return view('coupons.create', [
            'coupon' => $this->form->blank(),
            'roomGroups' => $this->form->roomsGrouped($owner),
            'productGroups' => $this->form->productsGrouped($owner),
            'selectedRoomIds' => [],
            'selectedProductIds' => [],
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $owner = TenantContext::user();
        $validated = $this->form->validate($request, $owner);

        $this->form->save(new Coupon(['owner_id' => $owner->id]), $validated, $request);

        return redirect()->route('coupons.index')->with('success', __('app.coupons.created'));
    }

    public function edit(int $id): View
    {
        $owner = TenantContext::user();
        $coupon = Coupon::where('owner_id', $owner->id)->with(['rooms', 'products'])->findOrFail($id);

        return view('coupons.edit', [
            'coupon' => $coupon,
            'roomGroups' => $this->form->roomsGrouped($owner),
            'productGroups' => $this->form->productsGrouped($owner),
            'selectedRoomIds' => $coupon->rooms->pluck('id')->all(),
            'selectedProductIds' => $coupon->products->pluck('id')->all(),
        ]);
    }

    public function update(Request $request, int $id): RedirectResponse
    {
        $owner = TenantContext::user();
        $coupon = Coupon::where('owner_id', $owner->id)->findOrFail($id);
        $validated = $this->form->validate($request, $owner, $coupon->id);

        $this->form->save($coupon, $validated, $request);

        return redirect()->route('coupons.index')->with('success', __('app.coupons.updated'));
    }

    public function destroy(int $id): RedirectResponse
    {
        $coupon = Coupon::where('owner_id', TenantContext::id())->findOrFail($id);

        if ($coupon->usages()->exists()) {
            return back()->with('error', __('app.coupons.delete_blocked'));
        }

        $coupon->delete();

        return redirect()->route('coupons.index')->with('success', __('app.coupons.deleted'));
    }

    public function toggleActive(int $id): RedirectResponse
    {
        $coupon = Coupon::where('owner_id', TenantContext::id())->findOrFail($id);
        $coupon->update(['is_active' => ! $coupon->is_active]);

        return back()->with('success', __('app.coupons.toggled'));
    }
}
