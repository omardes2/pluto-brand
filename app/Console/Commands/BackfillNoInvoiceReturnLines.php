<?php

namespace App\Console\Commands;

use App\Modules\Catalog\Models\ProductVariant;
use App\Modules\Inventory\Models\InventoryMovement;
use App\Modules\Pos\Models\PosReturnLine;
use App\Modules\Pos\Models\PosShift;
use App\Modules\Pos\Models\PosShiftMovement;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * تعبئة بنود المرتجعات بدون فاتورة القديمة (قبل جدول pos_return_lines) من حركات المخزون،
 * حتى تُخصم من المبيعات/التكلفة/الربح في التقارير. idempotent (يتخطّى الورديات المُعبّأة).
 * سعر الوحدة يُشتقّ من إجمالي استرداد الوردية بدون فاتورة ÷ إجمالي الكمية المُرجعة.
 */
class BackfillNoInvoiceReturnLines extends Command
{
    protected $signature = 'pos:backfill-noinvoice-returns {--dry-run} {--force}';

    protected $description = 'تعبئة بنود المرتجعات بدون فاتورة القديمة من حركات المخزون لتصحيح التقارير';

    public function handle(): int
    {
        $movements = InventoryMovement::query()
            ->where('reference_type', PosShift::class)
            ->where('reason', 'like', 'pos_return_no_invoice:%')
            ->get()
            ->groupBy('reference_id');

        if ($movements->isEmpty()) {
            $this->info('لا توجد مرتجعات بدون فاتورة قديمة.');

            return self::SUCCESS;
        }

        $plan = [];
        foreach ($movements as $shiftId => $rows) {
            if (PosReturnLine::where('pos_shift_id', $shiftId)->whereNull('order_id')->exists()) {
                continue; // مُعبّأة مسبقًا
            }
            $shift = PosShift::find($shiftId);
            if (! $shift) {
                continue;
            }
            $totalRefund = (float) $shift->movements()
                ->where('type', PosShiftMovement::TYPE_REFUND)->whereNull('order_id')->sum('amount');
            $totalQty = (float) $rows->sum('qty');
            if ($totalQty <= 0) {
                continue;
            }
            $unitPrice = round($totalRefund / $totalQty, 2);
            $plan[] = ['shift' => $shift, 'rows' => $rows, 'unit_price' => $unitPrice, 'qty' => $totalQty];
        }

        if ($plan === []) {
            $this->info('كل المرتجعات بدون فاتورة مُعبّأة مسبقًا — لا شيء للتنفيذ.');

            return self::SUCCESS;
        }

        foreach ($plan as $p) {
            $this->line("وردية {$p['shift']->number}: {$p['qty']} قطعة، سعر وحدة تقريبي {$p['unit_price']}");
        }

        if ($this->option('dry-run')) {
            $this->info('(تشغيل تجريبي — لم يُنفَّذ شيء)');

            return self::SUCCESS;
        }
        if (! $this->option('force') && ! $this->confirm('تعبئة بنود المرتجعات؟')) {
            return self::SUCCESS;
        }

        $created = 0;
        foreach ($plan as $p) {
            DB::transaction(function () use ($p, &$created) {
                foreach ($p['rows'] as $mv) {
                    $cost = (float) $mv->unit_cost;
                    if ($cost <= 0) {
                        $cost = (float) (ProductVariant::whereKey($mv->variant_id)->value('average_cost') ?? 0);
                    }
                    PosReturnLine::create([
                        'pos_shift_id' => $p['shift']->id,
                        'order_id' => null,
                        'variant_id' => $mv->variant_id,
                        'qty' => (float) $mv->qty,
                        'unit_price' => $p['unit_price'],
                        'unit_cost' => $cost,
                        'created_at' => $mv->created_at ?? now(),
                    ]);
                    $created++;
                }
            });
        }

        $this->info("اكتمل: أُنشئ {$created} بند مرتجع.");

        return self::SUCCESS;
    }
}
