<?php

namespace App\Console\Commands;

use App\Modules\Sales\Models\Order;
use App\Modules\Sales\Services\SalesPostingService;
use Illuminate\Console\Command;

/**
 * إعادة ترحيل الفواتير المُخصَّمة المُرحّلة سابقًا لتصحيح قيودها المحاسبية بعد إصلاح
 * ازدواج خصم الخصم (كان الإيراد/الذمم يُنقَص بقيمة الخصم مرّتين). يُحدَّث القيد في مكانه
 * (نفس رقمه وتاريخه) بالمبالغ الصحيحة. آمن ومتكرّر (idempotent).
 */
class RepostDiscountedSales extends Command
{
    protected $signature = 'accounting:repost-discounted-sales {--dry-run : عرض المتأثّر دون تنفيذ} {--force : تنفيذ دون تأكيد}';

    protected $description = 'إعادة ترحيل الفواتير المُخصَّمة لتصحيح قيود الإيراد/الذمم بعد إصلاح ازدواج الخصم';

    public function handle(SalesPostingService $posting): int
    {
        $query = Order::query()
            ->whereNotNull('revenue_entry_id')
            ->where('discount_total', '>', 0);

        $count = $query->count();
        if ($count === 0) {
            $this->info('لا توجد فواتير مُخصَّمة مُرحّلة بحاجة إلى تصحيح.');

            return self::SUCCESS;
        }

        $this->warn("سيُعاد ترحيل {$count} فاتورة مُخصَّمة لتصحيح قيودها المحاسبية.");

        if ($this->option('dry-run')) {
            $query->clone()->orderBy('id')->limit(50)->get(['id', 'number', 'total', 'discount_total'])
                ->each(fn ($o) => $this->line("  #{$o->number} — الإجمالي {$o->total} (خصم {$o->discount_total})"));
            $this->info('(تشغيل تجريبي — لم يُنفَّذ شيء)');

            return self::SUCCESS;
        }

        if (! $this->option('force') && ! $this->confirm('المتابعة وتصحيح القيود؟')) {
            return self::SUCCESS;
        }

        $done = 0;
        $failed = 0;
        $query->orderBy('id')->chunkById(100, function ($orders) use ($posting, &$done, &$failed) {
            foreach ($orders as $order) {
                try {
                    $posting->repost($order);
                    $done++;
                } catch (\Throwable $e) {
                    $failed++;
                    $this->error("فشل #{$order->number}: {$e->getMessage()}");
                }
            }
        });

        $this->info("اكتمل: صُحّح {$done} فاتورة".($failed ? "، وفشل {$failed}." : '.'));

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
