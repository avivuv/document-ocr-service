<?php

declare(strict_types=1);

namespace App\Engines;

use App\Contracts\OcrEngineInterface;
use App\Contracts\Repositories\BinaryRepositoryInterface;
use App\DTO\PageResult;
use App\DTO\WordBox;

final class TesseractEngine implements OcrEngineInterface
{
    /** Kolom TSV keluaran Tesseract; hanya level 5 yang berupa kata. */
    private const TSV_LEVEL_WORD = 5;

    public function __construct(private readonly BinaryRepositoryInterface $binaries)
    {
    }

    public function name(): string
    {
        return 'tesseract';
    }

    public function version(): string
    {
        return $this->binaries->version('tesseract') ?? 'unknown';
    }

    public function isAvailable(): bool
    {
        return $this->binaries->isAvailable('tesseract');
    }

    public function recognize(string $imagePath, int $pageNo, array $options = []): PageResult
    {
        $outputBase = preg_replace('/\.[^.]+$/', '', $imagePath).'-ocr';
        $lang       = (string) ($options['lang'] ?? config('ocr.default_profile.lang'));
        $psm        = (int) ($options['psm'] ?? config('ocr.default_profile.psm'));

        try {
            $this->binaries->run('tesseract', [
                $imagePath,
                $outputBase,
                '-l', $lang,
                '--oem', '1',
                '--psm', (string) $psm,
                'tsv',
                'txt',
            ], (int) config('ocr.timeout.ocr'));

            $words = $this->parseTsv($outputBase.'.tsv', $pageNo);
            $text  = $this->readText($outputBase.'.txt', $words);

            return new PageResult(
                pageNo: $pageNo,
                text: $text,
                words: $words,
                avgConfidence: $this->averageConfidence($words),
            );
        } finally {
            foreach ([$outputBase.'.tsv', $outputBase.'.txt'] as $artifact) {
                if (is_file($artifact)) {
                    @unlink($artifact);
                }
            }
        }
    }

    /** @return WordBox[] */
    private function parseTsv(string $path, int $pageNo): array
    {
        if (! is_file($path)) {
            return [];
        }

        $handle = fopen($path, 'rb');
        if ($handle === false) {
            return [];
        }

        $words = [];

        try {
            fgets($handle); // baris header

            while (($line = fgets($handle)) !== false) {
                $columns = explode("\t", rtrim($line, "\r\n"));
                if (count($columns) < 12) {
                    continue;
                }

                // conf = -1 menandai baris struktur (blok/paragraf), bukan kata.
                $confidence = (float) $columns[10];
                $text       = trim($columns[11]);

                if ((int) $columns[0] !== self::TSV_LEVEL_WORD || $confidence < 0 || $text === '') {
                    continue;
                }

                $words[] = new WordBox(
                    text: $text,
                    confidence: $confidence,
                    x: (int) $columns[6],
                    y: (int) $columns[7],
                    width: (int) $columns[8],
                    height: (int) $columns[9],
                    page: $pageNo,
                );
            }
        } finally {
            fclose($handle);
        }

        return $words;
    }

    /** @param WordBox[] $words */
    private function readText(string $path, array $words): string
    {
        if (is_file($path)) {
            return (string) file_get_contents($path);
        }

        return implode(' ', array_map(static fn (WordBox $word): string => $word->text, $words));
    }

    /** @param WordBox[] $words */
    private function averageConfidence(array $words): ?float
    {
        if ($words === []) {
            return null;
        }

        $sum = array_sum(array_map(static fn (WordBox $word): float => $word->confidence, $words));

        return round($sum / count($words), 2);
    }
}
