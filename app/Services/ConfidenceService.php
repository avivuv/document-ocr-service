<?php

declare(strict_types=1);

namespace App\Services;

use App\DTO\AnalyzeResult;
use App\DTO\FieldResult;
use App\DTO\WordBox;

/**
 * Confidence sebuah field diambil dari kata dengan confidence TERENDAH, bukan
 * rata-rata. Satu kata meragukan sudah cukup untuk membuat seluruh field patut
 * diragukan — pilihan konservatif ini yang menekan false positive.
 */
final class ConfidenceService
{
    private const TEXT_LAYER_CONFIDENCE = 100.0;

    /**
     * @param FieldResult[] $fields
     * @param WordBox[]     $words
     * @return FieldResult[]
     */
    public function score(array $fields, array $words, string $mode): array
    {
        if ($mode === AnalyzeResult::MODE_TEXT_LAYER) {
            return array_map(
                fn (FieldResult $field): FieldResult => $this->withConfidence(
                    $field,
                    min($field->confidence, self::TEXT_LAYER_CONFIDENCE),
                    null,
                    $field->page
                ),
                $fields
            );
        }

        if ($words === []) {
            return $fields;
        }

        return array_map(fn (FieldResult $field): FieldResult => $this->scoreField($field, $words), $fields);
    }

    /**
     * Dua sumber ketidakpastian digabung dengan mengambil yang terendah:
     * kepastian struktur dari parser (field berlabel vs terbaca dari posisi)
     * dan kepastian karakter dari engine.
     *
     * @param WordBox[] $words
     */
    private function scoreField(FieldResult $field, array $words): FieldResult
    {
        $matched = $this->matchingWords($field->raw, $words);

        if ($matched === []) {
            return $field;
        }

        $confidence = min(array_map(static fn (WordBox $word): float => $word->confidence, $matched));

        return $this->withConfidence(
            $field,
            round(min($confidence, $field->confidence), 2),
            $this->union($matched),
            $matched[0]->page,
        );
    }

    /**
     * Mencari deretan kata yang benar-benar menyusun field, bukan sekadar kata
     * yang teksnya kebetulan sama.
     *
     * Kata seperti "INDONESIA" muncul di kop dokumen sekaligus di nama
     * perusahaan. Mengambil kemunculan pertama membuat bbox melar sehalaman
     * penuh dan confidence diambil dari kata yang salah — karena itu yang
     * dipilih adalah deretan berurutan paling rapat.
     *
     * @param WordBox[] $words
     * @return WordBox[]
     */
    private function matchingWords(string $raw, array $words): array
    {
        $tokens = array_values(array_filter(
            array_map([$this, 'canonical'], preg_split('/\s+/u', trim($raw), -1, PREG_SPLIT_NO_EMPTY) ?: []),
            static fn (string $token): bool => $token !== ''
        ));

        if ($tokens === [] || $words === []) {
            return [];
        }

        $canonicals  = array_map(fn (WordBox $word): string => $this->canonical($word->text), $words);
        $total       = count($words);
        $tokenCount  = count($tokens);
        $maxSpan     = $tokenCount * 3 + 5;
        $minRequired = (int) ceil($tokenCount / 2);

        $best      = null;
        $bestCount = 0;
        $bestSpan  = PHP_INT_MAX;

        for ($start = 0; $start < $total; $start++) {
            if ($canonicals[$start] !== $tokens[0]) {
                continue;
            }

            $picked = [$start];
            $cursor = $start + 1;
            $limit  = min($total, $start + $maxSpan + 1);

            // Token yang tidak ditemukan dilewati, bukan menggugurkan kandidat —
            // Tesseract kerap memecah satu token dokumen menjadi beberapa kata.
            for ($t = 1; $t < $tokenCount; $t++) {
                for ($i = $cursor; $i < $limit; $i++) {
                    if ($canonicals[$i] === $tokens[$t] && $words[$i]->page === $words[$start]->page) {
                        $picked[] = $i;
                        $cursor   = $i + 1;
                        break;
                    }
                }
            }

            $count = count($picked);
            $span  = $picked[$count - 1] - $start;

            if ($count > $bestCount || ($count === $bestCount && $span < $bestSpan)) {
                $best      = $picked;
                $bestCount = $count;
                $bestSpan  = $span;
            }
        }

        if ($best === null || $bestCount < $minRequired) {
            return [];
        }

        return array_map(static fn (int $index): WordBox => $words[$index], $best);
    }

    private function canonical(string $value): string
    {
        return mb_strtolower((string) preg_replace('/[^\p{L}\p{N}]/u', '', $value));
    }

    /**
     * @param WordBox[] $words
     * @return array{0:int,1:int,2:int,3:int}
     */
    private function union(array $words): array
    {
        $left   = min(array_map(static fn (WordBox $w): int => $w->x, $words));
        $top    = min(array_map(static fn (WordBox $w): int => $w->y, $words));
        $right  = max(array_map(static fn (WordBox $w): int => $w->x + $w->width, $words));
        $bottom = max(array_map(static fn (WordBox $w): int => $w->y + $w->height, $words));

        return [$left, $top, $right - $left, $bottom - $top];
    }

    /** @param array{0:int,1:int,2:int,3:int}|null $bbox */
    private function withConfidence(FieldResult $field, float $confidence, ?array $bbox, int $page): FieldResult
    {
        return new FieldResult(
            name: $field->name,
            value: $field->value,
            raw: $field->raw,
            confidence: $confidence,
            page: $page,
            bbox: $bbox,
        );
    }
}
