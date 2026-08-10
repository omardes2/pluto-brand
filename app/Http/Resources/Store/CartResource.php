<?php

namespace App\Http\Resources\Store;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CartResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->uuid,
            'status' => $this->status,
            'items' => $this->whenLoaded('items', fn () => $this->items->map(function ($i) {
                $product = $i->variant?->product;
                // اسم العرض: اسم المنتج + تسمية الخيار (مقاس/لون) إن وُجدت واختلفت عن اسم المنتج
                // (المتغيّر الافتراضي للأصناف البسيطة يحمل اسم المنتج نفسه — فلا نكرّره).
                $name = $product?->name ?? $i->variant?->sku ?? '—';
                $variantLabel = $i->variant?->name;
                if (! empty($variantLabel) && $variantLabel !== $product?->name) {
                    $name .= ' — '.$variantLabel;
                }

                return [
                    'variant_id' => $i->variant?->uuid,
                    'name' => $name,
                    'sku' => $i->variant?->sku,
                    'barcode' => $i->variant?->barcode,
                    'image' => $product?->primaryImage?->url(),
                    'qty' => $i->qty,
                    'unit_price' => $i->unit_price,
                    'line_total' => round((float) $i->qty * (float) $i->unit_price, 2),
                ];
            })),
            'subtotal' => round($this->subtotal(), 2),
            'item_count' => $this->whenLoaded('items', fn () => $this->items->count()),
        ];
    }
}
