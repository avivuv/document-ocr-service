<?php

declare(strict_types=1);

namespace App\DTO;

/**
 * Hasil penyelidikan text layer sebuah PDF.
 *
 * $hasTextLayer false berarti dokumen harus ditempuh lewat jalur OCR — baik
 * karena benar-benar hasil scan, maupun karena text layer-nya kotor (PDF scan
 * yang sudah di-OCR mesin pemindai).
 */
final class TextLayerProbe
{
    public function __construct(
        public readonly bool $hasTextLayer,
        public readonly string $text,
        public readonly int $pageCount,
        public readonly int $pagesRead,
    ) {
    }

    public static function absent(int $pageCount = 1): self
    {
        return new self(false, '', $pageCount, 0);
    }
}
