<?php

declare(strict_types=1);

namespace App\DTO;

/**
 * Berkas dokumen yang sudah tersedia sebagai file lokal dan lolos validasi.
 *
 * $isTemporary menandai file hasil salinan (base64 / upload multipart) yang
 * wajib dihapus setelah proses selesai. File milik consumer (source "path") tidak
 * pernah ditandai temporary — service hanya membacanya.
 */
final class DocumentFile
{
    public function __construct(
        public readonly string $path,
        public readonly string $extension,
        public readonly int $sizeBytes,
        public readonly string $originalName,
        public readonly bool $isTemporary = false,
    ) {
    }

    public function isPdf(): bool
    {
        return $this->extension === 'pdf';
    }
}
