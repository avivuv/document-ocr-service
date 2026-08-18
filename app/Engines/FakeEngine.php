<?php

declare(strict_types=1);

namespace App\Engines;

use App\Contracts\OcrEngineInterface;
use App\DTO\PageResult;
use App\DTO\WordBox;

/**
 * Engine tiruan: mengembalikan teks yang sudah disiapkan, bukan hasil OCR.
 *
 * Dipakai test agar seluruh pipeline bisa dijalankan tanpa Tesseract terpasang,
 * dan di mesin pengembangan yang belum lengkap binary-nya. WordBox dibangkitkan
 * dengan koordinat berurutan supaya ConfidenceService tetap punya bahan kerja.
 */
final class FakeEngine implements OcrEngineInterface
{
    private const CONFIDENCE = 95.0;

    /** @var string[] antrian teks per halaman */
    private array $queue = [];

    public function __construct(private string $defaultText = '')
    {
    }

    public function queue(string $text): self
    {
        $this->queue[] = $text;

        return $this;
    }

    public function reset(): self
    {
        $this->queue = [];

        return $this;
    }

    public function name(): string
    {
        return 'fake';
    }

    public function version(): string
    {
        return 'fake-1.0';
    }

    public function isAvailable(): bool
    {
        return true;
    }

    public function recognize(string $imagePath, int $pageNo, array $options = []): PageResult
    {
        $text = array_shift($this->queue) ?? $this->sidecarText($imagePath) ?? $this->defaultText;

        return new PageResult(
            pageNo: $pageNo,
            text: $text,
            words: $this->wordsOf($text, $pageNo),
            avgConfidence: $text === '' ? null : self::CONFIDENCE,
        );
    }

    /** Berkas ".txt" bersebelahan dengan gambar — memudahkan uji coba manual. */
    private function sidecarText(string $imagePath): ?string
    {
        $sidecar = preg_replace('/\.[^.]+$/', '', $imagePath).'.txt';

        return is_string($sidecar) && is_file($sidecar) ? (string) file_get_contents($sidecar) : null;
    }

    /** @return WordBox[] */
    private function wordsOf(string $text, int $pageNo): array
    {
        $words = [];
        $y     = 40;

        foreach (explode("\n", str_replace("\r\n", "\n", $text)) as $line) {
            $x = 40;

            foreach (preg_split('/\s+/u', trim($line), -1, PREG_SPLIT_NO_EMPTY) ?: [] as $word) {
                $width   = mb_strlen($word) * 12;
                $words[] = new WordBox($word, self::CONFIDENCE, $x, $y, $width, 24, $pageNo);
                $x      += $width + 12;
            }

            $y += 32;
        }

        return $words;
    }
}
