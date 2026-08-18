<?php

declare(strict_types=1);

namespace App\Contracts;

use App\DTO\PageResult;

/**
 * Kontrak engine OCR.
 *
 * Inilah katup pengaman arsitektur: mengganti Tesseract dengan engine lain
 * (sidecar Python, provider cloud, engine khusus KTP) cukup dengan menambah
 * implementasi baru dan mengubah config — kontrak API ke consumer tidak
 * berubah sama sekali.
 */
interface OcrEngineInterface
{
    public function name(): string;

    public function version(): string;

    public function isAvailable(): bool;

    /**
     * Kenali teks pada satu berkas gambar.
     *
     * @param array{psm?:int,lang?:string} $options
     */
    public function recognize(string $imagePath, int $pageNo, array $options = []): PageResult;
}
