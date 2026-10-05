<?php

namespace App\Services;

use App\Exceptions\InsufficientStockException;
use App\Models\InventoryMovement;
use App\Models\Product;
use App\Models\SaleItem;
use Illuminate\Support\Str;

/**
 * The only place a product's stock changes, and every change leaves an
 * InventoryMovement row (previous → new) so the current count is explainable.
 *
 * Stock leaves the shelf when a product is added to a bill or a session tab
 * (SalesService::addItem) and comes back if that line is removed. Untracked
 * products (tracking off, or services) are never touched — they sell as before.
 *
 * Concurrency: reductions are a single guarded UPDATE
 *   … SET stock_quantity = stock_quantity - q WHERE id = ? AND stock_quantity >= q
 * — the same "atomic claim" pattern SharedSessionController::close() uses, and
 * safe on SQLite (which ignores lockForUpdate) as well as MySQL. Two requests
 * racing for the last units can never both succeed.
 *
 * Alerts: one Low Stock notification when stock first reaches the threshold and
 * one Out of Stock at 0 (products.stock_alert remembers what was sent); rising
 * back above the threshold re-arms the cycle.
 */
class InventoryService
{
    public function __construct(private NotificationService $notifications) {}

    /** A sale line took $qty units. Throws InsufficientStockException (caller's transaction rolls back). */
    public function take(Product $product, int $qty, SaleItem $item): void
    {
        if (! $product->tracksStock() || $qty <= 0) {
            return;
        }

        $new = $this->guardedDecrement($product, $qty);
        $this->record($product, InventoryMovement::SALE, -$qty, $new + $qty, $new, [
            'sale_id' => $item->sale_id, 'sale_item_id' => $item->id,
        ]);
        $this->checkAlerts($product);
    }

    /**
     * A sale line was removed from a bill: its units go back on the shelf —
     * but only if that line actually took stock (lines added before tracking
     * was switched on never did, so they must not inflate the count).
     */
    public function giveBack(SaleItem $item): void
    {
        $product = $item->product;
        if (! $product || ! $product->tracksStock()) {
            return;
        }
        $tookStock = InventoryMovement::where('sale_item_id', $item->id)->where('type', InventoryMovement::SALE)->exists();
        if (! $tookStock) {
            return;
        }

        $new = $this->increment($product, $item->quantity);
        $this->record($product, InventoryMovement::SALE_REMOVED, $item->quantity, $new - $item->quantity, $new, [
            'sale_id' => $item->sale_id,
        ]);
        $this->checkAlerts($product);
    }

    /**
     * A sale line's quantity changed in place (not a full add/remove):
     * $delta is signed, +N takes N more units, -N gives N back. Mirrors
     * take()'s guarded decrement for a positive delta and giveBack()'s
     * "only restore stock this line actually took" safety check for a
     * negative one, so a line added while tracking was off never wrongly
     * inflates stock on a later decrease.
     */
    public function adjustSaleItemQuantity(SaleItem $item, int $delta): void
    {
        $product = $item->product;
        if (! $product || ! $product->tracksStock() || $delta === 0) {
            return;
        }

        if ($delta > 0) {
            $new = $this->guardedDecrement($product, $delta);
            $this->record($product, InventoryMovement::SALE, -$delta, $new + $delta, $new, [
                'sale_id' => $item->sale_id, 'sale_item_id' => $item->id,
            ]);
        } else {
            $tookStock = InventoryMovement::where('sale_item_id', $item->id)->where('type', InventoryMovement::SALE)->exists();
            if (! $tookStock) {
                return;
            }

            $qty = -$delta;
            $new = $this->increment($product, $qty);
            $this->record($product, InventoryMovement::SALE_REMOVED, $qty, $new - $qty, $new, [
                'sale_id' => $item->sale_id, 'sale_item_id' => $item->id,
            ]);
        }

        $this->checkAlerts($product);
    }

    /** Owner restock: +$qty. */
    public function restock(Product $product, int $qty, ?string $note = null): InventoryMovement
    {
        $new = $this->increment($product, $qty);
        $movement = $this->record($product, InventoryMovement::RESTOCK, $qty, $new - $qty, $new, ['note' => $note]);
        $this->checkAlerts($product);

        return $movement;
    }

    /** Owner reduction (damage, loss, correction…): −$qty with a reason; never below 0. */
    public function adjustDown(Product $product, int $qty, string $reason, ?string $note = null): InventoryMovement
    {
        $new = $this->guardedDecrement($product, $qty);
        $movement = $this->record($product, InventoryMovement::ADJUSTMENT, -$qty, $new + $qty, $new, [
            'reason' => $reason, 'note' => $note,
        ]);
        $this->checkAlerts($product);

        return $movement;
    }

    /** Opening count when tracking starts (product created with stock, or tracking switched on). */
    public function setInitial(Product $product, int $qty): InventoryMovement
    {
        $previous = (int) Product::whereKey($product->id)->value('stock_quantity');
        Product::whereKey($product->id)->update(['stock_quantity' => $qty, 'stock_alert' => null]);
        $movement = $this->record($product, InventoryMovement::INITIAL, $qty - $previous, $previous, $qty);
        $this->checkAlerts($product);

        return $movement;
    }

    /**
     * Send at most one Low Stock and one Out of Stock alert per cycle; rising
     * above the threshold (or having none) re-arms it.
     */
    public function checkAlerts(Product $product): void
    {
        $product->refresh();
        if (! $product->tracksStock()) {
            return;
        }

        $stock = (int) $product->stock_quantity;
        $threshold = $product->low_stock_threshold;

        if ($stock <= 0) {
            if ($product->stock_alert !== 'out') {
                $this->alert($product, 'out');
                $product->update(['stock_alert' => 'out']);
            }

            return;
        }

        if ($threshold !== null && $stock <= $threshold) {
            if ($product->stock_alert === null) {
                $this->alert($product, 'low');
                $product->update(['stock_alert' => 'low']);
            } elseif ($product->stock_alert === 'out') {
                // Restocked from 0 but still low: no new Low alert this cycle,
                // but a later return to 0 must be able to alert again.
                $product->update(['stock_alert' => 'low']);
            }

            return;
        }

        if ($product->stock_alert !== null) {
            $product->update(['stock_alert' => null]);
        }
    }

    /** @return int the stock after the decrement */
    private function guardedDecrement(Product $product, int $qty): int
    {
        $affected = Product::whereKey($product->id)
            ->where('owner_id', $product->owner_id)
            ->where('stock_quantity', '>=', $qty)
            ->decrement('stock_quantity', $qty);

        $now = (int) Product::whereKey($product->id)->value('stock_quantity');
        if ($affected === 0) {
            throw new InsufficientStockException($product, max(0, $now));
        }

        return $now;
    }

    /** @return int the stock after the increment */
    private function increment(Product $product, int $qty): int
    {
        Product::whereKey($product->id)->where('owner_id', $product->owner_id)->increment('stock_quantity', $qty);

        return (int) Product::whereKey($product->id)->value('stock_quantity');
    }

    private function record(Product $product, string $type, int $change, int $previous, int $new, array $extra = []): InventoryMovement
    {
        [$actorType, $actorId] = $this->actor();

        return InventoryMovement::create(array_merge([
            'owner_id' => $product->owner_id,
            'product_id' => $product->id,
            'type' => $type,
            'quantity_change' => $change,
            'previous_quantity' => $previous,
            'new_quantity' => $new,
            'created_by_type' => $actorType,
            'created_by_id' => $actorId,
        ], $extra));
    }

    private function alert(Product $product, string $kind): void
    {
        $stock = (int) $product->stock_quantity;

        $this->notifications->notify($product->owner, [
            'type' => $kind === 'out' ? 'stock_out' : 'stock_low',
            'level' => $kind === 'out' ? 'danger' : 'warning',
            'title' => __('app.inventory.notify.'.$kind.'_title'),
            'body' => $kind === 'out'
                ? __('app.inventory.notify.out_body', ['name' => $product->name])
                : trans_choice('app.inventory.notify.low_body', $stock, ['name' => $product->name, 'count' => $stock]),
            'action_url' => '/products/'.$product->id,
            // Unique per alert; repetition within a cycle is prevented by products.stock_alert.
            'reference' => 'stock:'.$kind.':'.$product->id.':'.Str::ulid(),
        ]);
    }

    /** @return array{0: ?string, 1: ?int} */
    private function actor(): array
    {
        if ($staff = auth('staff')->user()) {
            return ['staff', $staff->id];
        }
        if ($owner = auth('owner')->user()) {
            return ['owner', $owner->id];
        }

        return [null, null];
    }
}
