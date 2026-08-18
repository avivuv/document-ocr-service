<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Contracts\Repositories\BinaryRepositoryInterface;
use App\Exceptions\OcrException;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Process;

final class BinaryRepository implements BinaryRepositoryInterface
{
    /** Argumen penanya versi berbeda-beda antar binary. */
    private const VERSION_ARGS = [
        'tesseract' => ['--version'],
        'pdftotext' => ['-v'],
        'pdftoppm'  => ['-v'],
        'pdfinfo'   => ['-v'],
        'magick'    => ['-version'],
        'gs'        => ['--version'],
    ];

    /** @var array<string,string|null> */
    private array $versionCache = [];

    public function run(string $binKey, array $args, ?int $timeout = null): string
    {
        $binary = $this->binaryPath($binKey);

        $process = new Process([$binary, ...$args]);
        $process->setTimeout((float) ($timeout ?? config('ocr.timeout.ocr')));

        try {
            $process->run();
        } catch (ProcessTimedOutException) {
            throw OcrException::timeout("Proses {$binKey} melewati batas waktu.");
        }

        if (! $process->isSuccessful()) {
            $stderr = trim($process->getErrorOutput()) ?: trim($process->getOutput());

            throw OcrException::engineFailure(
                "Proses {$binKey} gagal (exit {$process->getExitCode()}): ".mb_substr($stderr, 0, 500)
            );
        }

        return $process->getOutput();
    }

    public function version(string $binKey): ?string
    {
        if (array_key_exists($binKey, $this->versionCache)) {
            return $this->versionCache[$binKey];
        }

        $binary = config("ocr.bin.{$binKey}");
        if (! is_string($binary) || $binary === '') {
            return $this->versionCache[$binKey] = null;
        }

        $process = new Process([$binary, ...(self::VERSION_ARGS[$binKey] ?? ['--version'])]);
        $process->setTimeout((float) config('ocr.timeout.version'));

        try {
            $process->run();
        } catch (\Throwable) {
            return $this->versionCache[$binKey] = null;
        }

        // Sebagian binary menulis versi ke stderr (mis. pdftotext), bukan stdout.
        $output = trim($process->getOutput()) ?: trim($process->getErrorOutput());
        if ($output === '') {
            return $this->versionCache[$binKey] = null;
        }

        // Exit code tidak bisa dijadikan penentu: Xpdf mengembalikan 99 pada
        // "pdftotext -v" meski berhasil. Yang menentukan adalah ada tidaknya
        // nomor versi — pesan "'magick' is not recognized" tidak memuatnya.
        $head = implode("\n", array_slice(preg_split('/\R/', $output) ?: [], 0, 2));

        if (preg_match('/(\d+\.\d+(?:\.\d+)?)/', $head, $matches) !== 1) {
            return $this->versionCache[$binKey] = null;
        }

        return $this->versionCache[$binKey] = $matches[1];
    }

    public function isAvailable(string $binKey): bool
    {
        return $this->version($binKey) !== null;
    }

    private function binaryPath(string $binKey): string
    {
        $binary = config("ocr.bin.{$binKey}");

        if (! is_string($binary) || $binary === '') {
            throw OcrException::engineFailure("Binary '{$binKey}' belum dikonfigurasi.");
        }

        return $binary;
    }
}
