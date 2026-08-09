<?php

namespace App\Http\Controllers\Admin\Catalog;

use App\Http\Controllers\Controller;
use App\Http\Requests\Catalog\ImportProductsRequest;
use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Services\ProductImportService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ProductImportController extends Controller
{
    public function __construct(private readonly ProductImportService $service) {}

    public function form(): View
    {
        $this->authorize('create', Product::class);

        return view('admin.catalog.products.import');
    }

    public function import(ImportProductsRequest $request): RedirectResponse
    {
        $this->authorize('create', Product::class);

        $summary = $this->service->import($request->file('file')->getRealPath());

        return redirect()->route('admin.products.import.form')
            ->with('import_summary', $summary)
            ->with('success', __('اكتمل الاستيراد: :created جديد، :updated محدّث، :skipped متجاوَز.', [
                'created' => $summary['created'],
                'updated' => $summary['updated'],
                'skipped' => $summary['skipped'],
            ]));
    }

    /** تنزيل قالب CSV جاهز بالعناوين العربية (مع BOM ليفتح صحيحًا في Excel). */
    public function template(): StreamedResponse
    {
        $this->authorize('create', Product::class);

        return response()->streamDownload(function () {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF"); // BOM لعرض العربية في Excel
            fputcsv($out, ['اسم الصنف', 'سعر البيع', 'الكمية', 'سعر الشراء', 'الباركود', 'التصنيف']);
            fputcsv($out, ['قميص قطن رجالي', '120', '10', '60', '26000021', 'قمصان']);
            fputcsv($out, ['حذاء رياضي', '150', '5', '80', '', 'أحذية']);
            fclose($out);
        }, 'products-import-template.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }
}
