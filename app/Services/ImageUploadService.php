<?php

namespace App\Services;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

/**
 * Stores an uploaded photo (room or workspace gallery) on the `public` disk
 * under a generated filename, plus a best-effort 640px WebP thumbnail.
 *
 * Callers own authorization and the database row; this class only touches
 * files, and only the paths it was given or just wrote. If storing fails
 * part-way, everything it wrote is removed before the exception is rethrown.
 */
class ImageUploadService
{
    public const DISK = 'public';

    public const THUMBNAIL_MAX_DIMENSION = 640;

    /** Above this many pixels GD would need hundreds of MB to decode — skip the thumbnail instead. */
    private const THUMBNAIL_MAX_SOURCE_PIXELS = 40_000_000;

    /**
     * @return array{path: string, thumbnail_path: ?string}
     */
    public function store(UploadedFile $file, string $directory): array
    {
        $path = null;
        $thumbnail = null;

        try {
            $path = $file->store($directory, self::DISK); // random hash filename, never the client's name
            if (! is_string($path) || $path === '') {
                throw new \RuntimeException('The image could not be stored.');
            }
            $thumbnail = $this->makeThumbnail($file, $path);

            return ['path' => $path, 'thumbnail_path' => $thumbnail];
        } catch (\Throwable $e) {
            $this->delete($path, $thumbnail);
            throw $e;
        }
    }

    /** Deletes the given paths (nulls ignored) from the public disk. */
    public function delete(?string ...$paths): void
    {
        $paths = array_values(array_filter($paths));
        if ($paths !== []) {
            Storage::disk(self::DISK)->delete($paths);
        }
    }

    /** Best-effort: a thumbnail failure never blocks the upload — models fall back to the full image. */
    protected function makeThumbnail(UploadedFile $file, string $originalPath): ?string
    {
        if (! function_exists('imagecreatefromstring')) {
            return null;
        }

        try {
            $size = @getimagesize($file->getRealPath());
            if (! $size || $size[0] * $size[1] > self::THUMBNAIL_MAX_SOURCE_PIXELS) {
                return null;
            }

            $source = @imagecreatefromstring(file_get_contents($file->getRealPath()));
            if (! $source) {
                return null;
            }

            $width = imagesx($source);
            $height = imagesy($source);
            $scale = min(1, self::THUMBNAIL_MAX_DIMENSION / max($width, $height));
            $targetW = max(1, (int) round($width * $scale));
            $targetH = max(1, (int) round($height * $scale));

            $thumb = imagecreatetruecolor($targetW, $targetH);
            imagesavealpha($thumb, true);
            imagefill($thumb, 0, 0, imagecolorallocatealpha($thumb, 0, 0, 0, 127));
            imagecopyresampled($thumb, $source, 0, 0, 0, 0, $targetW, $targetH, $width, $height);
            imagedestroy($source);

            $thumbPath = preg_replace('/\.[^.]+$/', '', $originalPath).'-thumb.webp';
            $tmp = tempnam(sys_get_temp_dir(), 'ls-thumb');
            imagewebp($thumb, $tmp, 80);
            imagedestroy($thumb);

            Storage::disk(self::DISK)->put($thumbPath, file_get_contents($tmp));
            @unlink($tmp);

            return $thumbPath;
        } catch (\Throwable) {
            return null;
        }
    }
}
