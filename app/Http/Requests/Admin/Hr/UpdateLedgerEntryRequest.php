<?php

namespace App\Http\Requests\Admin\Hr;

use App\Modules\Hr\Models\EmployeeLedgerEntry;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateLedgerEntryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('hr.employees.ledger') ?? false;
    }

    public function rules(): array
    {
        return [
            'type' => ['required', Rule::in([
                EmployeeLedgerEntry::TYPE_SALARY_ACCRUAL,
                EmployeeLedgerEntry::TYPE_ADVANCE,
                EmployeeLedgerEntry::TYPE_SALARY_PAYMENT,
                EmployeeLedgerEntry::TYPE_ADJUSTMENT,
            ])],
            'amount' => ['required', 'numeric', 'gt:0', 'max:9999999999999'],
            'direction' => ['required_if:type,adjustment', 'in:credit,debit'],
            'entry_date' => ['nullable', 'date'],
            'note' => ['nullable', 'string', 'max:500'],
        ];
    }
}
