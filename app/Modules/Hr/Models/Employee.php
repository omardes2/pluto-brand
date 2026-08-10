<?php

namespace App\Modules\Hr\Models;

use App\Models\User;
use App\Modules\Crm\Models\Customer;
use App\Modules\Foundation\Models\Branch;
use App\Support\Concerns\Auditable;
use App\Support\Concerns\HasUuid;
use Database\Factories\Hr\EmployeeFactory;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * موظف — كيان مستقل للرواتب والذمم/السلف (المرحلة 1).
 * كيان مهم: uuid + soft-delete + auditable. قد يرتبط بحساب مستخدم (user_id)
 * وبسجل عميل (customer_id) لاستخدام البيع الآجل في نقطة البيع.
 */
class Employee extends Model
{
    use Auditable, HasFactory, HasUuid, SoftDeletes;

    protected $fillable = [
        'branch_id', 'user_id', 'customer_id', 'name', 'phone',
        'job_title', 'monthly_salary', 'hire_date', 'is_active', 'notes', 'created_by',
    ];

    protected $casts = [
        'monthly_salary' => 'decimal:2',
        'hire_date' => 'date',
        'is_active' => 'boolean',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function ledgerEntries(): HasMany
    {
        return $this->hasMany(EmployeeLedgerEntry::class)->latest('entry_date')->latest('id');
    }

    protected static function newFactory(): Factory
    {
        return EmployeeFactory::new();
    }
}
