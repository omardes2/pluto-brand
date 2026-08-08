<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * تصنيف حركة الدرج — يُستخدم لنوع المصروف (غداء/كهرباء/…) على حركات pay_out.
 * يبقى null لبقية الحركات (مبيعات/إيداع/رصيد افتتاحي).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pos_shift_movements', function (Blueprint $table) {
            $table->string('category', 60)->nullable()->after('type');
        });
    }

    public function down(): void
    {
        Schema::table('pos_shift_movements', function (Blueprint $table) {
            $table->dropColumn('category');
        });
    }
};
