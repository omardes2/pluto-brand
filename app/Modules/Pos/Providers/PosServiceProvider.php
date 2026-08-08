<?php

namespace App\Modules\Pos\Providers;

use Illuminate\Support\ServiceProvider;

/**
 * وحدة نقطة البيع (POS). البيع المباشر داخل المحل: بيع نقدي/بطاقة، وردية وصندوق نقدي،
 * وإرجاع/تبديل — تُبنى فوق خدمات المبيعات/المخزون/المدفوعات/المحاسبة القائمة (بلا تكرار).
 *
 * Policies وربط الخدمات تُضاف في المراحل اللاحقة (خدمة البيع/الوردية/الواجهة).
 */
class PosServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        //
    }
}
