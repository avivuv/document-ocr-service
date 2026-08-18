<?php

declare(strict_types=1);

namespace App\DTO;

final class PageResult
{
    /** @param WordBox[] $words */
    public function __construct(
        public readonly int $pageNo,
        public readonly string $text,
        public readonly array $words = [],
        public readonly ?float $avgConfidence = null,
    ) {
    }
}
