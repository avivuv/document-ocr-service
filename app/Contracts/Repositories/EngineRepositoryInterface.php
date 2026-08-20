<?php

declare(strict_types=1);

namespace App\Contracts\Repositories;

use App\Contracts\OcrEngineInterface;

/**
 * Pemilihan engine berdasarkan config — termasuk override per jenis dokumen,
 * jalan keluar bila suatu saat KTP perlu engine lain tanpa mengubah kontrak API.
 *
 * Urutan yang menang: pilihan per permintaan, lalu override per jenis dokumen,
 * baru default.
 */
interface EngineRepositoryInterface
{
    public function forDocType(?string $docType, ?string $override = null): OcrEngineInterface;

    public function default(): OcrEngineInterface;
}
