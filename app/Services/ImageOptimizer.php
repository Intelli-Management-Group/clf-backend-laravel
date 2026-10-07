<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;

/**
 * Compresses JPEG, PNG and WebP images in place with GD: applies the EXIF
 * orientation, scales anything larger than `max_dimension` down and
 * re-encodes it. The original is kept whenever the result is not smaller
 * (or GD cannot read the file), so this never makes an image worse.
 */
class ImageOptimizer
{
    /**
     * @var int
     */
    private $maxDimension;

    /**
     * @var int
     */
    private $jpegQuality;

    /**
     * @var int
     */
    private $webpQuality;

    /**
     * Results that do not save at least this fraction are discarded.
     *
     * @var float
     */
    private $minSaving;

    public function __construct(int $maxDimension = 2000, int $jpegQuality = 80, int $webpQuality = 80, float $minSaving = 0.05)
    {
        $this->maxDimension = $maxDimension;
        $this->jpegQuality = $jpegQuality;
        $this->webpQuality = $webpQuality;
        $this->minSaving = $minSaving;
    }

    public static function available(): bool
    {
        return extension_loaded('gd');
    }

    /**
     * Compress the image at $path. Returns [bytes before, bytes after]
     * (equal when the file was left untouched).
     */
    public function optimize(string $path, bool $dryRun = false): array
    {
        $before = (int) filesize($path);
        $after = $before;

        if (! self::available() || $before === 0) {
            return [$before, $after];
        }

        $info = @getimagesize($path);
        if (! $info || ! in_array($info[2], [IMAGETYPE_JPEG, IMAGETYPE_PNG, IMAGETYPE_WEBP], true)) {
            return [$before, $after];
        }

        list($width, $height, $type) = $info;
        if (! $this->hasMemoryFor($width, $height)) {
            Log::warning("Image too large to compress, kept as is: {$path}");

            return [$before, $after];
        }

        $image = $this->load($path, $type);
        if (! $image) {
            return [$before, $after];
        }

        try {
            if ($type === IMAGETYPE_JPEG) {
                $image = $this->orient($image, $path);
            }
            $image = $this->downscale($image);

            $temp = $path.'.tmp';
            if (! $this->save($image, $temp, $type)) {
                @unlink($temp);

                return [$before, $after];
            }

            $size = (int) filesize($temp);
            $smaller = $size > 0 && $size <= $before * (1 - $this->minSaving);
            if ($smaller && ($dryRun || $this->replace($temp, $path))) {
                $after = $size;
            }

            @unlink($temp);
        } finally {
            imagedestroy($image);
        }

        return [$before, $after];
    }

    private function load(string $path, int $type)
    {
        switch ($type) {
            case IMAGETYPE_JPEG:
                return @imagecreatefromjpeg($path);
            case IMAGETYPE_PNG:
                return @imagecreatefrompng($path);
            case IMAGETYPE_WEBP:
                return @imagecreatefromwebp($path);
        }

        return false;
    }

    private function save($image, string $path, int $type): bool
    {
        switch ($type) {
            case IMAGETYPE_JPEG:
                imageinterlace($image, true);

                return imagejpeg($image, $path, $this->jpegQuality);
            case IMAGETYPE_PNG:
                imagesavealpha($image, true);

                return imagepng($image, $path, 9);
            case IMAGETYPE_WEBP:
                return imagewebp($image, $path, $this->webpQuality);
        }

        return false;
    }

    /**
     * Rotate according to the EXIF Orientation tag, which is lost on re-encoding.
     */
    private function orient($image, string $path)
    {
        $exif = function_exists('exif_read_data') ? @exif_read_data($path) : false;
        $orientation = $exif['Orientation'] ?? 1;

        $angle = [3 => 180, 6 => -90, 8 => 90][$orientation] ?? 0;
        if ($angle) {
            $rotated = imagerotate($image, $angle, 0);
            if ($rotated) {
                imagedestroy($image);
                $image = $rotated;
            }
        }

        return $image;
    }

    private function downscale($image)
    {
        $width = imagesx($image);
        $height = imagesy($image);
        $longest = max($width, $height);

        if ($this->maxDimension <= 0 || $longest <= $this->maxDimension) {
            return $image;
        }

        $ratio = $this->maxDimension / $longest;
        $scaled = imagescale($image, max(1, (int) round($width * $ratio)), max(1, (int) round($height * $ratio)), IMG_BICUBIC);

        if (! $scaled) {
            return $image;
        }

        imagedestroy($image);

        return $scaled;
    }

    private function replace(string $temp, string $path): bool
    {
        // rename() over an existing file is not atomic on Windows; copy instead.
        return @copy($temp, $path);
    }

    /**
     * GD keeps ~5 bytes per pixel, plus room for a scaled copy.
     */
    private function hasMemoryFor(int $width, int $height): bool
    {
        $limit = $this->bytes((string) ini_get('memory_limit'));

        return $limit <= 0 || memory_get_usage() + $width * $height * 5 * 2 < $limit;
    }

    private function bytes(string $value): int
    {
        $value = trim($value);
        $number = (int) $value;

        switch (strtolower(substr($value, -1))) {
            case 'g':
                return $number * 1024 * 1024 * 1024;
            case 'm':
                return $number * 1024 * 1024;
            case 'k':
                return $number * 1024;
        }

        return $number;
    }
}
