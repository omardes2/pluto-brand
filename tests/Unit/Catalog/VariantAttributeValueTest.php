<?php

namespace Tests\Unit\Catalog;

use App\Modules\Catalog\Models\ProductAttribute;
use App\Modules\Catalog\Models\ProductAttributeValue;
use App\Modules\Catalog\Models\ProductVariant;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class VariantAttributeValueTest extends TestCase
{
    use RefreshDatabase;

    private function value(ProductAttribute $attr, string $value, ?string $label = null, int $sort = 0): ProductAttributeValue
    {
        return ProductAttributeValue::factory()->create([
            'attribute_id' => $attr->id,
            'value' => $value,
            'label' => $label,
            'sort_order' => $sort,
        ]);
    }

    public function test_variant_links_to_attribute_values(): void
    {
        $size = ProductAttribute::factory()->create(['name' => 'المقاس', 'sort_order' => 1]);
        $l = $this->value($size, 'L', 'L');

        $variant = ProductVariant::factory()->withOptions([$l])->create();

        $this->assertDatabaseHas('variant_attribute_values', [
            'variant_id' => $variant->id,
            'attribute_id' => $size->id,
            'attribute_value_id' => $l->id,
        ]);
        $this->assertTrue($variant->attributeValues->contains($l));
    }

    public function test_one_value_per_attribute_axis_is_enforced(): void
    {
        $size = ProductAttribute::factory()->create(['name' => 'المقاس']);
        $s = $this->value($size, 'S', 'S');
        $m = $this->value($size, 'M', 'M');

        $variant = ProductVariant::factory()->create();
        $variant->attributeValues()->attach($s->id, ['attribute_id' => $size->id]);

        // قيمة ثانية لنفس المحور (السمة) لنفس المتغيّر يجب أن تُرفض (unique).
        $this->expectException(QueryException::class);
        $variant->attributeValues()->attach($m->id, ['attribute_id' => $size->id]);
    }

    public function test_option_label_orders_by_attribute_then_value(): void
    {
        $size = ProductAttribute::factory()->create(['name' => 'المقاس', 'sort_order' => 1]);
        $color = ProductAttribute::factory()->create(['name' => 'اللون', 'sort_order' => 2]);
        $l = $this->value($size, 'L', 'L');
        $black = $this->value($color, 'black', 'أسود');

        // نُرفق اللون أولًا للتأكّد أن الترتيب يعتمد على sort_order لا ترتيب الإرفاق.
        $variant = ProductVariant::factory()->withOptions([$black, $l])->create();
        $variant->load('attributeValues.attribute');

        $this->assertSame('L / أسود', $variant->optionLabel());
    }

    public function test_deleting_variant_cascades_pivot_rows(): void
    {
        $size = ProductAttribute::factory()->create();
        $l = $this->value($size, 'L', 'L');
        $variant = ProductVariant::factory()->withOptions([$l])->create();

        $variant->forceDelete();

        $this->assertDatabaseMissing('variant_attribute_values', [
            'attribute_value_id' => $l->id,
        ]);
    }
}
