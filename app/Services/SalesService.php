<?php

namespace App\Services;

use App\Models\Booking;
use App\Models\Product;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\SharedSession;
use Illuminate\Support\Facades\DB;

/**
 * Sales math + line-item management. Totals are always computed server-side
 * from the persisted line items — never trusted from the client. Stock for
 * tracked products moves through InventoryService in the same transaction.
 */
class SalesService
{
    public function __construct(private InventoryService $inventory) {}

    /** The sale attached to a booking, creating it on first use. */
    public function saleForBooking(Booking $booking): Sale
    {
        // Looked up fresh (not the possibly-stale loaded relation): one sale
        // per booking, even when two "add product" requests race.
        return Sale::firstOrCreate(['booking_id' => $booking->id, 'owner_id' => $booking->owner_id], [
            'hotspot_user_id' => $booking->hotspot_user_id,
            'status' => 'completed',
            'sold_at' => now(),
        ]);
    }

    /**
     * The open "tab" sale attached to a shared session, creating it on first use.
     * Stays status=open (and unpriced) until the session is closed.
     */
    public function saleForSharedSession(SharedSession $session): Sale
    {
        // Fresh lookup, one tab per session (see saleForBooking()).
        return Sale::firstOrCreate(['shared_session_id' => $session->id, 'owner_id' => $session->owner_id], [
            'hotspot_user_id' => $session->hotspot_user_id,
            'status' => 'open',
        ]);
    }

    /**
     * Move an open session's tab onto the booking created at close: keep the same
     * line items, drop the session link, and realize the sale as completed.
     */
    public function transferToBooking(Sale $sale, Booking $booking): void
    {
        $sale->update([
            'booking_id' => $booking->id,
            'shared_session_id' => null,
            'status' => 'completed',
            'sold_at' => now(),
        ]);
    }

    /**
     * Add a product to a sale as a snapshotted line item, then recompute totals.
     * name/unit_price/line_total are frozen here so later catalog edits never
     * rewrite this sale.
     */
    public function addItem(Sale $sale, Product $product, int $quantity): SaleItem
    {
        $quantity = max(1, $quantity);

        // One transaction: if the product's stock can't cover the quantity,
        // InventoryService throws InsufficientStockException and the line is
        // never written. unit_cost freezes today's purchase price so later
        // cost changes never rewrite this sale's profit.
        return DB::transaction(function () use ($sale, $product, $quantity) {
            $item = $sale->items()->create([
                'product_id' => $product->id,
                'name' => $product->name,
                'unit_price' => $product->price,
                'unit_cost' => $product->purchase_price,
                'quantity' => $quantity,
                'line_total' => round((float) $product->price * $quantity, 2),
            ]);

            $this->inventory->take($product, $quantity, $item);
            $this->recalculate($sale);

            return $item;
        });
    }

    /** Remove a line item (its units go back in stock) and recompute totals. */
    public function removeItem(SaleItem $item): void
    {
        DB::transaction(function () use ($item) {
            $sale = $item->sale;
            $this->inventory->giveBack($item);
            $item->delete();
            $this->recalculate($sale);
        });
    }

    /**
     * Change an existing line's quantity. 0 or below converges on the exact
     * same removeItem() path — "decrement to zero" and "Remove" must never
     * be two slightly different deletions. line_total is always recomputed
     * from the item's own frozen unit_price, never a client value.
     */
    public function updateItemQuantity(SaleItem $item, int $newQuantity): void
    {
        if ($newQuantity <= 0) {
            $this->removeItem($item);

            return;
        }

        DB::transaction(function () use ($item, $newQuantity) {
            $sale = $item->sale;
            $delta = $newQuantity - $item->quantity;

            $this->inventory->adjustSaleItemQuantity($item, $delta);

            $item->update([
                'quantity' => $newQuantity,
                'line_total' => round((float) $item->unit_price * $newQuantity, 2),
            ]);

            $this->recalculate($sale);
        });
    }

    /** Recompute subtotal/total from the line items and persist. */
    public function recalculate(Sale $sale): void
    {
        $subtotal = (float) $sale->items()->sum('line_total');

        $sale->update([
            'subtotal' => round($subtotal, 2),
            'total' => round($subtotal - (float) $sale->discount_total + (float) $sale->tax_total, 2),
        ]);
    }
}
