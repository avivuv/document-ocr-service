<?php

declare(strict_types=1);

namespace App\Services;

use App\Contracts\Repositories\BinaryRepositoryInterface;
use App\Contracts\Repositories\ProfileRepositoryInterface;

/**
 * Perbaikan citra sebelum OCR: deskew, grayscale, ambang batas lokal.
 *
 * Bersifat menaikkan mutu, bukan syarat. Bila ImageMagick tidak terpasang,
 * gambar diteruskan apa adanya — akurasi turun, tetapi request tetap berhasil.
 */
final class PreprocessService
{
    public function __construct(
        private readonly BinaryRepositoryInterface $binaries,
        private readonly ProfileRepositoryInterface $profiles,
    ) {
    }

    public function isAvailable(): bool
    {
        return $this->binaries->isAvailable('magick');
    }

    /**
     * @param string[] $images
     * @return string[]
     */
    public function apply(array $images, string $workspace, string $profileName): array
    {
        $args = $this->profiles->preprocessArgs($profileName);

        if ($args === [] || ! $this->isAvailable()) {
            return $images;
        }

        $processed = [];
        $timeout   = (int) config('ocr.timeout.preprocess');

        foreach ($images as $index => $image) {
            $target = $workspace.DIRECTORY_SEPARATOR.'pre-'.str_pad((string) ($index + 1), 3, '0', STR_PAD_LEFT).'.png';

            $this->binaries->run('magick', [$image, ...$args, $target], $timeout);

            $processed[] = $target;
        }

        return $processed;
    }
}
