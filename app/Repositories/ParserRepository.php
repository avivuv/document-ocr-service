<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Contracts\DocumentParserInterface;
use App\Contracts\Repositories\ParserRepositoryInterface;

final class ParserRepository implements ParserRepositoryInterface
{
    /** @var array<string,DocumentParserInterface> */
    private array $parsers = [];

    /** @param DocumentParserInterface[] $parsers */
    public function __construct(array $parsers = [])
    {
        foreach ($parsers as $parser) {
            $this->parsers[mb_strtoupper($parser->docType())] = $parser;
        }
    }

    public function for(string $docType): ?DocumentParserInterface
    {
        return $this->parsers[mb_strtoupper($docType)] ?? null;
    }

    public function all(): array
    {
        return array_values($this->parsers);
    }

    public function supportedDocTypes(): array
    {
        return array_keys($this->parsers);
    }
}
