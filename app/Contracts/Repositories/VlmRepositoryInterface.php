<?php

declare(strict_types=1);

namespace App\Contracts\Repositories;

/**
 * Satu-satunya pintu ke host model VLM.
 *
 * Aksesnya lewat HTTP, bukan proses eksternal, sehingga tidak melewati
 * BinaryRepository. Alasan pemisahannya tetap sama: service tidak pernah
 * menyentuh I/O secara langsung (RULES.md §1).
 */
interface VlmRepositoryInterface
{
    /** Transkripsikan seluruh teks pada satu berkas gambar. */
    public function transcribe(string $imagePath): string;

    public function isAvailable(): bool;

    public function model(): string;
}
