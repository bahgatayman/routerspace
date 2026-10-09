<?php

namespace App\Http\Controllers;

use App\Models\Coupon;
use App\Models\Owner;
use App\Models\Product;
use App\Models\Room;
use App\Services\CouponService;
use App\Support\TenantContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class CouponController extends Controller
{
    public function __construct(private CouponService $coupons) {}

    public function index(Request $request): Response
    {
        $owner = TenantContext::user();
        $search = $request->query('search');
        $status = $request->query('status');

        $coupons = Coupon::where('owner_id', $owner->id)
            ->withCount('usages')
            ->when($search, fn ($q) => $q->where('code', 'like', '%'.$this->coupons->normalizeCode($search).'%'))
            ->when($status, fn ($q) => $q->withStatus($status))
            ->orderByDesc('created_at')
            ->paginate(20)
            ->withQueryString();

        $staff = auth('staff')->user();
        $canCreate = ! $staff || $staff->hasPermission('coupons.create');

        return Inertia::render('Coupons/Index', [
            'coupons' => $coupons->through(fn (Coupon $c) => $this->couponCard($c)),
            'search' => $search,
            'status' => $status,
            'canCreate' => $canCreate,
            'canEdit' => ! $staff || $staff->hasPermission('coupons.edit'),
            'canDelete' => ! $staff || $staff->hasPermission('coupons.delete'),
            'newCoupon' => $canCreate ? $this->couponFormData(new Coupon(['applies_to' => Coupon::SCOPE_BOTH, 'discount_type' => Coupon::TYPE_PERCENTAGE, 'is_active' => true]), [], []) : null,
            'roomGroups' => $canCreate ? $this->groupsForPicker($this->ownerRoomsGrouped($owner)) : [],
            'productGroups' => $canCreate ? $this->groupsForPicker($this->ownerProductsGrouped($owner)) : [],
        ]);
    }

    public function create(): Response
    {
        $owner = TenantContext::user();

        return Inertia::render('Coupons/Form', [
            'coupon' => $this->couponFormData(new Coupon(['applies_to' => Coupon::SCOPE_BOTH, 'discount_type' => Coupon::TYPE_PERCENTAGE, 'is_active' => true]), [], []),
            'roomGroups' => $this->groupsForPicker($this->ownerRoomsGrouped($owner)),
            'productGroups' => $this->groupsForPicker($this->ownerProductsGrouped($owner)),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $owner = TenantContext::user();
        $validated = $this->validated($request, $owner);

        $coupon = new Coupon(['owner_id' => $owner->id]);
        $this->persist($coupon, $validated, $request);

        return redirect()->route('coupons.index')->with('success', __('app.coupons.created'));
    }

    public function edit(int $id): Response
    {
        $owner = TenantContext::user();
        $coupon = Coupon::where('owner_id', $owner->id)->with(['rooms', 'products'])->findOrFail($id);

        return Inertia::render('Coupons/Form', [
            'coupon' => $this->couponFormData($coupon, $coupon->rooms->pluck('id')->all(), $coupon->products->pluck('id')->all()),
            'roomGroups' => $this->groupsForPicker($this->ownerRoomsGrouped($owner)),
            'productGroups' => $this->groupsForPicker($this->ownerProductsGrouped($owner)),
        ]);
    }

    /** One coupon card on the index (labels resolved here, never in the page). */
    private function couponCard(Coupon $coupon): array
    {
        $scopeLabel = match ($coupon->applies_to) {
            Coupon::SCOPE_BOTH => __('app.coupons.both'),
            Coupon::SCOPE_ROOMS => $coupon->targetsAllRooms() ? __('app.coupons.all_rooms') : __('app.coupons.specific_rooms'),
            default => $coupon->targetsAllProducts() ? __('app.coupons.all_products') : __('app.coupons.specific_products'),
        };
        $used = $coupon->usedCount();

        return [
            'id' => $coupon->id,
            'code' => $coupon->code,
            'discount_label' => $coupon->discountLabel(),
            'status_key' => $coupon->statusKey(),
            'status_label' => $coupon->statusLabel(),
            'status_tone' => $coupon->statusTone(),
            'applies_to' => $coupon->applies_to,
            'scope_label' => $scopeLabel,
            'usage_label' => $coupon->usage_limit !== null
                ? __('app.coupons.used_of', ['used' => $used, 'limit' => $coupon->usage_limit])
                : __('app.coupons.used_count', ['count' => $used]),
            'usage_percent' => $coupon->usage_limit
                ? min(100, (int) round($used / max(1, $coupon->usage_limit) * 100))
                : null,
            'expires' => $coupon->expires_at?->format('M d, Y'),
            'is_active' => (bool) $coupon->is_active,
        ];
    }

    /** Initial form state for the create/edit form and the quick-create modal. */
    private function couponFormData(Coupon $coupon, array $roomIds, array $productIds): array
    {
        return [
            'id' => $coupon->exists ? $coupon->id : null,
            'code' => $coupon->code ?? '',
            'discount_type' => $coupon->discount_type,
            'discount_value' => $coupon->discount_value ?? '',
            'applies_to' => $coupon->applies_to,
            'room_scope' => empty($roomIds) ? 'all' : 'specific',
            'product_scope' => empty($productIds) ? 'all' : 'specific',
            'room_ids' => array_map('intval', $roomIds),
            'product_ids' => array_map('intval', $productIds),
            'starts_at' => $coupon->starts_at?->toDateString() ?? '',
            'expires_at' => $coupon->expires_at?->toDateString() ?? '',
            'usage_limit' => $coupon->usage_limit ?? '',
            'per_customer_limit' => $coupon->per_customer_limit ?? '',
            'minimum_spend' => $coupon->minimum_spend ?? '',
            'is_active' => $coupon->exists ? (bool) $coupon->is_active : true,
        ];
    }

    /** @param  Collection<string, Collection<int, Room|Product>>  $groups */
    private function groupsForPicker(Collection $groups): array
    {
        return $groups->map(fn (Collection $items, $label) => [
            'label' => (string) $label,
            'items' => $items->map(fn ($item) => ['id' => $item->id, 'name' => $item->name])->values()->all(),
        ])->values()->all();
    }

    public function update(Request $request, int $id): RedirectResponse
    {
        $owner = TenantContext::user();
        $coupon = Coupon::where('owner_id', $owner->id)->findOrFail($id);
        $validated = $this->validated($request, $owner, $coupon->id);

        $this->persist($coupon, $validated, $request);

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

    /** @return array<string, mixed> */
    private function validated(Request $request, Owner $owner, ?int $id = null): array
    {
        $request->merge(['code' => $this->coupons->normalizeCode($request->input('code'))]);

        $discountType = $request->input('discount_type');

        return $request->validate([
            'code' => [
                'required', 'string', 'max:32', 'regex:/^[A-Z0-9_-]+$/',
                Rule::unique('coupons', 'code')->where('owner_id', $owner->id)->ignore($id),
            ],
            'discount_type' => 'required|in:'.Coupon::TYPE_PERCENTAGE.','.Coupon::TYPE_FIXED,
            'discount_value' => $discountType === Coupon::TYPE_PERCENTAGE
                ? 'required|numeric|gt:0|max:100'
                : 'required|numeric|gt:0|max:999999.99',
            'applies_to' => 'required|in:'.Coupon::SCOPE_ROOMS.','.Coupon::SCOPE_PRODUCTS.','.Coupon::SCOPE_BOTH,
            'room_scope' => 'required_if:applies_to,'.Coupon::SCOPE_ROOMS.','.Coupon::SCOPE_BOTH.'|in:all,specific',
            'product_scope' => 'required_if:applies_to,'.Coupon::SCOPE_PRODUCTS.','.Coupon::SCOPE_BOTH.'|in:all,specific',
            'room_ids' => 'required_if:room_scope,specific|array',
            'room_ids.*' => ['integer', 'distinct', Rule::exists('rooms', 'id')->where('owner_id', $owner->id)],
            'product_ids' => 'required_if:product_scope,specific|array',
            'product_ids.*' => ['integer', 'distinct', Rule::exists('products', 'id')->where('owner_id', $owner->id)],
            'starts_at' => 'nullable|date',
            'expires_at' => 'nullable|date|after_or_equal:starts_at',
            'usage_limit' => 'nullable|integer|min:0',
            'per_customer_limit' => 'nullable|integer|min:0',
            'minimum_spend' => 'nullable|numeric|min:0',
        ]);
    }

    private function persist(Coupon $coupon, array $validated, Request $request): void
    {
        $coupon->fill([
            'code' => $validated['code'],
            'discount_type' => $validated['discount_type'],
            'discount_value' => $validated['discount_value'],
            'applies_to' => $validated['applies_to'],
            'starts_at' => $validated['starts_at'] ?? null,
            'expires_at' => $validated['expires_at'] ?? null,
            'usage_limit' => $validated['usage_limit'] ?? null,
            'per_customer_limit' => $validated['per_customer_limit'] ?? null,
            'minimum_spend' => $validated['minimum_spend'] ?? null,
            'is_active' => $request->boolean('is_active'),
        ]);
        $coupon->save();

        $roomIds = $coupon->appliesTo(Coupon::SCOPE_ROOMS) && ($validated['room_scope'] ?? null) === 'specific'
            ? ($validated['room_ids'] ?? [])
            : [];
        $productIds = $coupon->appliesTo(Coupon::SCOPE_PRODUCTS) && ($validated['product_scope'] ?? null) === 'specific'
            ? ($validated['product_ids'] ?? [])
            : [];

        $coupon->rooms()->sync($roomIds);
        $coupon->products()->sync($productIds);
    }

    /** @return Collection<string, Collection<int, Room>> */
    private function ownerRoomsGrouped(Owner $owner): Collection
    {
        return Room::where('owner_id', $owner->id)
            ->with('workspace')
            ->orderBy('name')
            ->get()
            ->groupBy(fn (Room $room) => $room->workspace?->name ?? __('app.workspace.rooms'));
    }

    /** @return Collection<string, Collection<int, Product>> */
    private function ownerProductsGrouped(Owner $owner): Collection
    {
        return Product::where('owner_id', $owner->id)
            ->where('is_active', true)
            ->orderBy('name')
            ->get()
            ->groupBy(fn (Product $product) => $product->typeLabel());
    }
}
