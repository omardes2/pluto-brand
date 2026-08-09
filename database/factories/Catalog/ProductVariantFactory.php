<?php

namespace Database\Factories\Catalog;

use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Models\ProductAttributeValue;
use App\Modules\Catalog\Models\ProductVariant;
use Illuminate\Database\Eloquent\Factories\Factory;

class ProductVariantFactory extends Factory
{
    protected $model = ProductVariant::class;

    public function definition(): array
    {
        return [
            'product_id' => Product::factory(),
            'sku' => strtoupper($this->faker->unique()->bothify('VAR-#####')),
            'barcode' => $this->faker->optional()->ean13(),
            'name' => null,
            'is_default' => false,
            'is_active' => true,
        ];
    }

    public function default(): static
    {
        return $this->state(fn () => ['is_default' => true]);
    }

    /**
     * إرفاق قيم سمات بالمتغيّر (للاختبارات).
     *
     * @param  iterable<ProductAttributeValue>  $values
     */
    public function withOptions(iterable $values): static
    {
        return $this->afterCreating(function (ProductVariant $variant) use ($values) {
            foreach ($values as $value) {
                $variant->attributeValues()->attach($value->id, ['attribute_id' => $value->attribute_id]);
            }
        });
    }
}
