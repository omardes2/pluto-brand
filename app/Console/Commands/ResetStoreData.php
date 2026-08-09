<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * تهيئة المتجر لبداية نظيفة قبل الإطلاق: حذف كل البيانات التشغيلية/التجريبية
 * (مبيعات، ورديات كاشير، شحنات، مرتجعات، عمولات، مشتريات، موردون، عملاء، مخزون،
 * قيود محاسبية، منتجات ومتغيّراتها) وتصفير الصناديق — مع **الإبقاء** على:
 * سمات المنتجات وقيمها (المقاسات/الألوان)، التصنيفات، العلامات التجارية، الوسوم،
 * والبنية الأساسية (دليل الحسابات، الخزائن، الفروع/المستودعات، الوحدات، الجغرافيا،
 * طرق الدفع، الإعدادات، الأدوار/الصلاحيات، المستخدمون).
 *
 * الاستخدام:
 *   php artisan store:reset --dry-run   # عرض ما سيُحذف دون تنفيذ
 *   php artisan store:reset             # تنفيذ (يطلب تأكيدًا)
 *   php artisan store:reset --force     # تنفيذ دون تأكيد
 */
class ResetStoreData extends Command
{
    protected $signature = 'store:reset {--dry-run : عرض ما سيُحذف دون تنفيذ} {--force : تنفيذ دون تأكيد} {--with-categories : حذف التصنيفات والعلامات أيضًا (تبقى السمات)}';

    protected $description = 'تهيئة المتجر لبداية نظيفة: حذف البيانات التشغيلية وتصفير الصناديق مع الإبقاء على السمات والتصنيفات والبنية.';

    /**
     * الجداول التي تُفرَّغ بالكامل (أبناء ← آباء). لا تشمل: product_attributes،
     * product_attribute_values، categories، brands، product_tags، campaign_templates
     * (تبقى كبيانات كتالوج/إعداد).
     */
    private const CLEAR_TABLES = [
        // تحليلات/توصيات
        'recommendation_events', 'product_recommendations',
        // روابط المنتج (تذهب مع المنتجات) — دون السمات/القيم نفسها
        'variant_attribute_values', 'product_attribute_links', 'product_tag_links', 'product_images',
        // مرتجعات
        'return_request_photos', 'return_request_events', 'return_request_items', 'return_requests',
        // عمولات
        'commission_payout_entries', 'commission_transitions', 'commission_entries', 'commission_payouts',
        // شحن/توصيل
        'settlement_lines', 'delivery_settlements',
        'shipment_events', 'shipment_fee_components',
        'delivery_exception_notes', 'delivery_provider_events', 'delivery_provider_transitions',
        'delivery_exceptions', 'shipments',
        // مدفوعات
        'payment_transactions', 'payments',
        // ورديات الكاشير (POS)
        'pos_shift_movements', 'pos_shifts',
        // مبيعات
        'order_price_changes', 'order_status_history', 'order_items', 'orders',
        // مشتريات
        'goods_receipt_items', 'purchase_invoice_items', 'purchase_order_items', 'supplier_return_items',
        'goods_receipts', 'purchase_invoices', 'purchase_orders', 'supplier_returns',
        'supplier_contacts', 'suppliers',
        // عملاء
        'customer_notes', 'customer_addresses', 'customer_contacts', 'customer_phones',
        'wishlist_items', 'social_identities', 'checkout_sessions', 'cart_items', 'carts', 'customers',
        // حملات/رسائل (تجريبية)
        'campaign_messages', 'campaigns', 'message_suppressions',
        // مخزون
        'inventory_ledger', 'inventory_movements', 'inventory_count_items', 'inventory_counts',
        'stock_adjustment_items', 'stock_adjustments', 'stock_reservations', 'inventory_stocks',
        // قيود محاسبية
        'journal_lines', 'journal_entries', 'financial_vouchers',
        // منتجات ومتغيّراتها (تبقى السمات/القيم/التصنيفات/العلامات)
        'product_variants', 'products',
        // سجلّات
        'ai_generation_logs', 'audit_logs', 'notifications',
    ];

    public function handle(): int
    {
        $dry = (bool) $this->option('dry-run');

        $this->line($dry ? '— وضع المعاينة (لن يُحذف شيء) —' : '— تهيئة المتجر لبداية نظيفة —');
        $this->newLine();

        // التصنيفات/العلامات تُحذف فقط عند --with-categories (السمات تبقى دائمًا).
        $tables = self::CLEAR_TABLES;
        if ($this->option('with-categories')) {
            $tables[] = 'categories';
            $tables[] = 'brands';
        }

        $counts = [];
        foreach ($tables as $table) {
            if (Schema::hasTable($table)) {
                $n = DB::table($table)->count();
                if ($n > 0) {
                    $counts[$table] = $n;
                }
            }
        }
        // حسابات فرعية للعملاء/الموردين (تُحذف مع حذفهم).
        $subAccounts = Schema::hasTable('accounts')
            ? DB::table('accounts')->where(fn ($q) => $q->where('code', 'like', '1100-%')->orWhere('code', 'like', '2010-%'))->count() : 0;
        // الخزائن التي سيُصفَّر رصيدها الافتتاحي (تبقى الخزائن نفسها).
        $treasuries = Schema::hasTable('treasuries')
            ? DB::table('treasuries')->where('opening_balance', '!=', 0)->count() : 0;

        foreach ($counts as $table => $n) {
            $this->line(sprintf('  %-32s %d', $table, $n));
        }
        $this->line(sprintf('  %-32s %d', 'accounts (حسابات عملاء/موردين فرعية)', $subAccounts));
        $this->line(sprintf('  %-32s %d', 'treasuries (تصفير الرصيد الافتتاحي)', $treasuries));
        $this->newLine();

        $keptMeta = $this->option('with-categories') ? 'الوسوم' : 'التصنيفات، العلامات، الوسوم';
        $this->comment("سيبقى: سمات المنتجات وقيمها، {$keptMeta}، والبنية الأساسية.");
        $this->newLine();

        $total = array_sum($counts) + $subAccounts;
        if ($total === 0 && $treasuries === 0) {
            $this->info('لا توجد بيانات تشغيلية للحذف — المتجر نظيف بالفعل.');

            return self::SUCCESS;
        }

        if ($dry) {
            $this->info("سيُحذف {$total} سجلّ وتُصفَّر {$treasuries} خزنة. أعد التشغيل دون --dry-run للتنفيذ.");

            return self::SUCCESS;
        }

        if (! $this->option('force') && ! $this->confirm("سيُحذف {$total} سجلّ نهائيًا وتُصفَّر الصناديق. هل تريد المتابعة؟")) {
            $this->warn('أُلغيت العملية.');

            return self::SUCCESS;
        }

        Schema::disableForeignKeyConstraints();
        try {
            foreach (array_keys($counts) as $table) {
                DB::table($table)->delete();
            }
            if (Schema::hasTable('accounts')) {
                DB::table('accounts')->where(fn ($q) => $q->where('code', 'like', '1100-%')->orWhere('code', 'like', '2010-%'))->delete();
            }
            if (Schema::hasTable('treasuries')) {
                DB::table('treasuries')->update(['opening_balance' => 0]);
            }
        } finally {
            Schema::enableForeignKeyConstraints();
        }

        $this->newLine();
        $this->info("تم — حُذف {$total} سجلّ وصُفِّرت الصناديق. المتجر الآن على بداية نظيفة (السمات والتصنيفات محفوظة).");

        return self::SUCCESS;
    }
}
