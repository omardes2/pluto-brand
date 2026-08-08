<?php

namespace App\Modules\Pos\Models;

use App\Models\User;
use App\Modules\Accounting\Models\Treasury;
use App\Modules\Foundation\Models\Branch;
use App\Modules\Foundation\Models\Warehouse;
use App\Modules\Sales\Models\Order;
use App\Support\Concerns\Auditable;
use App\Support\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * وردية نقطة البيع (POS). كيان حسّاس: uuid + soft-delete + auditable (المبادئ 3/4/8).
 * open → closed. النقد المتوقّع عند الإغلاق يُشتقّ من الرصيد الافتتاحي وحركات الدرج.
 */
class PosShift extends Model
{
    use Auditable, HasFactory, HasUuid, SoftDeletes;

    public const STATUS_OPEN = 'open';

    public const STATUS_CLOSED = 'closed';

    protected $fillable = [
        'number', 'branch_id', 'warehouse_id', 'treasury_id', 'user_id', 'status',
        'opening_float', 'cash_sales', 'card_sales', 'cash_refunds', 'total_sales', 'total_refunds',
        'expected_cash', 'counted_cash', 'variance', 'orders_count',
        'opened_at', 'closed_at', 'notes', 'created_by',
    ];

    protected $casts = [
        'opening_float' => 'decimal:2',
        'cash_sales' => 'decimal:2',
        'card_sales' => 'decimal:2',
        'cash_refunds' => 'decimal:2',
        'total_sales' => 'decimal:2',
        'total_refunds' => 'decimal:2',
        'expected_cash' => 'decimal:2',
        'counted_cash' => 'decimal:2',
        'variance' => 'decimal:2',
        'orders_count' => 'integer',
        'opened_at' => 'datetime',
        'closed_at' => 'datetime',
    ];

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function treasury(): BelongsTo
    {
        return $this->belongsTo(Treasury::class);
    }

    public function cashier(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function movements(): HasMany
    {
        return $this->hasMany(PosShiftMovement::class);
    }

    public function orders(): HasMany
    {
        return $this->hasMany(Order::class);
    }

    public function isOpen(): bool
    {
        return $this->status === self::STATUS_OPEN;
    }
}
