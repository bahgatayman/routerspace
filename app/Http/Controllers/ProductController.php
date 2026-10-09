<?php

namespace App\Http\Controllers;

use App\Exceptions\InsufficientStockException;
use App\Models\InventoryMovement;
use App\Models\Product;
use App\Models\SaleItem;
use App\Services\ActivityLogger;
use App\Services\InventoryService;
use App\Support\TenantContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class ProductController extends Controller
{
    public function __construct(
        private ActivityLogger $activityLogger,
        private InventoryService $inventory,
    ) {}

    public function index(Request $request): Response
    {
        $ownerId = TenantContext::id();
        $stock = in_array($request->get('stock'), ['low', 'out'], true) ? $request->get('stock') : null;

        $products = Product::where('owner_id', $ownerId)
            ->when($stock === 'low', fn ($q) => $this->scopeLow($q))
            ->when($stock === 'out', fn ($q) => $this->scopeOut($q))
            ->orderBy('name')
            ->paginate(15)
            ->withQueryString();

        // Filter chip counts across the whole catalog, not just this page.
        $counts = [
            'all' => Product::where('owner_id', $ownerId)->count(),
            'low' => $this->scopeLow(Product::where('owner_id', $ownerId))->count(),
            'out' => $this->scopeOut(Product::where('owner_id', $ownerId))->count(),
        ];

        return Inertia::render('Products/Index', [
            'products' => $products->through(fn (Product $p) => [
                'id' => $p->id,
                'name' => $p->name,
                'sku' => $p->sku,
                'is_service' => $p->isService(),
                'is_active' => (bool) $p->is_active,
                'status' => $p->stockStatus(),
                'price' => (float) $p->price,
                'purchase_price' => (float) $p->purchase_price,
                'profit' => $p->profitPerUnit(),
                'stock_quantity' => (int) $p->stock_quantity,
            ]),
            'stock' => $stock,
            'counts' => $counts,
        ]);
    }

    /** Product details: pricing, stock metrics, sales-to-date profit and the stock history. */
    public function show(int $id): Response
    {
        $product = $this->ownedProduct($id);

        $movements = $product->movements()->with('sale')->paginate(20);

        // Sold-to-date from completed sales (the same basis as Financials).
        // Profit uses each line's own cost snapshot; lines sold before costs
        // were recorded (unit_cost null) are counted separately, never guessed.
        $lines = SaleItem::query()
            ->join('sales', 'sales.id', '=', 'sale_items.sale_id')
            ->where('sales.owner_id', $product->owner_id)
            ->where('sales.status', 'completed')
            ->where('sale_items.product_id', $product->id);

        $sold = [
            'units' => (int) (clone $lines)->sum('sale_items.quantity'),
            'revenue' => round((float) (clone $lines)->sum('sale_items.line_total'), 2),
            'profit' => round((float) (clone $lines)->whereNotNull('sale_items.unit_cost')
                ->selectRaw('COALESCE(SUM(sale_items.line_total - sale_items.unit_cost * sale_items.quantity), 0) as p')->value('p'), 2),
            'unknown_cost_units' => (int) (clone $lines)->whereNull('sale_items.unit_cost')->sum('sale_items.quantity'),
        ];

        $staff = auth('staff')->user();

        return Inertia::render('Products/Show', [
            'product' => [
                ...$this->productFields($product),
                'status' => $product->stockStatus(),
                'profit' => $product->profitPerUnit(),
                'margin' => $product->marginPercent(),
                'inventory_value' => $product->inventoryValue(),
                'potential_revenue' => $product->potentialRevenue(),
                'potential_profit' => $product->potentialProfit(),
            ],
            'movements' => $movements->through(fn (InventoryMovement $m) => [
                'id' => $m->id,
                'date_short' => $m->created_at->format('M d, H:i'),
                'date_full' => $m->created_at->format('M d, Y H:i'),
                'change' => (int) $m->quantity_change,
                'label' => $m->label(),
                'reason' => $m->reason ? __('app.inventory.reasons.'.$m->reason) : null,
                'note' => $m->note,
                'booking_id' => $m->sale?->booking_id,
                'booking_ref' => $m->sale?->booking_id ? '#'.str_pad((string) $m->sale->booking_id, 4, '0', STR_PAD_LEFT) : null,
                'walk_in' => (bool) $m->sale?->shared_session_id,
                'by' => $m->actorName(),
                'after' => (int) $m->new_quantity,
            ]),
            'sold' => $sold,
            'canManage' => ! $staff || $staff->hasPermission('products.manage'),
            'reasons' => collect(InventoryMovement::REASONS)
                ->map(fn ($r) => ['value' => $r, 'label' => __('app.inventory.reasons.'.$r)])->all(),
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('Products/Form', ['product' => null]);
    }

    public function store(Request $request): RedirectResponse
    {
        if (! TenantContext::user()->canAddMoreProducts()) {
            return back()->withInput()->with('error', __('app.plan_limit.products'));
        }

        [$data, $openingStock] = $this->validateProduct($request);

        $product = Product::create(array_merge($data, [
            'owner_id' => TenantContext::id(),
            'stock_quantity' => 0,
        ]));

        if ($product->tracksStock()) {
            $this->inventory->setInitial($product, $openingStock ?? 0);
        }

        $this->activityLogger->log('product.created', $product, "Added product {$product->name}");

        return redirect('/products')->with('success', __('app.sales.product_created'));
    }

    public function edit(int $id): Response
    {
        $product = $this->ownedProduct($id);

        return Inertia::render('Products/Form', [
            'product' => [...$this->productFields($product), 'tracks_stock' => $product->tracksStock()],
        ]);
    }

    public function update(Request $request, int $id): RedirectResponse
    {
        $product = $this->ownedProduct($id);
        $wasTracked = $product->tracksStock();

        [$data, $openingStock] = $this->validateProduct($request);

        // Stock itself is never edited here — only through Restock/Adjust, so
        // the history always explains the count. The one exception is the
        // opening count when tracking is switched on.
        $product->update($data);

        if (! $wasTracked && $product->tracksStock()) {
            $this->inventory->setInitial($product, $openingStock ?? (int) $product->stock_quantity);
        } elseif ($product->tracksStock()) {
            $this->inventory->checkAlerts($product); // a new threshold may put it in (or out of) "low"
        }

        $this->activityLogger->log('product.updated', $product, "Updated product {$product->name}");

        return redirect('/products')->with('success', __('app.sales.product_updated'));
    }

    /** Restock (+) or remove with a reason (−). Never below zero. */
    public function adjustStock(Request $request, int $id): RedirectResponse
    {
        $product = $this->ownedProduct($id);

        if (! $product->tracksStock()) {
            return back()->with('error', __('app.inventory.errors.not_tracked'));
        }

        $validated = $request->validate([
            'direction' => 'required|in:restock,remove',
            'quantity' => 'required|integer|min:1|max:1000000',
            'reason' => ['nullable', 'required_if:direction,remove', Rule::in(InventoryMovement::REASONS)],
            'note' => 'nullable|string|max:255',
        ]);

        $qty = (int) $validated['quantity'];
        try {
            $validated['direction'] === 'restock'
                ? $this->inventory->restock($product, $qty, $validated['note'] ?? null)
                : $this->inventory->adjustDown($product, $qty, $validated['reason'], $validated['note'] ?? null);
        } catch (InsufficientStockException $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }

        $sign = $validated['direction'] === 'restock' ? '+' : '−';
        $this->activityLogger->log('product.stock_adjusted', $product, "Stock {$sign}{$qty} for {$product->name}", [
            'direction' => $validated['direction'], 'quantity' => $qty, 'reason' => $validated['reason'] ?? null,
        ]);

        return redirect()->route('products.show', $product)->with('success', __('app.inventory.adjusted', [
            'count' => $product->fresh()->stock_quantity,
        ]));
    }

    public function destroy(int $id): RedirectResponse
    {
        $product = $this->ownedProduct($id);

        $this->activityLogger->log('product.deleted', $product, "Deleted product {$product->name}");

        $product->delete();

        return redirect('/products')->with('success', __('app.sales.product_deleted'));
    }

    public function toggleActive(int $id): RedirectResponse
    {
        $product = $this->ownedProduct($id);

        $product->update(['is_active' => ! $product->is_active]);

        $this->activityLogger->log('product.toggled', $product, "{$product->name} ".($product->is_active ? 'activated' : 'deactivated'));

        return back()->with('success', __('app.sales.product_updated'));
    }

    /** The displayed/editable attributes of a product (explicit — never the model). */
    private function productFields(Product $product): array
    {
        return [
            'id' => $product->id,
            'name' => $product->name,
            'type' => $product->type,
            'is_service' => $product->isService(),
            'sku' => $product->sku,
            'description' => $product->description,
            'price' => (float) $product->price,
            'purchase_price' => (float) $product->purchase_price,
            'is_active' => (bool) $product->is_active,
            'track_stock' => (bool) $product->track_stock,
            'stock_quantity' => (int) $product->stock_quantity,
            'low_stock_threshold' => $product->low_stock_threshold,
        ];
    }

    private function ownedProduct(int $id): Product
    {
        return Product::where('id', $id)
            ->where('owner_id', TenantContext::id())
            ->firstOrFail();
    }

    /** @return array{0: array, 1: ?int} [attributes, opening stock when tracking starts] */
    private function validateProduct(Request $request): array
    {
        $data = $request->validate([
            'name' => 'required|string|max:255',
            'type' => 'required|in:product,service',
            'price' => 'required|numeric|gt:0|max:1000000',
            'purchase_price' => 'nullable|numeric|min:0|max:1000000',
            'sku' => 'nullable|string|max:100',
            'description' => 'nullable|string|max:1000',
            'track_stock' => 'nullable|boolean',
            'stock_quantity' => 'nullable|integer|min:0|max:1000000',
            'low_stock_threshold' => 'nullable|integer|min:0|max:1000000',
        ]);

        // Unchecked checkboxes are absent from the request, so resolve explicitly.
        $data['is_active'] = $request->boolean('is_active');
        $data['purchase_price'] = round((float) ($data['purchase_price'] ?? 0), 2);
        // Services are never stock-tracked.
        $data['track_stock'] = $data['type'] === 'product' && $request->boolean('track_stock');
        $data['low_stock_threshold'] = $data['track_stock'] ? ($data['low_stock_threshold'] ?? null) : null;

        $opening = isset($data['stock_quantity']) ? (int) $data['stock_quantity'] : null;
        unset($data['stock_quantity']);

        return [$data, $opening];
    }

    private function scopeLow(Builder $q): Builder
    {
        return $q->lowStock();
    }

    private function scopeOut(Builder $q): Builder
    {
        return $q->where('track_stock', true)->where('type', 'product')->where('stock_quantity', '<=', 0);
    }
}
