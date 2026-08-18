<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Koreksi kesalahan OCR yang khas pada karakter angka.
 *
 * HANYA boleh dipakai pada field numerik (NPWP, NIB, KBLI, kode pos). Menerapkan
 * pemetaan ini pada nama perusahaan akan merusaknya — "PT SOLO" menjadi "PT 5010".
 */
final class OcrTextNormalizer
{
    private const DIGIT_LOOKALIKES = [
        'O' => '0', 'o' => '0',
        'I' => '1', 'l' => '1', '|' => '1',
        'S' => '5', 's' => '5',
        'B' => '8',
        'Z' => '2', 'z' => '2',
    ];

    /** Karakter yang boleh muncul sebagai digit setelah dikoreksi — dipakai menyusun regex. */
    public const DIGIT_CLASS = '[0-9OoIlSsBZz|]';

    public static function digits(string $value): string
    {
        return (string) preg_replace('/\D+/', '', strtr($value, self::DIGIT_LOOKALIKES));
    }

    /**
     * Rasio digit asli terhadap seluruh karakter non-pemisah.
     *
     * Penjaga false positive: tanpa ini, kata seperti "SOLOBIZ" ikut lolos
     * sebagai kandidat nomor karena seluruh hurufnya mirip angka.
     */
    public static function realDigitRatio(string $raw): float
    {
        $meaningful = (string) preg_replace('/[\s.\-]/', '', $raw);
        $length     = mb_strlen($meaningful);

        if ($length === 0) {
            return 0.0;
        }

        return mb_strlen((string) preg_replace('/[^0-9]/', '', $meaningful)) / $length;
    }

    public static function squish(string $value): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', $value));
    }
}
