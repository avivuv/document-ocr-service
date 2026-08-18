<?php

declare(strict_types=1);

namespace App\Services;

use App\Contracts\Repositories\ParserRepositoryInterface;
use App\Exceptions\OcrException;

final class ExtractionService
{
    public function __construct(private readonly ParserRepositoryInterface $parsers)
    {
    }

    /**
     * @param \App\DTO\WordBox[] $words
     * @return \App\DTO\FieldResult[]
     */
    public function extract(string $docType, string $text, array $words = []): array
    {
        $parser = $this->parsers->for($docType);

        if ($parser === null) {
            throw OcrException::invalidPayload("Jenis dokumen '{$docType}' belum didukung.");
        }

        return $parser->parse($text, $words);
    }
}
