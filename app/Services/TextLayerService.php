<?php

declare(strict_types=1);

namespace App\Services;

use App\Contracts\Repositories\BinaryRepositoryInterface;
use App\DTO\DocumentFile;
use App\DTO\TextLayerProbe;
use App\Exceptions\OcrException;

/**
 * Jalur bernilai tertinggi service ini: NIB OSS, NPWP DJP, dan SK Kemenkumham
 * terbit sebagai PDF digital, sehingga pdftotext mengembalikan teks persis
 * tanpa OCR sama sekali.
 *
 * Kasus perantara yang harus ditangani: PDF hasil scan yang sudah di-OCR mesin
 * pemindai — text layer ada tapi kotor. Dideteksi lewat rasio karakter aneh.
 */
final class TextLayerService
{
    private const PAGE_SEPARATOR = "\f";

    public function __construct(private readonly BinaryRepositoryInterface $binaries)
    {
    }

    public function isEnabled(): bool
    {
        return (bool) config('ocr.text_layer.enabled', true);
    }

    public function version(): string
    {
        return $this->binaries->version('pdftotext') ?? 'unknown';
    }

    public function probe(DocumentFile $file, int $maxPages): TextLayerProbe
    {
        if (! $file->isPdf()) {
            return TextLayerProbe::absent();
        }

        $raw = $this->extract($file);
        if ($raw === null) {
            return TextLayerProbe::absent();
        }

        $pages     = $this->splitPages($raw);
        $pageCount = max(1, count($pages));
        $pagesRead = min($maxPages, $pageCount);
        $text      = implode(self::PAGE_SEPARATOR, array_slice($pages, 0, $pagesRead));

        if (! $this->isEnabled() || ! $this->isUsable($pages)) {
            return new TextLayerProbe(false, '', $pageCount, 0);
        }

        return new TextLayerProbe(true, $text, $pageCount, $pagesRead);
    }

    private function extract(DocumentFile $file): ?string
    {
        try {
            return $this->binaries->run(
                'pdftotext',
                ['-layout', '-q', $file->path, '-'],
                (int) config('ocr.timeout.probe')
            );
        } catch (OcrException) {
            // pdftotext tidak terpasang atau PDF tidak terbaca olehnya — jalur OCR
            // masih tersedia, jadi kegagalan di sini bukan kegagalan request.
            return null;
        }
    }

    /** @return string[] */
    private function splitPages(string $raw): array
    {
        $pages = explode(self::PAGE_SEPARATOR, $raw);

        // pdftotext menutup halaman terakhir dengan separator, menyisakan potongan kosong.
        if (end($pages) === '' && count($pages) > 1) {
            array_pop($pages);
        }

        return $pages;
    }

    /** @param string[] $pages */
    private function isUsable(array $pages): bool
    {
        $probePages = array_slice($pages, 0, (int) config('ocr.text_layer.probe_pages', 3));
        $minAlnum   = (int) config('ocr.text_layer.min_alnum_per_page', 200);

        foreach ($probePages as $page) {
            $alnum = preg_match_all('/[\p{L}\p{N}]/u', $page);

            if ($alnum >= $minAlnum && $this->garbageRatio($page) <= (float) config('ocr.text_layer.max_garbage_ratio', 0.30)) {
                return true;
            }
        }

        return false;
    }

    private function garbageRatio(string $page): float
    {
        $meaningful = preg_replace('/\s+/u', '', $page) ?? '';
        $length     = mb_strlen($meaningful);

        if ($length === 0) {
            return 1.0;
        }

        $recognised = preg_match_all('/[\p{L}\p{N}.,:;()\/&%+#@!?_"\x27-]/u', $meaningful);

        return 1.0 - ($recognised / $length);
    }
}
