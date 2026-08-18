<?php

declare(strict_types=1);

namespace App\Contracts\Repositories;

/**
 * Satu-satunya pintu ke proses eksternal.
 *
 * Argumen selalu dikirim sebagai array (bukan string shell), sehingga tidak ada
 * jalur command injection dan tidak perlu escaping manual. Seluruh service
 * memanggil binary lewat sini, tidak pernah langsung.
 */
interface BinaryRepositoryInterface
{
    /**
     * @param string[] $args
     * @return string stdout
     */
    public function run(string $binKey, array $args, ?int $timeout = null): string;

    public function version(string $binKey): ?string;

    public function isAvailable(string $binKey): bool;
}
