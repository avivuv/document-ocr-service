<?php

declare(strict_types=1);

namespace App\Contracts\Repositories;

use App\DTO\DocumentFile;
use Illuminate\Http\UploadedFile;

/**
 * Akses ke berkas dokumen, apa pun asalnya (path server, base64, upload
 * multipart). Seluruh validasi berkas — keberadaan, ukuran, ekstensi, dan
 * batas direktori yang boleh dibaca — menjadi tanggung jawab lapis ini,
 * bukan service.
 */
interface DocumentRepositoryInterface
{
    /**
     * @param array{type:string,value?:string} $source
     */
    public function resolve(array $source, ?UploadedFile $uploaded = null): DocumentFile;

    /** Hapus berkas bila ia salinan sementara. Aman dipanggil berkali-kali. */
    public function release(DocumentFile $file): void;
}
