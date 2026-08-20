<?php

declare(strict_types=1);

namespace App\Engines;

use App\Contracts\OcrEngineInterface;
use App\Contracts\Repositories\VlmRepositoryInterface;
use App\DTO\PageResult;

/**
 * Engine yang membaca gambar memakai VLM lokal.
 *
 * Model diminta mentranskripsi apa adanya, bukan mengekstrak field. Hasilnya
 * berupa teks yang masuk ke parser yang sudah ada — jalur ekstraksi tetap
 * deterministik dan dapat diuji dengan fixture.
 *
 * PageResult::words sengaja kosong: VLM tidak memberi koordinat kata, sehingga
 * bbox pada mode ini bernilai null. Kontrak API sudah mengizinkannya.
 */
final class VlmEngine implements OcrEngineInterface
{
    public function __construct(private readonly VlmRepositoryInterface $vlm)
    {
    }

    public function name(): string
    {
        return 'vlm';
    }

    public function version(): string
    {
        return $this->vlm->model();
    }

    public function isAvailable(): bool
    {
        return $this->vlm->isAvailable();
    }

    public function recognize(string $imagePath, int $pageNo, array $options = []): PageResult
    {
        $text = $this->vlm->transcribe($imagePath);

        /*
         * avgConfidence null, bukan sebuah angka: model tidak melaporkan
         * keyakinan per kata, dan mengarang angka di sini akan membuat seluruh
         * pertahanan false-positive kehilangan makna.
         */
        return new PageResult(
            pageNo: $pageNo,
            text: $text,
            words: [],
            avgConfidence: null,
        );
    }
}
