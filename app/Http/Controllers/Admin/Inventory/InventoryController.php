<?php

namespace App\Http\Controllers\Admin\Inventory;

use App\Http\Controllers\Controller;
use App\Http\Requests\Inventory\IssueStockRequest;
use App\Http\Requests\Inventory\ReceiveStockRequest;
use App\Http\Requests\Inventory\TransferStockRequest;
use App\Modules\Catalog\Models\Category;
use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Models\ProductVariant;
use App\Modules\Catalog\Services\ProductService;
use App\Modules\Foundation\Models\Warehouse;
use App\Modules\Inventory\Models\InventoryLedger;
use App\Modules\Inventory\Models\InventoryMovement;
use App\Modules\Inventory\Models\InventoryStock;
use App\Modules\Inventory\Models\StockReservation;
use App\Modules\Inventory\Services\InventoryService;
use App\Modules\Inventory\Services\ReservationService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class InventoryController extends Controller
{
    public function __construct(
        private readonly InventoryService $inventory,
        private readonly ReservationService $reservations,
        private readonly ProductService $products,
    ) {}

    /** صفحة «المخزن»: عرض الأصناف بالأسعار والكمية المتوفرة (متمركزة حول المنتج). */
    public function stocks(Request $request): View
    {
        $this->authorize('inventory.stocks.view');

        $products = Product::query()->with('category:id,name')
            ->withSum('stocks as on_hand_sum', 'on_hand')
            ->withSum('stocks as reserved_sum', 'reserved')
            ->when($request->filled('search'), function ($q) use ($request) {
                $term = '%'.$request->string('search').'%';
                $q->where(fn ($w) => $w->where('name', 'like', $term)->orWhere('sku', 'like', $term));
            })
            ->when($request->filled('category'), fn ($q) => $q->where('category_id', $request->integer('category')))
            ->orderBy('name')->paginate(20)->withQueryString();

        return view('admin.inventory.stocks', [
            'products' => $products,
            'categories' => Category::orderBy('name')->get(['id', 'name']),
            'search' => $request->input('search'),
            'activeCategory' => $request->integer('category') ?: null,
        ]);
    }

    /** كرت الصنف: بياناته + سجل المخزن (كل حركات المخزون على الصنف). */
    public function showProduct(Product $product): View
    {
        $this->authorize('inventory.stocks.view');

        $variantIds = $product->variants()->pluck('id');

        $ledger = InventoryLedger::query()
            ->whereIn('variant_id', $variantIds)
            ->with(['warehouse:id,name', 'movement:id,reason,reference_type,reference_id'])
            ->latest('id')->paginate(20);

        $onHand = (float) InventoryStock::whereIn('variant_id', $variantIds)->sum('on_hand');
        $reserved = (float) InventoryStock::whereIn('variant_id', $variantIds)->sum('reserved');

        return view('admin.inventory.product-ledger', [
            'product' => $product->load('category:id,name'),
            'ledger' => $ledger,
            'available' => $onHand - $reserved,
            'onHand' => $onHand,
        ]);
    }

    /** صفحة تعديل سريع لصنف من المخزن: الاسم، الفئة، الأسعار، الكمية المتوفرة، الباركود. */
    public function editProduct(Product $product): View
    {
        $this->authorize('update', $product);

        $variant = $product->defaultVariant()->first();
        $warehouse = $this->defaultWarehouse();

        // المتغيّرات النشطة (مقاس/لون) — تُعرض كميّاتها للتعديل المباشر عند وجود أكثر من واحد.
        $variants = $product->variants()->where('is_active', true)
            ->with('attributeValues.attribute')->get();
        $hasVariants = $variants->count() > 1;

        // الكمية المتوفّرة لكل متغيّر على المستودع الافتراضي (لعرضها في جدول الكميات).
        $stockByVariant = $warehouse
            ? InventoryStock::whereIn('variant_id', $variants->pluck('id'))
                ->where('warehouse_id', $warehouse->id)->pluck('on_hand', 'variant_id')
            : collect();

        $quantity = (float) $product->stocks()->sum('on_hand');

        return view('admin.inventory.product-edit', [
            'product' => $product,
            'categories' => Category::orderBy('name')->get(['id', 'name']),
            'variant' => $variant,
            'quantity' => $quantity,
            'hasVariants' => $hasVariants,
            'variants' => $variants,
            'stockByVariant' => $stockByVariant,
        ]);
    }

    public function updateProduct(Request $request, Product $product): RedirectResponse
    {
        $this->authorize('update', $product);

        $variant = $product->defaultVariant()->first();

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'category_id' => ['nullable', 'integer', 'exists:categories,id'],
            'cost_price' => ['nullable', 'numeric', 'min:0'],
            'retail_price' => ['nullable', 'numeric', 'min:0'],
            'wholesale_price' => ['nullable', 'numeric', 'min:0'],
            'quantity' => ['nullable', 'numeric', 'min:0'],
            'variant_qty' => ['nullable', 'array'],
            'variant_qty.*' => ['nullable', 'numeric', 'min:0'],
            'barcode' => ['nullable', 'string', 'max:64',
                $variant ? 'unique:product_variants,barcode,'.$variant->id : 'unique:product_variants,barcode'],
        ]);

        // الاسم + الفئة + الأسعار (تُزامَن الأسعار تلقائيًا مع المتغيّر الافتراضي داخل الخدمة).
        $this->products->update($product, [
            'name' => $data['name'],
            'category_id' => $data['category_id'] ?? null,
            'cost_price' => $data['cost_price'] ?? null,
            'retail_price' => $data['retail_price'] ?? null,
            'wholesale_price' => $data['wholesale_price'] ?? null,
        ]);

        if ($variant) {
            $variant->update(['barcode' => $data['barcode'] ?? null]);
        }

        $warehouse = $this->defaultWarehouse();
        $hasVariants = $product->variants()->count() > 1;

        if ($warehouse) {
            if ($hasVariants) {
                // أصناف المقاسات/الألوان: تُضبط كمية كل متغيّر على حدة من جدول الكميات.
                $this->syncVariantQuantities($product, $warehouse, (array) ($data['variant_qty'] ?? []), $data['cost_price'] ?? null);

                // «إجمالي الكمية» حقل حرّ: إن اختلف عن مجموع الصفوف، يُضاف/يُخصم الفرق على
                // المتغيّر الافتراضي فقط (التعديل عبر الصفوف يُبقي الإجمالي مطابقًا فلا فرق).
                if ($variant && ($data['quantity'] ?? null) !== null) {
                    $sum = (float) InventoryStock::whereIn('variant_id', $product->variants()->pluck('id'))
                        ->where('warehouse_id', $warehouse->id)->sum('on_hand');
                    $diff = round((float) $data['quantity'] - $sum, 2);
                    if (abs($diff) >= 0.001) {
                        $defaultOnHand = (float) InventoryStock::where('variant_id', $variant->id)
                            ->where('warehouse_id', $warehouse->id)->value('on_hand');
                        $this->adjustVariantTo($variant, $warehouse, max(0.0, $defaultOnHand + $diff), $product->sku, $data['cost_price'] ?? null);
                    }
                }
            } elseif ($variant && ($data['quantity'] ?? null) !== null) {
                // صنف بسيط (متغيّر واحد): حقل كمية واحد.
                $this->adjustVariantTo($variant, $warehouse, (float) $data['quantity'], $product->sku, $data['cost_price'] ?? null);
            }
        }

        return redirect()->route('admin.inventory.stocks')->with('success', __('حُدّث الصنف.'));
    }

    /** المستودع الافتراضي (النظام أحادي المستودع حاليًا). */
    private function defaultWarehouse(): ?Warehouse
    {
        return Warehouse::where('is_default', true)->first() ?? Warehouse::orderBy('id')->first();
    }

    /**
     * ضبط كميّات متغيّرات المنتج (مقاس/لون) على المستودع الافتراضي عبر تسويات مخزنية.
     * تُقبَل فقط المتغيّرات النشطة التابعة لهذا المنتج (حماية من العبث بالمعرّفات).
     *
     * @param  array<int|string, mixed>  $quantities  variant_id => الكمية الجديدة
     */
    private function syncVariantQuantities(Product $product, Warehouse $warehouse, array $quantities, ?float $cost): void
    {
        if ($quantities === []) {
            return;
        }

        $allowed = $product->variants()->where('is_active', true)->pluck('id')->all();

        foreach ($quantities as $variantId => $qty) {
            if ($qty === null || $qty === '' || ! in_array((int) $variantId, $allowed, true)) {
                continue;
            }
            $variant = ProductVariant::find((int) $variantId);
            if ($variant) {
                $this->adjustVariantTo($variant, $warehouse, (float) $qty, $product->sku, $cost);
            }
        }
    }

    /** يضبط رصيد متغيّر إلى كمية مستهدفة عبر تسوية دخول/خروج (تظهر في سجل المخزن). */
    private function adjustVariantTo(ProductVariant $variant, Warehouse $warehouse, float $target, ?string $sku, ?float $cost): void
    {
        $current = (float) InventoryStock::where('variant_id', $variant->id)
            ->where('warehouse_id', $warehouse->id)->value('on_hand');
        $delta = round($target - $current, 2);
        if (abs($delta) < 0.001) {
            return;
        }

        $opts = ['reason' => 'inventory_edit:'.$sku];
        $delta > 0
            ? $this->inventory->adjustIn($variant, $warehouse, $delta, $cost, $opts)
            : $this->inventory->adjustOut($variant, $warehouse, -$delta, $opts);
    }

    public function movements(Request $request): View
    {
        $this->authorize('inventory.movements.view');

        $movements = InventoryMovement::query()->with(['variant', 'warehouse'])
            ->when($request->filled('type'), fn ($q) => $q->where('type', $request->string('type')))
            ->latest('id')->paginate(20)->withQueryString();

        return view('admin.inventory.movements', compact('movements'));
    }

    public function reservations(Request $request): View
    {
        $this->authorize('viewAny', StockReservation::class);

        $reservations = StockReservation::query()->with(['variant', 'warehouse'])
            ->latest('id')->paginate(20);

        return view('admin.inventory.reservations', compact('reservations'));
    }

    public function releaseReservation(StockReservation $reservation): RedirectResponse
    {
        $this->authorize('release', $reservation);

        try {
            $this->reservations->release($reservation);
        } catch (ValidationException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', __('تم تحرير الحجز.'));
    }

    public function operations(): View
    {
        $this->authorize('inventory.stocks.view');

        return view('admin.inventory.operations', [
            'products' => Product::with('defaultVariant')->orderBy('name')->get(),
            'warehouses' => Warehouse::orderBy('name')->get(),
        ]);
    }

    public function receive(ReceiveStockRequest $request): RedirectResponse
    {
        $this->authorize('inventory.operations.receive');
        [$variant, $warehouse] = $this->resolve($request);
        $this->inventory->receive($variant, $warehouse, (float) $request->validated('qty'), (float) $request->validated('unit_cost'), $request->only(['reason', 'note']));

        return back()->with('success', __('تم استلام المخزون.'));
    }

    public function issue(IssueStockRequest $request): RedirectResponse
    {
        $this->authorize('inventory.operations.issue');
        [$variant, $warehouse] = $this->resolve($request);

        try {
            $this->inventory->issue($variant, $warehouse, (float) $request->validated('qty'), $request->only(['reason', 'note']));
        } catch (ValidationException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', __('تم صرف المخزون.'));
    }

    public function transfer(TransferStockRequest $request): RedirectResponse
    {
        $this->authorize('inventory.operations.transfer');
        $variant = ProductVariant::where('uuid', $request->validated('variant'))->firstOrFail();
        $from = Warehouse::where('uuid', $request->validated('from_warehouse'))->firstOrFail();
        $to = Warehouse::where('uuid', $request->validated('to_warehouse'))->firstOrFail();

        try {
            $this->inventory->transfer($variant, $from, $to, (float) $request->validated('qty'), $request->only(['reason', 'note']));
        } catch (ValidationException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', __('تم التحويل بين المستودعين.'));
    }

    private function resolve($request): array
    {
        return [
            ProductVariant::where('uuid', $request->validated('variant'))->firstOrFail(),
            Warehouse::where('uuid', $request->validated('warehouse'))->firstOrFail(),
        ];
    }
}
