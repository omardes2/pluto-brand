<?php

namespace App\Modules\Store\Services;

use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Models\ProductVariant;
use App\Modules\Foundation\Services\Settings;
use Illuminate\Support\Str;

/**
 * موجز منتجات متوافق مع كتالوج ميتا (Meta Commerce / Facebook) — صيغة CSV.
 * صفّ لكل **متغيّر** نشط (لون/مقاس) مجمّع تحت المنتج الأب عبر item_group_id، ليتوافق
 * مع الإعلانات الديناميكية للملابس والأحذية. يُقرأ عبر رابط عام يسحبه ميتا دوريًا.
 */
class MetaCatalogFeedService
{
    /** ترويسة الأعمدة (بترتيب ثابت). */
    public const HEADER = [
        'id', 'title', 'description', 'availability', 'condition',
        'price', 'sale_price', 'link', 'image_link', 'additional_image_link',
        'brand', 'item_group_id', 'color', 'size',
    ];

    public function __construct(private readonly CartService $carts) {}

    /**
     * صفوف الموجز (مولّد كسول عبر المنتجات الظاهرة ومتغيّراتها النشطة ذات الصورة).
     *
     * @return \Generator<int, array<string, string>>
     */
    public function rows(): \Generator
    {
        $currency = (string) Settings::get('store.currency', 'ILS');
        $storeName = (string) Settings::get('store.name', 'Pluto Brand');

        $products = Product::query()->active()->visible()
            ->with([
                'brand:id,name',
                'primaryImage',
                'images',
                'variants' => fn ($q) => $q->where('is_active', true)->with('attributeValues.attribute'),
            ])
            ->orderBy('id')
            ->lazy(200);

        foreach ($products as $product) {
            $image = $this->abs(($product->primaryImage ?: $product->images->first())?->url() ?? '');
            if ($image === '') {
                continue; // ميتا يتطلّب image_link — نتخطّى منتجًا بلا صورة.
            }

            $extra = $product->images
                ->reject(fn ($img) => $product->primaryImage && $img->id === $product->primaryImage->id)
                ->map(fn ($img) => $this->abs($img->url()))
                ->filter()->take(10)->implode(',');

            $brand = $product->brand?->name ?: $storeName;
            $link = route('storefront.product', $product->slug);
            $description = $this->description($product);

            foreach ($product->variants as $variant) {
                yield $this->row($product, $variant, $currency, $image, $extra, $brand, $link, $description);
            }
        }
    }

    /**
     * @return array<string, string>
     */
    private function row(Product $product, ProductVariant $variant, string $currency, string $image, string $extra, string $brand, string $link, string $description): array
    {
        $selling = round($this->carts->sellingPrice($variant), 2);
        $regular = round((float) $variant->retail_price, 2);
        $available = $this->carts->availableQty($variant);
        $onSale = $selling + 1e-9 < $regular;

        [$color, $size] = $this->colorSize($variant);

        return [
            'id' => (string) ($variant->sku ?: $variant->uuid),
            'title' => Str::limit($this->variantTitle($product, $variant), 150, ''),
            'description' => $description,
            'availability' => $available > 1e-9 ? 'in stock' : 'out of stock',
            'condition' => 'new',
            // price = السعر الأساسي؛ sale_price = سعر العرض إن وُجد.
            'price' => number_format($onSale ? $regular : $selling, 2, '.', '').' '.$currency,
            'sale_price' => $onSale ? number_format($selling, 2, '.', '').' '.$currency : '',
            'link' => $link,
            'image_link' => $image,
            'additional_image_link' => $extra,
            'brand' => $brand,
            'item_group_id' => (string) $product->uuid,
            'color' => $color,
            'size' => $size,
        ];
    }

    /** اسم الصنف: اسم المنتج + تسمية المتغيّر إن اختلفت (المتغيّر الافتراضي يحمل الاسم نفسه). */
    private function variantTitle(Product $product, ProductVariant $variant): string
    {
        $name = $product->name;
        if (filled($variant->name) && $variant->name !== $product->name) {
            $name .= ' - '.$variant->name;
        }

        return $name;
    }

    /** يفصل قيم المتغيّر إلى (لون، مقاس): محور النوع color للّون، وبقية المحاور تُجمَع كمقاس. */
    private function colorSize(ProductVariant $variant): array
    {
        $color = null;
        $sizes = [];
        foreach ($variant->attributeValues as $val) {
            $label = $val->label ?: $val->value;
            if ($color === null && ($val->attribute?->type === 'color' || filled($val->color_hex))) {
                $color = $label;
            } else {
                $sizes[] = $label;
            }
        }

        return [(string) ($color ?? ''), implode(' / ', $sizes)];
    }

    /** وصف نصّي نظيف (بلا وسوم)، بالتراجُع إلى الوصف المختصر ثم اسم المنتج. */
    private function description(Product $product): string
    {
        $text = trim(strip_tags((string) ($product->description ?: $product->short_description ?: $product->name)));
        $text = (string) preg_replace('/\s+/u', ' ', $text);

        return Str::limit($text !== '' ? $text : $product->name, 5000, '');
    }

    /** يجعل رابط الصورة مطلقًا (ميتا يتطلّب روابط مطلقة). */
    private function abs(string $url): string
    {
        if ($url === '' || str_starts_with($url, 'http://') || str_starts_with($url, 'https://')) {
            return $url;
        }

        return url($url);
    }
}
