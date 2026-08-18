<?php

declare(strict_types=1);

namespace App\Contracts;

use App\DTO\FieldResult;

/**
 * Kontrak parser jenis dokumen.
 *
 * Implementasi WAJIB berupa fungsi murni: tanpa I/O, tanpa akses config global,
 * tanpa state. Inilah yang membuat parser bisa diuji tuntas dengan fixture teks
 * tanpa perlu binary OCR terpasang.
 */
interface DocumentParserInterface
{
    /** Kode kanonik jenis dokumen, mis. "NPWP". */
    public function docType(): string;

    /** Daftar nama field yang mungkin dikembalikan — dipakai endpoint /doc-types. */
    public function fieldNames(): array;

    /**
     * Skor 0..1 seberapa yakin teks ini adalah dokumen jenis tersebut.
     * Dipakai ClassificationService saat consumer tidak mengirim doc_type.
     */
    public function matchScore(string $text): float;

    /**
     * @param \App\DTO\WordBox[] $words Kosong bila sumbernya text layer.
     * @return FieldResult[]
     */
    public function parse(string $text, array $words = []): array;
}
