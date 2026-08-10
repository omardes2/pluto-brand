<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * دفتر حساب الموظف — قيود تراكمية (استحقاق راتب/سلفة/مشتريات/دفع راتب/تسوية).
 * amount مُوقّع: موجب = مُستحق للموظف، سالب = مخصوم عليه. الرصيد = SUM(amount).
 * موجب → له رصيد من راتبه، سالب → عليه دين.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('employee_ledger_entries', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->string('type', 30); // salary_accrual/advance/purchase/salary_payment/adjustment
            $table->decimal('amount', 15, 2); // مُوقّع (+ مُستحق / − مخصوم)
            $table->date('entry_date');
            $table->string('source_type', 60)->nullable(); // pos_order / pos_expense / manual ...
            $table->unsignedBigInteger('source_id')->nullable();
            $table->string('note', 500)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['employee_id', 'entry_date']);
            $table->index(['source_type', 'source_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('employee_ledger_entries');
    }
};
