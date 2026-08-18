<?php

declare(strict_types=1);

namespace App\DTO;

final class AnalyzeOptions
{
    public function __construct(
        public readonly int $maxPages,
        public readonly ?string $lang = null,
        public readonly bool $returnRawText = true,
        public readonly bool $returnWords = false,
        public readonly bool $forceOcr = false,
    ) {
    }

    public static function fromArray(array $options): self
    {
        $max      = (int) ($options['max_pages'] ?? config('ocr.limits.max_pages'));
        $hardCap  = (int) config('ocr.limits.max_pages_hard');

        return new self(
            maxPages: max(1, min($max, $hardCap)),
            lang: $options['lang'] ?? null,
            returnRawText: (bool) ($options['return_raw_text'] ?? true),
            returnWords: (bool) ($options['return_words'] ?? false),
            forceOcr: (bool) ($options['force_ocr'] ?? false),
        );
    }
}
