<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// ربط متغيّر المنتج بقيم السمات (مقاس/لون…) — أساس اختيار الخيارات في المتجر.
// إضافي بالكامل: لا يمسّ جداول المنتجات/المتغيّرات/المخزون/الطلبات القائمة.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('variant_attribute_values', function (Blueprint $table) {
            $table->id();
            $table->foreignId('variant_id')->constrained('product_variants')->cascadeOnDelete();
            // attribute_id مُخزّن (denormalized) لتجميع المحاور بلا join إضافي عبر القيم.
            $table->foreignId('attribute_id')->constrained('product_attributes')->cascadeOnDelete();
            $table->foreignId('attribute_value_id')->constrained('product_attribute_values')->cascadeOnDelete();
            $table->timestamps();

            // قيمة واحدة لكل محور (سمة) لكل متغيّر.
            $table->unique(['variant_id', 'attribute_id']);
            // تجميع الخيارات وفلترتها في المتجر.
            $table->index(['attribute_id', 'attribute_value_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('variant_attribute_values');
    }
};
