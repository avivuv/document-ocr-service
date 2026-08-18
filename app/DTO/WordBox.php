<?php

declare(strict_types=1);

namespace App\DTO;

/**
 * Satu kata hasil OCR beserta confidence dan posisinya di halaman.
 *
 * bbox dipertahankan sampai ke response supaya consumer bisa menyorot lokasi
 * field di atas gambar dokumen. Menambahkannya belakangan berarti menaikkan
 * versi API, jadi ia ikut sejak awal meski belum dipakai.
 */
final class WordBox
{
    public function __construct(
        public readonly string $text,
        public readonly float $confidence,
        public readonly int $x,
        public readonly int $y,
        public readonly int $width,
        public readonly int $height,
        public readonly int $page = 1,
    ) {
    }

    /** @return array{0:int,1:int,2:int,3:int} */
    public function bbox(): array
    {
        return [$this->x, $this->y, $this->width, $this->height];
    }
}
