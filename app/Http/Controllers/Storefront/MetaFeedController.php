<?php

namespace App\Http\Controllers\Storefront;

use App\Http\Controllers\Controller;
use App\Modules\Store\Services\MetaCatalogFeedService;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * رابط عام لموجز منتجات كتالوج ميتا (CSV). يُلصق في «مدير المعاملات التجارية → إضافة منتجات →
 * استخدام عنوان URL»، فيسحبه ميتا ويعيد المزامنة دوريًا. يُبثّ كسولًا لتفادي استهلاك الذاكرة.
 */
class MetaFeedController extends Controller
{
    public function csv(MetaCatalogFeedService $feed): StreamedResponse
    {
        return response()->stream(function () use ($feed) {
            $out = fopen('php://output', 'w');
            fputcsv($out, MetaCatalogFeedService::HEADER);
            foreach ($feed->rows() as $row) {
                fputcsv($out, array_map(fn ($h) => $row[$h] ?? '', MetaCatalogFeedService::HEADER));
            }
            fclose($out);
        }, 200, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'inline; filename="meta-catalog.csv"',
            'Cache-Control' => 'public, max-age=1800',
        ]);
    }
}
