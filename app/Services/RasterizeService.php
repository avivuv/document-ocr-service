<?php

declare(strict_types=1);

namespace App\Services;

use App\Contracts\Repositories\BinaryRepositoryInterface;
use App\Contracts\Repositories\WorkspaceRepositoryInterface;
use App\DTO\DocumentFile;
use App\Exceptions\OcrException;

final class RasterizeService
{
    private const PREFIX = 'page';

    public function __construct(
        private readonly BinaryRepositoryInterface $binaries,
        private readonly WorkspaceRepositoryInterface $workspaces,
    ) {
    }

    /**
     * @return string[] daftar berkas PNG terurut per halaman
     */
    public function toPng(DocumentFile $file, string $workspace, int $dpi, int $maxPages): array
    {
        if (! $file->isPdf()) {
            return [$file->path];
        }

        $prefix = $workspace.DIRECTORY_SEPARATOR.self::PREFIX;

        if ($this->binaries->isAvailable('pdftoppm')) {
            $this->runPdftoppm($file, $prefix, $dpi, $maxPages);
        } else {
            $this->runGhostscript($file, $prefix, $dpi, $maxPages);
        }

        $images = $this->workspaces->filesIn($workspace, self::PREFIX.'*.png');

        if ($images === []) {
            throw OcrException::unreadableDocument('Dokumen tidak menghasilkan halaman yang bisa dibaca.');
        }

        return array_slice($images, 0, $maxPages);
    }

    private function runPdftoppm(DocumentFile $file, string $prefix, int $dpi, int $maxPages): void
    {
        $this->binaries->run('pdftoppm', [
            '-r', (string) $this->effectiveDpi($file, $dpi),
            '-png',
            '-f', '1',
            '-l', (string) $maxPages,
            $file->path,
            $prefix,
        ], (int) config('ocr.timeout.rasterize'));
    }

    /**
     * dpi diturunkan bila ukuran halaman membuat hasilnya melewati batas piksel.
     *
     * Halaman A4 pada 300 dpi menghasilkan 2.480x3.508 px — wajar. Tetapi PDF
     * hasil bungkus foto memakai ukuran halaman sebesar piksel gambarnya, jadi
     * foto 4000x3000 menjadi halaman seluas 55x42 inci; pada 300 dpi hasilnya
     * 208 megapiksel. Terbukti memakan dua menit dan berakhir tanpa satu pun
     * field terbaca.
     */
    private function effectiveDpi(DocumentFile $file, int $dpi): int
    {
        $longestPoints = $this->longestPageSideInPoints($file);
        $maxPixels     = (int) config('ocr.limits.max_raster_px', 3500);

        if ($longestPoints === null || $longestPoints <= 0.0 || $maxPixels <= 0) {
            return $dpi;
        }

        $allowed = (int) floor(($maxPixels * 72) / $longestPoints);

        // Tanpa lantai selain 1: pada halaman raksasa, dpi rendah justru yang
        // benar — yang dibatasi adalah jumlah piksel keluaran, bukan dpi-nya.
        return max(1, min($dpi, $allowed));
    }

    /** Ukuran halaman hanya diketahui lewat pdfinfo; tanpa itu dpi dipakai apa adanya. */
    private function longestPageSideInPoints(DocumentFile $file): ?float
    {
        if (! $this->binaries->isAvailable('pdfinfo')) {
            return null;
        }

        try {
            $output = $this->binaries->run(
                'pdfinfo',
                [$file->path],
                (int) config('ocr.timeout.probe')
            );
        } catch (OcrException) {
            return null;
        }

        if (preg_match('/^Page size:\s+([\d.]+)\s+x\s+([\d.]+)\s+pts/mi', $output, $matches) !== 1) {
            return null;
        }

        return max((float) $matches[1], (float) $matches[2]);
    }

    /** Ghostscript dipakai bila poppler tidak terpasang — keluarannya setara. */
    private function runGhostscript(DocumentFile $file, string $prefix, int $dpi, int $maxPages): void
    {
        if (! $this->binaries->isAvailable('gs')) {
            throw OcrException::engineFailure('Tidak ada binary rasterisasi (pdftoppm maupun Ghostscript).');
        }

        $this->binaries->run('gs', [
            '-dNOPAUSE',
            '-dBATCH',
            '-dSAFER',
            '-sDEVICE=png16m',
            '-r'.$this->effectiveDpi($file, $dpi),
            '-dFirstPage=1',
            '-dLastPage='.$maxPages,
            '-sOutputFile='.$prefix.'-%03d.png',
            $file->path,
        ], (int) config('ocr.timeout.rasterize'));
    }
}
