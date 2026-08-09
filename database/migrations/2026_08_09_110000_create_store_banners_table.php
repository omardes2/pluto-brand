<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * شرائح بنر الصفحة الرئيسية (سلايدر) — تُدار من اللوحة: صورة + عنوان/وصف + رابط زر
 * + ترتيب + تفعيل. متعدّد اللغة عبر حقول *_en اختيارية.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('store_banners', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('image');                 // مسار الصورة على القرص العام
            $table->string('mobile_image')->nullable(); // صورة بديلة للموبايل (اختياري)
            $table->string('title')->nullable();
            $table->string('title_en')->nullable();
            $table->string('subtitle')->nullable();
            $table->string('subtitle_en')->nullable();
            $table->string('button_label')->nullable();
            $table->string('button_label_en')->nullable();
            $table->string('button_url')->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();

            $table->index(['is_active', 'sort_order']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('store_banners');
    }
};
