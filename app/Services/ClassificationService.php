<?php

declare(strict_types=1);

namespace App\Services;

use App\Contracts\Repositories\ParserRepositoryInterface;
use App\DTO\Classification;

final class ClassificationService
{
    public function __construct(private readonly ParserRepositoryInterface $parsers)
    {
    }

    /**
     * Memilih parser dengan skor kecocokan tertinggi.
     *
     * Di bawah ambang, jenis dokumen dibiarkan tidak diketahui. Salah memilih
     * parser menghasilkan field terisi salah — risiko yang jauh lebih mahal
     * daripada field kosong (lihat CONTEXT §9).
     */
    public function detect(string $text): Classification
    {
        if (trim($text) === '') {
            return Classification::undetermined();
        }

        $best      = null;
        $bestScore = 0.0;

        foreach ($this->parsers->all() as $parser) {
            $score = $parser->matchScore($text);

            if ($score > $bestScore) {
                $best      = $parser->docType();
                $bestScore = $score;
            }
        }

        $minimum = (float) config('ocr.classification.min_score', 0.35);

        if ($best === null || $bestScore < $minimum) {
            return Classification::undetermined();
        }

        return new Classification($best, round($bestScore, 2));
    }
}
