<?php

namespace App\Modules\Pos\Support;

/**
 * مولّد باركود Code 128 (النمط B) ذاتي بلا اعتماديات خارجية.
 *
 * يُنتج SVG من أشرطة سوداء على خلفية بيضاء، مقاسًا ليملأ العرض/الارتفاع
 * المطلوبين (preserveAspectRatio=none) ليتحكّم CSS بالمقاس النهائي (مم).
 */
final class Code128
{
    /** أنماط Code 128 (القيم 0..106) — عرض كل شريط/فراغ بالوحدات (تبدأ بشريط). */
    private const PATTERNS = [
        '212222', '222122', '222221', '121223', '121322', '131222', '122213', '122312',
        '132212', '221213', '221312', '231212', '112232', '122132', '122231', '113222',
        '123122', '123221', '223211', '221132', '221231', '213212', '223112', '312131',
        '311222', '321122', '321221', '312212', '322112', '322211', '212123', '212321',
        '232121', '111323', '131123', '131321', '112313', '132113', '132311', '211313',
        '231113', '231311', '112133', '112331', '132131', '113123', '113321', '133121',
        '313121', '211331', '231131', '213113', '213311', '213131', '311123', '311321',
        '331121', '312113', '312311', '332111', '314111', '221411', '431111', '111224',
        '111422', '121124', '121421', '141122', '141221', '112214', '112412', '122114',
        '122411', '142112', '142211', '241211', '221114', '413111', '241112', '134111',
        '111242', '121142', '121241', '114212', '124112', '124211', '411212', '421112',
        '421211', '212141', '214121', '412121', '111143', '111341', '131141', '114113',
        '114311', '411113', '411311', '113141', '114131', '311141', '411131', '211412',
        '211214', '211232', '2331112',
    ];

    private const START_B = 104;

    private const STOP = 106;

    /**
     * ترميز نصّ إلى قيم Code 128-B (تتضمّن البداية والتحقّق والإيقاف).
     *
     * @return array<int,int>
     */
    public static function encodeB(string $data): array
    {
        $codes = [self::START_B];
        $sum = self::START_B;
        $pos = 1;

        foreach (str_split($data === '' ? ' ' : $data) as $ch) {
            $value = ord($ch) - 32;
            if ($value < 0 || $value > 94) {
                $value = 0; // استبدال المحارف غير القابلة للترميز بفراغ
            }
            $codes[] = $value;
            $sum += $pos * $value;
            $pos++;
        }

        $codes[] = $sum % 103; // رمز التحقّق
        $codes[] = self::STOP;

        return $codes;
    }

    /** سلسلة الوحدات (1=شريط، 0=فراغ) للباركود كاملًا. */
    public static function modules(string $data): string
    {
        $bits = '';
        foreach (self::encodeB($data) as $code) {
            $bar = true;
            foreach (str_split(self::PATTERNS[$code]) as $width) {
                $bits .= str_repeat($bar ? '1' : '0', (int) $width);
                $bar = ! $bar;
            }
        }

        return $bits;
    }

    /**
     * SVG للباركود.
     *
     * @param  float  $height  ارتفاع الأشرطة (وحدة SVG)
     * @param  float  $module  عرض الوحدة الواحدة (وحدة SVG)
     * @param  int  $quiet  هامش هادئ بعدد الوحدات على كل جانب
     */
    public static function svg(string $data, float $height = 44.0, float $module = 1.5, int $quiet = 10): string
    {
        $bits = self::modules($data);
        $n = strlen($bits);
        $total = $n + 2 * $quiet;
        $w = round($total * $module, 3);
        $h = round($height, 3);

        $rects = '';
        $i = 0;
        while ($i < $n) {
            if ($bits[$i] === '1') {
                $j = $i;
                while ($j < $n && $bits[$j] === '1') {
                    $j++;
                }
                $x = round(($quiet + $i) * $module, 3);
                $rw = round(($j - $i) * $module, 3);
                $rects .= '<rect x="'.$x.'" y="0" width="'.$rw.'" height="'.$h.'"/>';
                $i = $j;
            } else {
                $i++;
            }
        }

        return '<svg xmlns="http://www.w3.org/2000/svg" width="'.$w.'" height="'.$h.'" '
            .'viewBox="0 0 '.$w.' '.$h.'" preserveAspectRatio="none" shape-rendering="crispEdges">'
            .'<rect x="0" y="0" width="'.$w.'" height="'.$h.'" fill="#fff"/>'
            .'<g fill="#000">'.$rects.'</g></svg>';
    }
}
