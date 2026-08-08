<?php

use App\Modules\Accounting\Models\Account;
use Illuminate\Database\Migrations\Migration;

/**
 * حساب «مصروفات نقطة البيع» (5010) الطرفي تحت «المصروفات 5000» — لترحيل مصروفات
 * الدرج محاسبيًا (مدين المصروف / دائن الصندوق) على قواعد قائمة (idempotent).
 */
return new class extends Migration
{
    public function up(): void
    {
        // في التثبيت الجديد لم يُزرع دليل الحسابات بعد وقت الترحيل — يُنشئه الـSeeder
        // تحت «المصروفات 5000». هنا نعالج قواعد البيانات القائمة فقط (5000 موجود).
        $parentId = Account::where('code', '5000')->value('id');
        if (! $parentId) {
            return;
        }

        Account::query()->firstOrCreate(
            ['code' => '5010'],
            [
                'name' => 'مصروفات نقطة البيع',
                'type' => 'expense',
                'parent_id' => $parentId,
                'is_postable' => true,
            ],
        );
    }

    public function down(): void
    {
        // لا نحذف الحساب تلقائيًا (سلامة محاسبية) — قد تكون عليه قيود مُرحّلة.
    }
};
