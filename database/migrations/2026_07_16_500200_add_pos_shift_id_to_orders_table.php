<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ربط طلب البيع بوردية نقطة البيع التي أنشأته (لطلبات القناة pos فقط).
 * يبقى null لكل الطلبات الأخرى (web/manual/marketer). لا يغيّر أي منطق قائم.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->foreignId('pos_shift_id')->nullable()->after('channel')->constrained('pos_shifts')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropConstrainedForeignId('pos_shift_id');
        });
    }
};
