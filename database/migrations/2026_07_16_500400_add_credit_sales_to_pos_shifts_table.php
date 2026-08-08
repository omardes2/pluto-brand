<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * مبيعات الذمم (الآجل) على الوردية — فواتير غير مدفوعة تُرحَّل على حساب العميل.
 * لا تدخل الدرج النقدي، لكنها تُحتسب ضمن إجمالي المبيعات.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pos_shifts', function (Blueprint $table) {
            $table->decimal('credit_sales', 15, 2)->default(0)->after('card_sales');
        });
    }

    public function down(): void
    {
        Schema::table('pos_shifts', function (Blueprint $table) {
            $table->dropColumn('credit_sales');
        });
    }
};
