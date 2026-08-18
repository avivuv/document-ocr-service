<?php

declare(strict_types=1);

namespace App\DTO;

final class Classification
{
    public function __construct(
        public readonly ?string $docType,
        public readonly ?float $confidence,
    ) {
    }

    public static function undetermined(): self
    {
        return new self(null, null);
    }
}
