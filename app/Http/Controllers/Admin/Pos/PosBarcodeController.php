<?php

namespace App\Http\Controllers\Admin\Pos;

use App\Http\Controllers\Controller;
use App\Modules\Pos\Services\PosCatalogService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;

class PosBarcodeController extends Controller
{
    public function __construct(private readonly PosCatalogService $catalog) {}

    /** صفحة طباعة ملصقات الباركود — اختيار الأصناف وكمياتها وطباعتها بمقاس 50×25مم. */
    public function index(Request $request): View
    {
        $q = trim((string) $request->query('q')) ?: null;
        $category = $request->filled('category') ? (int) $request->query('category') : null;

        return view('admin.pos.barcodes', [
            'items' => $this->catalog->barcodeItems($q, $category),
            'categories' => $this->catalog->categories(),
            'q' => $q,
            'category' => $category,
        ]);
    }
}
