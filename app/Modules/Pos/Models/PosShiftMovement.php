<?php

namespace App\Modules\Pos\Models;

use App\Models\User;
use App\Modules\Sales\Models\Order;
use App\Support\Concerns\Auditable;
use App\Support\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * حركة درج نقطة البيع — سجلّ append-only لكل دخول/خروج نقدي أو بطاقة داخل الوردية.
 */
class PosShiftMovement extends Model
{
    use Auditable, HasFactory, HasUuid;

    public const TYPE_OPENING = 'opening';

    public const TYPE_CASH_SALE = 'cash_sale';

    public const TYPE_CARD_SALE = 'card_sale';

    public const TYPE_REFUND = 'refund';

    public const TYPE_PAY_IN = 'pay_in';

    public const TYPE_PAY_OUT = 'pay_out';

    protected $fillable = [
        'pos_shift_id', 'type', 'category', 'amount', 'order_id', 'reference', 'note', 'created_by',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
    ];

    public function shift(): BelongsTo
    {
        return $this->belongsTo(PosShift::class, 'pos_shift_id');
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
