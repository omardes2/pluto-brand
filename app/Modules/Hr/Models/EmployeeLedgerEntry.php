<?php

namespace App\Modules\Hr\Models;

use App\Models\User;
use App\Support\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * قيد في دفتر حساب الموظف. amount مُوقّع: + مُستحق للموظف، − مخصوم عليه.
 */
class EmployeeLedgerEntry extends Model
{
    use HasUuid;

    public const TYPE_SALARY_ACCRUAL = 'salary_accrual'; // استحقاق راتب (+)

    public const TYPE_ADVANCE = 'advance';               // سلفة (−)

    public const TYPE_PURCHASE = 'purchase';             // مشتريات آجلة (−)

    public const TYPE_SALARY_PAYMENT = 'salary_payment'; // دفع راتب (−)

    public const TYPE_ADJUSTMENT = 'adjustment';         // تسوية (±)

    /** إشارة كل نوع على الرصيد (+1 مُستحق، −1 مخصوم). التسوية تُحدَّد بالاتجاه. */
    public const SIGNS = [
        self::TYPE_SALARY_ACCRUAL => 1,
        self::TYPE_ADVANCE => -1,
        self::TYPE_PURCHASE => -1,
        self::TYPE_SALARY_PAYMENT => -1,
    ];

    protected $fillable = [
        'employee_id', 'type', 'amount', 'entry_date',
        'source_type', 'source_id', 'note', 'created_by',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'entry_date' => 'date',
    ];

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** تسمية عربية للنوع. */
    public function typeLabel(): string
    {
        return match ($this->type) {
            self::TYPE_SALARY_ACCRUAL => __('استحقاق راتب'),
            self::TYPE_ADVANCE => __('سلفة'),
            self::TYPE_PURCHASE => __('مشتريات آجلة'),
            self::TYPE_SALARY_PAYMENT => __('دفع راتب'),
            default => __('تسوية'),
        };
    }
}
