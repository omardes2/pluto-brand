<?php

namespace App\Support;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * تحويل الصور المرفوعة إلى صيغة WebP (عبر GD) للحفاظ على سرعة الموقع وتصغير الحجم مع
 * جودة جيّدة. يقلّص الأبعاد الكبيرة اختياريًا، ويحافظ على الشفافية (PNG). عند تعذّر
 * التحويل (صيغة غير مدعومة/غياب GD) يتراجع لتخزين الملف كما هو دون فشل الرفع.
 */
class ImageWebpConverter
{
    public function __construct(
        private readonly int $quality = 82,
        private readonly int $maxWidth = 1600,
        private readonly int $maxHeight = 1600,
    ) {}

    /**
     * يحوّل الصورة إلى WebP ويخزّنها على القرص ضمن المجلد المحدّد، ويعيد المسار النسبي
     * (مثل products/uuid.webp). يتراجع لتخزين الملف الأصلي إن تعذّر التحويل.
     */
    public function storeAsWebp(UploadedFile $file, string $directory, string $disk = 'public'): string
    {
        $image = $this->readImage($file);
        if ($image === null) {
            return $file->store($directory, $disk); // تراجع آمن
        }

        try {
            $image = $this->downscale($image);
            imagepalettetotruecolor($image);
            imagealphablending($image, false);
            imagesavealpha($image, true);

            ob_start();
            $ok = imagewebp($image, null, $this->quality);
            $binary = ob_get_clean();

            if (! $ok || $binary === '' || $binary === false) {
                return $file->store($directory, $disk);
            }

            $path = trim($directory, '/').'/'.Str::uuid()->toString().'.webp';
            Storage::disk($disk)->put($path, $binary);

            return $path;
        } finally {
            imagedestroy($image);
        }
    }

    /** ينشئ صورة GD من الملف المرفوع (يدعم JPG/PNG/GIF/WebP) أو null عند الفشل. */
    private function readImage(UploadedFile $file): ?\GdImage
    {
        if (! function_exists('imagecreatefromstring') || ! function_exists('imagewebp')) {
            return null;
        }

        $data = @file_get_contents($file->getRealPath());
        if ($data === false) {
            return null;
        }

        $image = @imagecreatefromstring($data);

        return $image instanceof \GdImage ? $image : null;
    }

    /** يقلّص الأبعاد إن تجاوزت الحدّ الأقصى مع الحفاظ على النسبة والشفافية. */
    private function downscale(\GdImage $src): \GdImage
    {
        $w = imagesx($src);
        $h = imagesy($src);
        if ($w <= $this->maxWidth && $h <= $this->maxHeight) {
            return $src;
        }

        $ratio = min($this->maxWidth / $w, $this->maxHeight / $h);
        $nw = max(1, (int) floor($w * $ratio));
        $nh = max(1, (int) floor($h * $ratio));

        $dst = imagecreatetruecolor($nw, $nh);
        imagealphablending($dst, false);
        imagesavealpha($dst, true);
        imagecopyresampled($dst, $src, 0, 0, 0, 0, $nw, $nh, $w, $h);
        imagedestroy($src);

        return $dst;
    }
}
