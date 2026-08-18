<?php

declare(strict_types=1);

namespace App\Parsers;

use App\Contracts\DocumentParserInterface;
use App\DTO\FieldResult;
use App\Support\OcrTextNormalizer;
use App\Support\TextBlock;

/**
 * NIB terbit dari OSS dengan label yang konsisten, sehingga seluruh ekstraksi
 * di sini berbasis label + kedekatan baris — bukan regex bebas atas seluruh
 * teks. Dokumen NIB memuat banyak angka lain (NPWP, tanggal, KBLI, kode pos)
 * yang mudah tertukar bila pencarian tidak dibatasi label.
 */
final class NibParser implements DocumentParserInterface
{
    private const LABELS_NUMBER = ['NIB', 'NOMOR INDUK BERUSAHA', 'NO NIB', 'NOMOR NIB'];
    private const LABELS_NAME   = ['NAMA PERUSAHAAN', 'NAMA PELAKU USAHA', 'NAMA BADAN USAHA', 'NAMA'];
    private const LABELS_ADDR   = ['ALAMAT PERUSAHAAN', 'ALAMAT KANTOR', 'ALAMAT USAHA', 'ALAMAT'];
    private const LABELS_POST   = ['KODE POS', 'KODEPOS'];
    private const LABELS_KBLI   = ['KBLI', 'KODE KBLI', 'BIDANG USAHA'];

    private const STOP_LABELS = [
        'NIB', 'NPWP', 'NAMA', 'ALAMAT', 'KODE POS', 'KELURAHAN', 'KECAMATAN',
        'KABUPATEN', 'KOTA', 'PROVINSI', 'KBLI', 'STATUS', 'SKALA USAHA',
        'JENIS', 'NOMOR', 'TANGGAL', 'MODAL', 'RT', 'RW', 'EMAIL', 'TELEPON',
    ];

    private const BASE_CONFIDENCE = 100.0;

    private const MAX_KBLI = 5;

    public function docType(): string
    {
        return 'NIB';
    }

    public function fieldNames(): array
    {
        return ['nib_no', 'vendor_name', 'address', 'postal_code', 'kbli'];
    }

    public function matchScore(string $text): float
    {
        $block = TextBlock::of($text);
        $upper = $block->upper();
        $score = 0.0;

        if (str_contains($upper, 'NOMOR INDUK BERUSAHA')) {
            $score += 0.40;
        }

        if (str_contains($upper, 'PERIZINAN BERUSAHA')) {
            $score += 0.20;
        }

        if (str_contains($upper, 'OSS')) {
            $score += 0.10;
        }

        if (preg_match('/\bNIB\b/u', $upper) === 1) {
            $score += 0.15;
        }

        if ($this->findNib($block) !== null) {
            $score += 0.15;
        }

        return min(1.0, $score);
    }

    public function parse(string $text, array $words = []): array
    {
        $block  = TextBlock::of($text);
        $fields = [];

        $nib = $this->findNib($block);
        if ($nib !== null) {
            $fields[] = new FieldResult('nib_no', $nib['value'], $nib['raw'], self::BASE_CONFIDENCE);
        }

        $name = $this->cleanName($block->afterLabel(self::LABELS_NAME, self::STOP_LABELS));
        if ($name !== null) {
            $fields[] = new FieldResult('vendor_name', $name, $name, self::BASE_CONFIDENCE);
        }

        $address = $block->blockAfterLabel(self::LABELS_ADDR, self::STOP_LABELS, 3);
        $cleaned = $this->cleanAddress($address);
        if ($address !== null && $cleaned !== null) {
            $fields[] = new FieldResult('address', $cleaned, $address, self::BASE_CONFIDENCE);
        }

        $postal = $this->findFixedDigits($block->afterLabel(self::LABELS_POST, self::STOP_LABELS), 5);
        if ($postal !== null) {
            $fields[] = new FieldResult('postal_code', $postal['value'], $postal['raw'], self::BASE_CONFIDENCE);
        }

        $kbli = $this->findKbli($block);
        if ($kbli !== null) {
            $fields[] = new FieldResult('kbli', $kbli['value'], $kbli['raw'], self::BASE_CONFIDENCE);
        }

        return $fields;
    }

    /** @return array{raw:string,value:string}|null */
    private function findNib(TextBlock $block): ?array
    {
        $scope = $block->scopeAround(self::LABELS_NUMBER);
        if ($scope === null) {
            return null;
        }

        return $this->findFixedDigits($scope, 13);
    }

    /**
     * Nomor berpanjang tetap di dalam sebuah potongan teks.
     *
     * @return array{raw:string,value:string}|null
     */
    private function findFixedDigits(?string $scope, int $length): ?array
    {
        if ($scope === null || $scope === '') {
            return null;
        }

        $d       = OcrTextNormalizer::DIGIT_CLASS;
        $pattern = "/(?<![0-9]){$d}{{$length}}(?![0-9])/u";

        if (preg_match_all($pattern, $scope, $matches) === false) {
            return null;
        }

        foreach ($matches[0] as $raw) {
            if (OcrTextNormalizer::realDigitRatio($raw) < 0.55) {
                continue;
            }

            $digits = OcrTextNormalizer::digits($raw);
            if (mb_strlen($digits) === $length) {
                return ['raw' => $raw, 'value' => $digits];
            }
        }

        return null;
    }

    /**
     * NIB umumnya memuat lebih dari satu KBLI. Seluruhnya dikembalikan dipisah
     * koma supaya user yang mereview bisa memilih, bukan satu kode yang dipilih
     * sepihak oleh service.
     *
     * @return array{raw:string,value:string}|null
     */
    private function findKbli(TextBlock $block): ?array
    {
        $scope = $block->scopeAround(self::LABELS_KBLI, 6);
        if ($scope === null) {
            return null;
        }

        $d = OcrTextNormalizer::DIGIT_CLASS;
        if (preg_match_all("/(?<![0-9]){$d}{5}(?![0-9])/u", $scope, $matches) === false) {
            return null;
        }

        $codes = [];
        foreach ($matches[0] as $raw) {
            if (OcrTextNormalizer::realDigitRatio($raw) < 0.55) {
                continue;
            }

            $digits = OcrTextNormalizer::digits($raw);
            if (mb_strlen($digits) === 5 && ! in_array($digits, $codes, true)) {
                $codes[] = $digits;
            }

            if (count($codes) >= self::MAX_KBLI) {
                break;
            }
        }

        if ($codes === []) {
            return null;
        }

        return ['raw' => implode(', ', $codes), 'value' => implode(', ', $codes)];
    }

    private function cleanName(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $name = OcrTextNormalizer::squish($value);

        if (mb_strlen($name) < 3 || mb_strlen($name) > 150) {
            return null;
        }

        return preg_match_all('/\p{L}/u', $name) < 2 ? null : $name;
    }

    private function cleanAddress(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $address = OcrTextNormalizer::squish(str_replace("\n", ' ', $value));

        return mb_strlen($address) < 8 ? null : $address;
    }
}
