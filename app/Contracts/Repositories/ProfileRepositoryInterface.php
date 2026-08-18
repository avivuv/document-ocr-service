<?php

declare(strict_types=1);

namespace App\Contracts\Repositories;

/**
 * Profil pemrosesan per jenis dokumen (psm, dpi, preprocessing, bahasa).
 *
 * Saat ini bersumber dari config. Antarmuka ini membuatnya bisa dipindah ke
 * database di kemudian hari tanpa menyentuh service.
 */
interface ProfileRepositoryInterface
{
    /** @return array{psm:int,dpi:int,preprocess:string,lang:string} */
    public function forDocType(?string $docType): array;

    /** @return string[] Argumen ImageMagick untuk profil preprocessing. */
    public function preprocessArgs(string $profileName): array;
}
