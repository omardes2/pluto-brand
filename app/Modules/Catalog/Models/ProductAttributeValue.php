<?php

namespace App\Modules\Catalog\Models;

use Database\Factories\Catalog\ProductAttributeValueFactory;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * قيمة سمة (PHASE_2_DESIGN §19). فرادة مركّبة (attribute_id, value).
 */
class ProductAttributeValue extends Model
{
    use HasFactory;

    protected $fillable = ['attribute_id', 'value', 'label', 'color_hex', 'sort_order', 'is_active'];

    protected $casts = [
        'sort_order' => 'integer',
        'is_active' => 'boolean',
    ];

    public function attribute(): BelongsTo
    {
        return $this->belongsTo(ProductAttribute::class, 'attribute_id');
    }

    /** المتغيّرات التي تحمل هذه القيمة. */
    public function variants(): BelongsToMany
    {
        return $this->belongsToMany(
            ProductVariant::class,
            'variant_attribute_values',
            'attribute_value_id',
            'variant_id'
        )->withTimestamps();
    }

    protected static function newFactory(): Factory
    {
        return ProductAttributeValueFactory::new();
    }
}
