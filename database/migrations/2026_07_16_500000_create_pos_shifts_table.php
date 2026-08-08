<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * وردية نقطة البيع (POS). كيان حسّاس: uuid + soft-delete + auditable.
 * تربط الكاشير بفرع/مستودع/خزينة (درج النقد). open → closed.
 * أرصدة النقد/البطاقة تُجمَّع أثناء الوردية، وعند الإغلاق تُسوَّى (متوقّع مقابل معدود).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pos_shifts', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('number', 40)->unique();
            $table->foreignId('branch_id')->constrained('branches')->restrictOnDelete();
            $table->foreignId('warehouse_id')->constrained('warehouses')->restrictOnDelete();
            $table->foreignId('treasury_id')->constrained('treasuries')->restrictOnDelete(); // درج النقد
            $table->foreignId('user_id')->constrained('users')->restrictOnDelete();          // الكاشير
            $table->string('status', 16)->default('open');                                   // open|closed
            $table->decimal('opening_float', 15, 2)->default(0);   // الرصيد الافتتاحي للدرج
            $table->decimal('cash_sales', 15, 2)->default(0);
            $table->decimal('card_sales', 15, 2)->default(0);
            $table->decimal('cash_refunds', 15, 2)->default(0);
            $table->decimal('total_sales', 15, 2)->default(0);
            $table->decimal('total_refunds', 15, 2)->default(0);
            $table->decimal('expected_cash', 15, 2)->default(0);   // المتوقّع في الدرج عند الإغلاق
            $table->decimal('counted_cash', 15, 2)->nullable();    // المعدود فعليًا
            $table->decimal('variance', 15, 2)->nullable();        // الفرق (معدود − متوقّع)
            $table->unsignedInteger('orders_count')->default(0);
            $table->timestamp('opened_at')->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->string('notes', 500)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['branch_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pos_shifts');
    }
};
