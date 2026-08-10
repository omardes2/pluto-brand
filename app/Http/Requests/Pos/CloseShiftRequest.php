<?php

namespace App\Http\Requests\Pos;

use Illuminate\Foundation\Http\FormRequest;

class CloseShiftRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('pos.shift.manage') ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'counted_cash' => ['required', 'numeric', 'min:0'],
            'notes' => ['nullable', 'string', 'max:500'],
            'print_report' => ['nullable', 'boolean'],
        ];
    }
}
