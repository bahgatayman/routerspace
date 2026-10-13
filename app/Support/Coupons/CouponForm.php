<?php

namespace App\Support\Coupons;

use App\Models\Coupon;
use App\Models\Owner;
use App\Models\Product;
use App\Models\Room;
use App\Services\CouponService;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;

/**
 * The single create/edit path for a coupon — validation, saving and the
 * room/product pickers — shared by the owner Coupons screen and the Super
 * Admin coupon management, so both enforce exactly the same rules. Every
 * lookup is scoped to the coupon's own business ($owner): codes are unique per
 * business, and targeted rooms/products must belong to that business.
 */
class CouponForm
{
    public function __construct(private CouponService $coupons) {}

    /** @return array<string, mixed> */
    public function validate(Request $request, Owner $owner, ?int $ignoreId = null): array
    {
        $request->merge(['code' => $this->coupons->normalizeCode($request->input('code'))]);

        $discountType = $request->input('discount_type');

        return $request->validate([
            'code' => [
                'required', 'string', 'max:32', 'regex:/^[A-Z0-9_-]+$/',
                Rule::unique('coupons', 'code')->where('owner_id', $owner->id)->ignore($ignoreId),
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

    /** @param array<string, mixed> $validated */
    public function save(Coupon $coupon, array $validated, Request $request): void
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
    public function roomsGrouped(Owner $owner): Collection
    {
        return Room::where('owner_id', $owner->id)
            ->with('workspace')
            ->orderBy('name')
            ->get()
            ->groupBy(fn (Room $room) => $room->workspace?->name ?? __('app.workspace.rooms'));
    }

    /** @return Collection<string, Collection<int, Product>> */
    public function productsGrouped(Owner $owner): Collection
    {
        return Product::where('owner_id', $owner->id)
            ->where('is_active', true)
            ->orderBy('name')
            ->get()
            ->groupBy(fn (Product $product) => $product->typeLabel());
    }

    /** A fresh coupon with the form's defaults (both, percentage, active). */
    public function blank(?Owner $owner = null): Coupon
    {
        return new Coupon(['owner_id' => $owner?->id, 'applies_to' => Coupon::SCOPE_BOTH, 'discount_type' => Coupon::TYPE_PERCENTAGE, 'is_active' => true]);
    }
}
