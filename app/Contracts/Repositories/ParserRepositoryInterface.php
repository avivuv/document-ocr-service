<?php

declare(strict_types=1);

namespace App\Contracts\Repositories;

use App\Contracts\DocumentParserInterface;

interface ParserRepositoryInterface
{
    public function for(string $docType): ?DocumentParserInterface;

    /** @return DocumentParserInterface[] */
    public function all(): array;

    /** @return string[] */
    public function supportedDocTypes(): array;
}
