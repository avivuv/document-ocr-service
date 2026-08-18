<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Contracts\Repositories\WorkspaceRepositoryInterface;
use App\Exceptions\OcrException;
use FilesystemIterator;
use Illuminate\Support\Facades\Log;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

final class WorkspaceRepository implements WorkspaceRepositoryInterface
{
    private const TRIM_CHARS = '/'.DIRECTORY_SEPARATOR;

    private const DESTROY_ATTEMPTS = 3;

    private const DESTROY_BACKOFF_MICROSECONDS = 200_000;

    public function create(string $name): string
    {
        $directory = $this->root().DIRECTORY_SEPARATOR.$name;

        if (! is_dir($directory) && ! @mkdir($directory, 0775, true) && ! is_dir($directory)) {
            throw OcrException::engineFailure('Gagal membuat direktori kerja.');
        }

        return $directory;
    }

    /**
     * Proses eksternal yang baru dihentikan paksa — Tesseract saat timeout —
     * masih memegang berkas keluarannya sesaat, dan Windows menolak menghapus
     * berkas yang terkunci. Percobaan tunggal gagal diam-diam dan meninggalkan
     * berkas turunan berisi data pribadi, melanggar batasan proyek §2.
     */
    public function destroy(string $directory): void
    {
        if (! is_dir($directory) || ! $this->isInsideRoot($directory)) {
            return;
        }

        for ($attempt = 1; $attempt <= self::DESTROY_ATTEMPTS; $attempt++) {
            $this->deleteTree($directory);

            if (! is_dir($directory)) {
                return;
            }

            if ($attempt < self::DESTROY_ATTEMPTS) {
                usleep(self::DESTROY_BACKOFF_MICROSECONDS);
            }
        }

        // Sisa ini akan dibersihkan purgeStale() pada request berikutnya, tetapi
        // kegagalannya harus terlihat karena menyangkut berkas ber-PII.
        Log::warning('ocr.workspace.tidak_terhapus', ['directory' => $directory]);
    }

    private function deleteTree(string $directory): void
    {
        $items = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );

        foreach ($items as $item) {
            $item->isDir() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
        }

        @rmdir($directory);
    }

    public function filesIn(string $directory, string $pattern = '*'): array
    {
        $found = glob(rtrim($directory, self::TRIM_CHARS).DIRECTORY_SEPARATOR.$pattern);

        if ($found === false) {
            return [];
        }

        sort($found, SORT_NATURAL);

        return array_values(array_filter($found, 'is_file'));
    }

    public function purgeStale(int $olderThanHours): int
    {
        $root = $this->root();
        if (! is_dir($root)) {
            return 0;
        }

        $threshold = time() - ($olderThanHours * 3600);
        $purged    = 0;

        foreach (new FilesystemIterator($root, FilesystemIterator::SKIP_DOTS) as $item) {
            if (! $item->isDir() || $item->getMTime() > $threshold) {
                continue;
            }

            $this->destroy($item->getPathname());
            $purged++;
        }

        return $purged;
    }

    private function root(): string
    {
        $root = rtrim((string) config('ocr.workspace_path'), self::TRIM_CHARS);

        if (! is_dir($root)) {
            @mkdir($root, 0775, true);
        }

        return $root;
    }

    /** Penjaga: destroy() tidak boleh bisa dipakai menghapus direktori di luar workspace. */
    private function isInsideRoot(string $directory): bool
    {
        $real = realpath($directory);
        $root = realpath($this->root());

        if ($real === false || $root === false) {
            return false;
        }

        return str_starts_with($real, $root.DIRECTORY_SEPARATOR);
    }
}
