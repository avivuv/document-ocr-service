<?php

declare(strict_types=1);

namespace App\DTO;

/**
 * Satu field hasil ekstraksi.
 *
 * $value ternormalisasi dan siap masuk kolom database consumer; $raw adalah
 * bentuk apa adanya di dokumen, dipakai untuk audit dan ditampilkan ke user
 * saat review. Keduanya dipisah supaya normalisasi tidak bocor ke dua tempat.
 */
final class FieldResult
{
    /** @param array{0:int,1:int,2:int,3:int}|null $bbox */
    public function __construct(
        public readonly string $name,
        public readonly string $value,
        public readonly string $raw,
        public readonly float $confidence,
        public readonly int $page = 1,
        public readonly ?array $bbox = null,
    ) {
    }
}
