<?php

namespace App\Modules\Shipping\Listeners;

use App\Modules\Sales\Services\OrderService;
use App\Modules\Shipping\Events\DeliveryStatusChanged;
use App\Modules\Shipping\Support\DeliveryStatus;
use Illuminate\Support\Facades\Log;

/**
 * يعكس حركات شركة التوصيل (webhook/مزامنة) على الطلب في لوحة الطلبات:
 *
 * 1. **إلغاء المزوّد** (Opost: cancel) ⇒ إلغاء الطلب تلقائيًا وتحرير المخزون المحجوز
 *    (إن كان الطلب قابلًا للإلغاء). الإلغاء اليدوي داخليًا لا يُلغي الطلب.
 * 2. **وصول المبلغ لمحاسبة المندوب** (Opost: in_accounting ⇒ FUNDS_AT_ACCOUNTING) ⇒
 *    اعتبار الفاتورة **مدفوعة** في النظام (حُصّل مبلغ الدفع عند الاستلام وأصبح لدى مالية
 *    شركة التوصيل قابلًا للسحب). علمًا أنّ ذمّة شركة التوصيل (1050) تبقى في الأستاذ حتى
 *    التحصيل النهائي — فالعلَم هنا حالة دفع الطلب لا قيدًا محاسبيًا جديدًا.
 */
class SyncOrderOnDeliveryStatus
{
    /** حالات الطلب التي يجوز إلغاؤها (مطابقة لـ OrderService::cancel). */
    private const CANCELLABLE = ['draft', 'new', 'confirmed', 'stock_reserved', 'preparing', 'ready_to_ship'];

    public function __construct(private readonly OrderService $orders) {}

    public function handle(DeliveryStatusChanged $event): void
    {
        match ($event->toStatus) {
            DeliveryStatus::CANCELLED => $this->cancelOrderFromProvider($event),
            DeliveryStatus::FUNDS_AT_ACCOUNTING => $this->markOrderPaid($event),
            default => null,
        };
    }

    /** إلغاء الطلب عند إلغاء الشحنة لدى شركة التوصيل (لا من إجراء داخلي). */
    private function cancelOrderFromProvider(DeliveryStatusChanged $event): void
    {
        if ($event->actorType !== 'provider') {
            return;
        }

        $order = $event->shipment->order;
        if ($order === null || ! in_array($order->status, self::CANCELLABLE, true)) {
            return; // مُلغى مسبقًا أو في حالة لا تسمح بالإلغاء (مثلًا مشحون/مُسلَّم).
        }

        try {
            $this->orders->cancel($order, __('أُلغيت الشحنة لدى شركة التوصيل.'));
        } catch (\Throwable $e) {
            Log::warning('Auto-cancel order on provider cancel failed: '.$e->getMessage(), ['order' => $order->id]);
        }
    }

    /**
     * اعتبار الفاتورة مدفوعة عند وصول المبلغ لمحاسبة شركة التوصيل (in_accounting). idempotent:
     * لا يمسّ طلبًا ملغى ولا يُعيد التعليم إن كان مدفوعًا بالكامل مسبقًا.
     */
    private function markOrderPaid(DeliveryStatusChanged $event): void
    {
        $order = $event->shipment->order;
        if ($order === null || $order->status === 'cancelled') {
            return;
        }

        $total = round((float) $order->total, 2);
        $alreadyPaid = $order->payment_status === 'paid'
            && (float) $order->amount_paid + 0.001 >= $total;
        if ($alreadyPaid) {
            return;
        }

        $order->update([
            'amount_paid' => $total,
            'payment_status' => 'paid',
        ]);
    }
}
