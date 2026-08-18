<?php

declare(strict_types=1);

namespace App\DTO;

final class AnalyzeResult
{
    public const MODE_TEXT_LAYER = 'TEXT_LAYER';
    public const MODE_OCR        = 'OCR';

    /**
     * @param FieldResult[] $fields
     * @param PageResult[]  $pages
     * @param string[]      $warnings
     */
    public function __construct(
        public readonly string $requestId,
        public readonly ?string $docType,
        public readonly ?float $docTypeConfidence,
        public readonly string $mode,
        public readonly string $engineName,
        public readonly string $engineVersion,
        public readonly string $lang,
        public readonly int $pageCount,
        public readonly int $pagesProcessed,
        public readonly int $processingMs,
        public readonly array $fields = [],
        public readonly array $pages = [],
        public readonly ?string $rawText = null,
        public readonly array $warnings = [],
    ) {
    }
}
