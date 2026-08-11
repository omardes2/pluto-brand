<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * بنود مرتجعات نقطة البيع (بفاتورة وبدون) — مصدر موحّد قابل للاستعلام لخصم المرتجعات
 * من المبيعات والتكلفة في التقارير. الإرجاع بدون فاتورة لم يكن له بند مُخزَّن، فكانت
 * المبيعات/التكلفة لا تُخصم في التقارير (ربح سالب خاطئ).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pos_return_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('pos_shift_id')->constrained('pos_shifts')->cascadeOnDelete();
            $table->foreignId('order_id')->nullable()->constrained('orders')->nullOnDelete(); // null = بدون فاتورة
            $table->foreignId('variant_id')->constrained('product_variants')->restrictOnDelete();
            $table->decimal('qty', 15, 3);
            $table->decimal('unit_price', 15, 2);       // سعر بيع الوحدة المُسترَد
            $table->decimal('unit_cost', 15, 4)->default(0); // تكلفة الوحدة (WAC) لعكس تكلفة البضاعة
            $table->timestamp('created_at')->nullable();

            $table->index('pos_shift_id');
            $table->index('variant_id');
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pos_return_lines');
    }
};
