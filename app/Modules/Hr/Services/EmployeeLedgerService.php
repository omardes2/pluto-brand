<?php

namespace App\Modules\Hr\Services;

use App\Modules\Hr\Models\Employee;
use App\Modules\Hr\Models\EmployeeLedgerEntry;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;

/**
 * دفتر حساب الموظف — تسجيل القيود وحساب الرصيد التراكمي والديون.
 * amount مُوقّع في القاعدة؛ هذه الخدمة تُطبّق إشارة النوع تلقائيًا.
 */
class EmployeeLedgerService
{
    /**
     * تسجيل قيد. $amount يُمرَّر موجبًا دائمًا؛ تُطبَّق إشارة النوع.
     * للتسوية (adjustment) يُحدَّد الاتجاه بـ $direction: 'credit' (+) أو 'debit' (−).
     */
    public function record(
        Employee $employee,
        string $type,
        float $amount,
        ?string $note = null,
        ?string $entryDate = null,
        ?string $sourceType = null,
        ?int $sourceId = null,
        string $direction = 'debit',
    ): EmployeeLedgerEntry {
        $magnitude = abs($amount);
        $sign = EmployeeLedgerEntry::SIGNS[$type] ?? ($direction === 'credit' ? 1 : -1);

        return $employee->ledgerEntries()->create([
            'type' => $type,
            'amount' => round($sign * $magnitude, 2),
            'entry_date' => $entryDate ?: Carbon::now()->toDateString(),
            'source_type' => $sourceType,
            'source_id' => $sourceId,
            'note' => $note,
            'created_by' => Auth::id(),
        ]);
    }

    /**
     * ملخّص حساب الموظف: الإجماليات والرصيد.
     * balance موجب = مُستحق للموظف (له رصيد)، سالب = دين عليه.
     *
     * @return array<string, float|bool>
     */
    public function summary(Employee $employee): array
    {
        $entries = $employee->ledgerEntries()->get(['type', 'amount']);

        $sumOf = fn (string $type) => (float) $entries->where('type', $type)->sum('amount');

        $accrued = $sumOf(EmployeeLedgerEntry::TYPE_SALARY_ACCRUAL);
        $advances = abs($sumOf(EmployeeLedgerEntry::TYPE_ADVANCE));
        $purchases = abs($sumOf(EmployeeLedgerEntry::TYPE_PURCHASE));
        $paid = abs($sumOf(EmployeeLedgerEntry::TYPE_SALARY_PAYMENT));
        $adjustments = (float) $entries->where('type', EmployeeLedgerEntry::TYPE_ADJUSTMENT)->sum('amount');
        $balance = round((float) $entries->sum('amount'), 2);

        return [
            'accrued' => round($accrued, 2),
            'advances' => round($advances, 2),
            'purchases' => round($purchases, 2),
            'paid' => round($paid, 2),
            'adjustments' => round($adjustments, 2),
            'balance' => $balance,          // + مُستحق له / − دين عليه
            'due_to_employee' => round(max($balance, 0), 2),
            'debt' => round(max(-$balance, 0), 2),
        ];
    }
}
