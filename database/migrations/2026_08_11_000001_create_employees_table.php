<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * جدول الموظفين — كيان مستقل للرواتب والذمم/السلف.
 * user_id: ربط اختياري بحساب مستخدم (قد يوجد موظف بلا حساب دخول).
 * customer_id: ربط بسجل عميل لاستخدام البيع الآجل (ذمم) في نقطة البيع.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('employees', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('branch_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('user_id')->nullable()->unique()->constrained('users')->nullOnDelete();
            $table->foreignId('customer_id')->nullable()->constrained('customers')->nullOnDelete();
            $table->string('name', 180);
            $table->string('phone', 40)->nullable();
            $table->string('job_title', 120)->nullable();
            $table->decimal('monthly_salary', 15, 2)->default(0);
            $table->date('hire_date')->nullable();
            $table->boolean('is_active')->default(true);
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index('is_active');
            $table->index('name');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('employees');
    }
};
