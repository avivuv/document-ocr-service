<?php

declare(strict_types=1);

namespace App\Contracts\Repositories;

/**
 * Direktori kerja sementara. Dipisah dari service supaya seluruh operasi
 * filesystem tetap berada di lapis repository (RULES §1.2).
 */
interface WorkspaceRepositoryInterface
{
    public function create(string $name): string;

    public function destroy(string $directory): void;

    /** @return string[] */
    public function filesIn(string $directory, string $pattern = '*'): array;

    /** @return int jumlah direktori usang yang dihapus */
    public function purgeStale(int $olderThanHours): int;
}
