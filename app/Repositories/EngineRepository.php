<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Contracts\OcrEngineInterface;
use App\Contracts\Repositories\EngineRepositoryInterface;
use App\Exceptions\OcrException;

final class EngineRepository implements EngineRepositoryInterface
{
    /** @param array<string,OcrEngineInterface> $engines dikunci nama engine */
    public function __construct(private readonly array $engines)
    {
    }

    public function forDocType(?string $docType, ?string $override = null): OcrEngineInterface
    {
        if ($override !== null && $override !== '') {
            return $this->resolve($override);
        }

        $perDocType = $docType === null
            ? null
            : config('ocr.engine.per_doc_type.'.mb_strtoupper($docType));

        return $this->resolve(is_string($perDocType) ? $perDocType : $this->defaultName());
    }

    public function default(): OcrEngineInterface
    {
        return $this->resolve($this->defaultName());
    }

    private function defaultName(): string
    {
        return (string) config('ocr.engine.default', 'tesseract');
    }

    private function resolve(string $name): OcrEngineInterface
    {
        return $this->engines[$name]
            ?? throw OcrException::engineFailure("Engine '{$name}' tidak dikenal.");
    }
}
