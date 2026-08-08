<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * وجهة التوصيل على جلسة الإتمام: المدينة (إلزامية للتوصيل) والمنطقة (اختيارية).
 * تُنسَخ لاحقًا إلى الطلب ليُرسَل لشركة التوصيل (ربط طلبات الموقع بالمزوّد).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('checkout_sessions', function (Blueprint $table) {
            $table->foreignId('city_id')->nullable()->after('shipping_address')->constrained('cities')->nullOnDelete();
            $table->foreignId('area_id')->nullable()->after('city_id')->constrained('areas')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('checkout_sessions', function (Blueprint $table) {
            $table->dropConstrainedForeignId('city_id');
            $table->dropConstrainedForeignId('area_id');
        });
    }
};
