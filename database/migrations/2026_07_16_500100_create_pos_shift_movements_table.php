<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * حركات درج نقطة البيع — سجلّ غير قابل للتعديل لكل ما يدخل/يخرج من درج الوردية.
 * type: opening (افتتاحي) | cash_sale | card_sale | refund | pay_in (إيداع) | pay_out (سحب).
 * منها يُشتقّ النقد المتوقّع في الدرج عند إغلاق الوردية.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pos_shift_movements', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('pos_shift_id')->constrained('pos_shifts')->cascadeOnDelete();
            $table->string('type', 20); // opening|cash_sale|card_sale|refund|pay_in|pay_out
            $table->decimal('amount', 15, 2);
            $table->foreignId('order_id')->nullable()->constrained('orders')->nullOnDelete();
            $table->string('reference', 60)->nullable(); // رقم الفاتورة/مرجع خارجي
            $table->string('note', 255)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['pos_shift_id', 'type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pos_shift_movements');
    }
};
