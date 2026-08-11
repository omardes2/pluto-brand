<?php

use App\Modules\Pos\Models\PosReturnLine;
use Illuminate\Database\Migrations\Migration;

/**
 * تصحيح تكلفة بنود المرتجعات التي عُبّئت بتكلفة صفر (اعتمدت على average_cost فقط،
 * وبعض الأصناف تكلفتها في cost_price لا في WAC). يُعاد الحساب بسلسلة التراجُع نفسها
 * المستخدمة في التقارير: average_cost ← cost_price ← تكلفة المنتج.
 */
return new class extends Migration
{
    public function up(): void
    {
        PosReturnLine::query()->where('unit_cost', '<=', 0)->with('variant.product')->get()
            ->each(function (PosReturnLine $line) {
                $v = $line->variant;
                $cost = (float) ($v?->average_cost ?: 0);
                if ($cost <= 0) {
                    $cost = (float) ($v?->cost_price ?: 0);
                }
                if ($cost <= 0) {
                    $cost = (float) ($v?->product?->cost_price ?: 0);
                }
                if ($cost > 0) {
                    $line->forceFill(['unit_cost' => $cost])->save();
                }
            });
    }

    public function down(): void
    {
        // لا تراجُع — تصحيح بيانات لمرّة واحدة.
    }
};
