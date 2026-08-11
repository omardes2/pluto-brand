<?php

namespace App\Modules\Pos\Models;

use App\Modules\Catalog\Models\ProductVariant;
use App\Modules\Sales\Models\Order;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * بند إرجاع في نقطة البيع (بفاتورة أو بدون). يُخصم من المبيعات والتكلفة في التقارير.
 */
class PosReturnLine extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = [
        'pos_shift_id', 'order_id', 'variant_id', 'qty', 'unit_price', 'unit_cost', 'created_at',
    ];

    protected $casts = [
        'qty' => 'decimal:3',
        'unit_price' => 'decimal:2',
        'unit_cost' => 'decimal:4',
        'created_at' => 'datetime',
    ];

    public function variant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class);
    }

    public function shift(): BelongsTo
    {
        return $this->belongsTo(PosShift::class, 'pos_shift_id');
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }
}
