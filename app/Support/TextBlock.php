<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Teks dokumen yang sudah dinormalisasi per baris, beserta pembacaan berbasis
 * label + kedekatan baris.
 *
 * Dokumen legalitas Indonesia (NPWP DJP, NIB OSS) memakai label yang konsisten,
 * sehingga pembacaan berbasis label jauh lebih aman daripada regex bebas atas
 * seluruh teks — inilah yang menekan false positive.
 */
final class TextBlock
{
    /** @param string[] $lines */
    private function __construct(private readonly array $lines)
    {
    }

    public static function of(string $text): self
    {
        $normalized = str_replace(["\r\n", "\r", "\xC2\xA0"], ["\n", "\n", ' '], $text);

        $lines = array_map(
            static fn (string $line): string => trim((string) preg_replace('/[ \t]+/u', ' ', $line)),
            explode("\n", $normalized)
        );

        return new self($lines);
    }

    /** @return string[] */
    public function lines(): array
    {
        return $this->lines;
    }

    public function text(): string
    {
        return implode("\n", $this->lines);
    }

    public function upper(): string
    {
        return mb_strtoupper($this->text());
    }

    public function count(): int
    {
        return count($this->lines);
    }

    public function line(int $index): ?string
    {
        return $this->lines[$index] ?? null;
    }

    /**
     * Indeks baris pertama yang diawali salah satu label.
     *
     * @param string[] $labels
     */
    public function indexOfLabel(array $labels): ?int
    {
        foreach ($this->lines as $index => $line) {
            if ($this->isLabel($line, $labels)) {
                return $index;
            }
        }

        return null;
    }

    /** @param string[] $labels */
    public function matchesLabel(int $index, array $labels): bool
    {
        $line = $this->line($index);

        return $line !== null && $this->isLabel($line, $labels);
    }

    /**
     * Isi setelah label: sisa baris yang sama, atau baris berikutnya bila kosong.
     *
     * @param string[] $labels
     * @param string[] $stopLabels label lain yang menandakan isian sudah habis
     */
    public function afterLabel(array $labels, array $stopLabels = []): ?string
    {
        $index = $this->indexOfLabel($labels);
        if ($index === null) {
            return null;
        }

        $rest = $this->restOfLine($this->lines[$index], $labels);
        if ($rest !== '') {
            return $rest;
        }

        $limit = min($index + 3, count($this->lines));
        for ($i = $index + 1; $i < $limit; $i++) {
            if ($this->lines[$i] === '') {
                continue;
            }

            return $this->isLabel($this->lines[$i], $stopLabels) ? null : $this->lines[$i];
        }

        return null;
    }

    /**
     * Blok beberapa baris setelah label — untuk alamat yang tercetak multi-baris.
     * Berhenti pada baris kosong atau label berikutnya.
     *
     * @param string[] $labels
     * @param string[] $stopLabels
     */
    public function blockAfterLabel(array $labels, array $stopLabels = [], int $maxLines = 4): ?string
    {
        $index = $this->indexOfLabel($labels);
        if ($index === null) {
            return null;
        }

        $collected = [];
        $rest      = $this->restOfLine($this->lines[$index], $labels);
        if ($rest !== '') {
            $collected[] = $rest;
        }

        $limit = min($index + 1 + $maxLines, count($this->lines));
        for ($i = $index + 1; $i < $limit; $i++) {
            $line = $this->lines[$i];

            if ($line === '') {
                if ($collected !== []) {
                    break;
                }

                continue;
            }

            if ($this->isLabel($line, $stopLabels)) {
                break;
            }

            $collected[] = $line;

            if (count($collected) >= $maxLines) {
                break;
            }
        }

        return $collected === [] ? null : implode("\n", $collected);
    }

    /**
     * Potongan teks di sekitar label — dipakai membatasi pencarian nomor supaya
     * tidak menangkap angka milik bagian lain dokumen.
     *
     * @param string[] $labels
     */
    public function scopeAround(array $labels, int $lookahead = 2): ?string
    {
        $index = $this->indexOfLabel($labels);
        if ($index === null) {
            return null;
        }

        $slice = array_slice($this->lines, $index, $lookahead + 1);

        return implode("\n", $slice);
    }

    /** @param string[] $labels */
    private function isLabel(string $line, array $labels): bool
    {
        return $labels !== [] && preg_match(self::pattern($labels), $line) === 1;
    }

    /** @param string[] $labels */
    private function restOfLine(string $line, array $labels): string
    {
        preg_match(self::pattern($labels), $line, $matches);

        return trim($matches[1] ?? '', " \t:.-");
    }

    /** @param string[] $labels */
    private static function pattern(array $labels): string
    {
        $alternatives = implode('|', array_map(
            static fn (string $label): string => preg_quote($label, '/'),
            $labels
        ));

        return '/^\s*(?:'.$alternatives.')\s*[:.\-]?\s*(.*)$/iu';
    }
}
