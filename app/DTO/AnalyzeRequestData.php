<?php

declare(strict_types=1);

namespace App\DTO;

use Illuminate\Http\UploadedFile;

final class AnalyzeRequestData
{
    /** @param array{type:string,value?:string,filename?:string} $source */
    public function __construct(
        public readonly string $requestId,
        public readonly array $source,
        public readonly AnalyzeOptions $options,
        public readonly ?string $docType = null,
        public readonly ?UploadedFile $uploaded = null,
    ) {
    }
}
