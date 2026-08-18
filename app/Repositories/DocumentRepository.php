<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Contracts\Repositories\DocumentRepositoryInterface;
use App\DTO\DocumentFile;
use App\Exceptions\OcrException;
use Illuminate\Http\UploadedFile;

final class DocumentRepository implements DocumentRepositoryInterface
{
    public const SOURCE_PATH   = 'path';
    public const SOURCE_BASE64 = 'base64';
    public const SOURCE_UPLOAD = 'upload';

    /** Magic bytes per jenis berkas — dicek agar ekstensi tidak bisa dipalsukan. */
    private const MAGIC = [
        'pdf'  => ["%PDF"],
        'jpg'  => ["\xFF\xD8\xFF"],
        'jpeg' => ["\xFF\xD8\xFF"],
        'png'  => ["\x89PNG\r\n\x1A\n"],
        'tif'  => ["II*\x00", "MM\x00*"],
        'tiff' => ["II*\x00", "MM\x00*"],
        'bmp'  => ['BM'],
    ];

    public function resolve(array $source, ?UploadedFile $uploaded = null): DocumentFile
    {
        return match ($source['type'] ?? '') {
            self::SOURCE_PATH   => $this->fromPath((string) ($source['value'] ?? '')),
            self::SOURCE_BASE64 => $this->fromBase64((string) ($source['value'] ?? ''), $source['filename'] ?? 'document.pdf'),
            self::SOURCE_UPLOAD => $this->fromUpload($uploaded),
            default             => throw OcrException::invalidPayload('source.type tidak dikenal.'),
        };
    }

    public function release(DocumentFile $file): void
    {
        if ($file->isTemporary && is_file($file->path)) {
            @unlink($file->path);
        }
    }

    private function fromPath(string $path): DocumentFile
    {
        if ($path === '') {
            throw OcrException::invalidPayload('source.value wajib diisi untuk type "path".');
        }

        $real = realpath($path);
        if ($real === false || ! is_file($real)) {
            throw OcrException::fileNotFound('Berkas tidak ditemukan.');
        }

        $this->assertWithinAllowedBasePaths($real);

        $extension = $this->extensionOf($real);
        $size      = (int) filesize($real);

        $this->assertSize($size);
        $this->assertExtension($extension);
        $this->assertMagicBytes($real, $extension);

        return new DocumentFile(
            path: $real,
            extension: $extension,
            sizeBytes: $size,
            originalName: basename($real),
            isTemporary: false,
        );
    }

    private function fromBase64(string $encoded, string $filename): DocumentFile
    {
        if ($encoded === '') {
            throw OcrException::invalidPayload('source.value wajib diisi untuk type "base64".');
        }

        // Toleransi data URI: "data:application/pdf;base64,JVBERi0..."
        if (str_contains($encoded, ',') && str_starts_with($encoded, 'data:')) {
            $encoded = substr($encoded, strpos($encoded, ',') + 1);
        }

        $binary = base64_decode($encoded, true);
        if ($binary === false || $binary === '') {
            throw OcrException::invalidPayload('source.value bukan base64 yang valid.');
        }

        $this->assertSize(strlen($binary));

        $extension = $this->extensionOf($filename);
        $this->assertExtension($extension);

        $target = $this->tempPath($extension);
        file_put_contents($target, $binary);

        $this->assertMagicBytes($target, $extension, cleanupOnFail: $target);

        return new DocumentFile(
            path: $target,
            extension: $extension,
            sizeBytes: strlen($binary),
            originalName: basename($filename),
            isTemporary: true,
        );
    }

    private function fromUpload(?UploadedFile $uploaded): DocumentFile
    {
        if (! $uploaded instanceof UploadedFile || ! $uploaded->isValid()) {
            throw OcrException::invalidPayload('Berkas upload tidak ditemukan atau rusak.');
        }

        $size = (int) $uploaded->getSize();
        $this->assertSize($size);

        $extension = $this->extensionOf($uploaded->getClientOriginalName());
        $this->assertExtension($extension);

        $target = $this->tempPath($extension);
        $uploaded->move(dirname($target), basename($target));

        $this->assertMagicBytes($target, $extension, cleanupOnFail: $target);

        return new DocumentFile(
            path: $target,
            extension: $extension,
            sizeBytes: $size,
            originalName: $uploaded->getClientOriginalName(),
            isTemporary: true,
        );
    }

    private function assertWithinAllowedBasePaths(string $real): void
    {
        $allowed = (array) config('ocr.allowed_base_paths', []);
        if ($allowed === []) {
            throw OcrException::pathNotAllowed('Tidak ada base path yang diizinkan. Set OCR_ALLOWED_BASE_PATHS.');
        }

        $needle = $this->normalize($real);

        foreach ($allowed as $base) {
            $baseReal = realpath($base);
            if ($baseReal === false) {
                continue;
            }

            $prefix = rtrim($this->normalize($baseReal), '/').'/';
            if (str_starts_with($needle, $prefix)) {
                return;
            }
        }

        throw OcrException::pathNotAllowed('Path berada di luar direktori yang diizinkan.');
    }

    /** Windows tidak membedakan huruf besar/kecil pada path — samakan sebelum dibandingkan. */
    private function normalize(string $path): string
    {
        $normalized = str_replace('\\', '/', $path);

        return DIRECTORY_SEPARATOR === '\\' ? mb_strtolower($normalized) : $normalized;
    }

    private function assertSize(int $size): void
    {
        if ($size <= 0) {
            throw OcrException::invalidPayload('Berkas kosong.');
        }

        $max = (int) config('ocr.limits.max_file_bytes');
        if ($size > $max) {
            throw OcrException::fileTooLarge("Ukuran berkas melebihi batas {$max} byte.");
        }
    }

    private function assertExtension(string $extension): void
    {
        if (! in_array($extension, (array) config('ocr.allowed_extensions', []), true)) {
            throw OcrException::unsupportedMediaType("Ekstensi '{$extension}' tidak didukung.");
        }
    }

    private function assertMagicBytes(string $path, string $extension, ?string $cleanupOnFail = null): void
    {
        $signatures = self::MAGIC[$extension] ?? null;
        if ($signatures === null) {
            return;
        }

        $handle = fopen($path, 'rb');
        $head   = $handle ? (string) fread($handle, 16) : '';
        if ($handle) {
            fclose($handle);
        }

        foreach ($signatures as $signature) {
            if (str_starts_with($head, $signature)) {
                return;
            }
        }

        if ($cleanupOnFail !== null && is_file($cleanupOnFail)) {
            @unlink($cleanupOnFail);
        }

        throw OcrException::unsupportedMediaType('Isi berkas tidak cocok dengan ekstensinya.');
    }

    private function extensionOf(string $filename): string
    {
        return mb_strtolower(pathinfo($filename, PATHINFO_EXTENSION));
    }

    private function tempPath(string $extension): string
    {
        $dir = rtrim((string) config('ocr.workspace_path'), '/\\').DIRECTORY_SEPARATOR.'upload';

        if (! is_dir($dir)) {
            mkdir($dir, 0775, true);
        }

        return $dir.DIRECTORY_SEPARATOR.bin2hex(random_bytes(12)).'.'.$extension;
    }
}
