<?php

declare(strict_types=1);

namespace App\Parsers;

use App\Contracts\DocumentParserInterface;
use App\DTO\FieldResult;
use App\Support\OcrTextNormalizer;
use App\Support\TextBlock;

/**
 * Kartu NPWP beredar dalam dua gaya yang menuntut cara baca berbeda:
 *
 *  - gaya lama / badan usaha — setiap field berlabel (NAMA, ALAMAT)
 *  - gaya baru / perorangan  — hanya NPWP dan NIK yang berlabel; nama tercetak
 *    langsung di bawah nomor, alamat di bawah NIK, keduanya tanpa label
 *
 * Pembacaan berlabel selalu didahulukan. Pembacaan berdasarkan posisi hanya
 * dipakai bila labelnya memang tidak ada, dan ditandai confidence lebih rendah
 * karena kepastiannya bersandar pada tata letak, bukan pada label.
 */
final class NpwpParser implements DocumentParserInterface
{
    private const LABELS_NUMBER = ['NPWP', 'N.P.W.P', 'NOMOR POKOK WAJIB PAJAK', 'NO NPWP', 'NOMOR NPWP'];
    private const LABELS_NAME   = ['NAMA WAJIB PAJAK', 'NAMA', 'NAME'];
    private const LABELS_ADDR   = ['ALAMAT WAJIB PAJAK', 'ALAMAT', 'ADDRESS'];
    private const LABELS_NIK    = ['NIK', 'N.I.K', 'NOMOR INDUK KEPENDUDUKAN'];

    /**
     * Nomor panjang lain yang ikut tercetak di kartu NPWP. NIK sama-sama 16
     * digit seperti NPWP format baru, jadi barisnya wajib dikecualikan saat
     * mencari nomor NPWP — kalau tidak, NIK terambil sebagai npwp_no begitu
     * nomor aslinya gagal terbaca.
     */
    private const LABELS_OTHER_NUMBER = ['NIK', 'N.I.K', 'NOMOR INDUK KEPENDUDUKAN', 'NITKU'];

    private const STOP_LABELS = [
        'NPWP', 'NAMA', 'ALAMAT', 'KPP', 'TERDAFTAR', 'NITKU', 'STATUS',
        'KLU', 'MERK', 'JENIS', 'KATEGORI', 'NIK', 'TANGGAL',
    ];

    /** Kata yang menandai akhir blok alamat meski tidak berada di awal baris. */
    private const ADDRESS_TERMINATORS = ['KPP', 'TERDAFTAR', 'NITKU', 'NPWP', 'NIK'];

    /** Field berlabel: kepastian struktur penuh. */
    private const CONFIDENCE_LABELED = 100.0;

    /** Field hasil pembacaan posisi: benar menurut tata letak, bukan menurut label. */
    private const CONFIDENCE_POSITIONAL = 75.0;

    private const LOOKAHEAD = 2;

    public function docType(): string
    {
        return 'NPWP';
    }

    public function fieldNames(): array
    {
        return ['npwp_no', 'nik', 'vendor_name', 'address'];
    }

    public function matchScore(string $text): float
    {
        $block = TextBlock::of($text);
        $upper = $block->upper();
        $score = 0.0;

        if (str_contains($upper, 'NOMOR POKOK WAJIB PAJAK')) {
            $score += 0.35;
        }

        if (str_contains($upper, 'DIREKTORAT JENDERAL PAJAK')) {
            $score += 0.30;
        }

        if (preg_match('/\bNPWP\b/u', $upper) === 1) {
            $score += 0.20;
        }

        if ($this->findNumber($block) !== null) {
            $score += 0.15;
        }

        return min(1.0, $score);
    }

    public function parse(string $text, array $words = []): array
    {
        $block  = TextBlock::of($text);
        $fields = [];

        foreach ([
            'npwp_no'     => $this->findNumber($block),
            'nik'         => $this->findNik($block),
            'vendor_name' => $this->findName($block),
            'address'     => $this->findAddress($block),
        ] as $name => $found) {
            if ($found !== null) {
                $fields[] = new FieldResult($name, $found['value'], $found['raw'], $found['confidence']);
            }
        }

        return $fields;
    }

    /**
     * NPWP punya dua format: lama 15 digit dan baru 16 digit (berlaku sejak
     * 2024). Keduanya dinormalisasi ke 16 digit — 15 digit di-pad "0" di depan,
     * sesuai kolom npwp_no milik consumer.
     *
     * @return array{raw:string,value:string,confidence:float}|null
     */
    private function findNumber(TextBlock $block): ?array
    {
        // Judul kartu ("NOMOR POKOK WAJIB PAJAK") juga berupa label, jadi setiap
        // baris berlabel dicoba — bukan hanya yang pertama ditemukan.
        for ($i = 0; $i < $block->count(); $i++) {
            if (! $block->matchesLabel($i, self::LABELS_NUMBER)) {
                continue;
            }

            $found = $this->numberIn((string) $block->line($i), allowBareDigits: true);
            if ($found !== null) {
                return $found + ['confidence' => self::CONFIDENCE_LABELED];
            }
        }

        for ($i = 0; $i < $block->count(); $i++) {
            if (! $block->matchesLabel($i, self::LABELS_NUMBER)) {
                continue;
            }

            $found = $this->numberNearLabel($block, $i);
            if ($found !== null) {
                return $found + ['confidence' => self::CONFIDENCE_POSITIONAL];
            }
        }

        // Tanpa label sama sekali, hanya format bertitik yang diterima — deretan
        // 15/16 digit polos terlalu mudah tertukar dengan nomor lain di dokumen.
        for ($i = 0; $i < $block->count(); $i++) {
            if ($block->matchesLabel($i, self::LABELS_OTHER_NUMBER)) {
                continue;
            }

            $found = $this->numberIn((string) $block->line($i), allowBareDigits: false);
            if ($found !== null) {
                return $found + ['confidence' => self::CONFIDENCE_POSITIONAL];
            }
        }

        return null;
    }

    /** @return array{raw:string,value:string}|null */
    private function numberNearLabel(TextBlock $block, int $labelIndex): ?array
    {
        for ($i = $labelIndex + 1; $i <= $labelIndex + self::LOOKAHEAD; $i++) {
            $line = $block->line($i);

            if ($line === null) {
                break;
            }

            if ($line === '' || $block->matchesLabel($i, self::LABELS_OTHER_NUMBER)) {
                continue;
            }

            // Baris berlabel NPWP bukan penghenti — justru di situ nomornya.
            if (! $block->matchesLabel($i, self::LABELS_NUMBER) && $block->matchesLabel($i, self::STOP_LABELS)) {
                break;
            }

            $found = $this->numberIn($line, allowBareDigits: true);
            if ($found !== null) {
                return $found;
            }
        }

        return null;
    }

    /** @return array{raw:string,value:string}|null */
    private function numberIn(string $line, bool $allowBareDigits): ?array
    {
        $d = OcrTextNormalizer::DIGIT_CLASS;

        $patterns = ["/{$d}{2}[.\\s]{$d}{3}[.\\s]{$d}{3}[.\\s]{$d}[\\s.\\-]{$d}{3}[.\\s]{$d}{3}/u"];

        if ($allowBareDigits) {
            $patterns[] = "/(?<![0-9]){$d}{16}(?![0-9])/u";
            $patterns[] = "/(?<![0-9]){$d}{15}(?![0-9])/u";
        }

        foreach ($patterns as $pattern) {
            if (preg_match_all($pattern, $line, $matches) === false) {
                continue;
            }

            foreach ($matches[0] as $raw) {
                if (OcrTextNormalizer::realDigitRatio($raw) < 0.55) {
                    continue;
                }

                $digits = OcrTextNormalizer::digits($raw);

                if (mb_strlen($digits) === 15) {
                    return ['raw' => trim($raw), 'value' => '0'.$digits];
                }

                if (mb_strlen($digits) === 16) {
                    return ['raw' => trim($raw), 'value' => $digits];
                }
            }
        }

        return null;
    }

    /**
     * NIK diambil hanya dari baris berlabelnya sendiri. Menebaknya dari baris
     * sekitar berisiko mengembalikan nomor orang lain — dan NIK adalah data
     * pribadi yang tidak boleh salah.
     *
     * @return array{raw:string,value:string,confidence:float}|null
     */
    private function findNik(TextBlock $block): ?array
    {
        $index = $block->indexOfLabel(self::LABELS_NIK);
        if ($index === null) {
            return null;
        }

        $d = OcrTextNormalizer::DIGIT_CLASS;
        if (preg_match("/(?<![0-9]){$d}{16}(?![0-9])/u", (string) $block->line($index), $matches) !== 1) {
            return null;
        }

        $raw = $matches[0];
        if (OcrTextNormalizer::realDigitRatio($raw) < 0.55) {
            return null;
        }

        $digits = OcrTextNormalizer::digits($raw);

        return mb_strlen($digits) === 16
            ? ['raw' => $raw, 'value' => $digits, 'confidence' => self::CONFIDENCE_LABELED]
            : null;
    }

    /** @return array{raw:string,value:string,confidence:float}|null */
    private function findName(TextBlock $block): ?array
    {
        $labeled = $this->cleanName($block->afterLabel(self::LABELS_NAME, self::STOP_LABELS));
        if ($labeled !== null) {
            return ['raw' => $labeled, 'value' => $labeled, 'confidence' => self::CONFIDENCE_LABELED];
        }

        $labelIndex = $block->indexOfLabel(self::LABELS_NUMBER);
        if ($labelIndex === null) {
            return null;
        }

        for ($i = $labelIndex + 1; $i <= $labelIndex + self::LOOKAHEAD; $i++) {
            $line = $block->line($i);

            if ($line === null) {
                break;
            }

            if ($line === '') {
                continue;
            }

            if ($block->matchesLabel($i, self::STOP_LABELS)) {
                break;
            }

            $name = $this->cleanName($line);
            if ($name !== null && $this->looksLikeName($name)) {
                return ['raw' => $name, 'value' => $name, 'confidence' => self::CONFIDENCE_POSITIONAL];
            }
        }

        return null;
    }

    /** @return array{raw:string,value:string,confidence:float}|null */
    private function findAddress(TextBlock $block): ?array
    {
        $labeled = $block->blockAfterLabel(self::LABELS_ADDR, self::STOP_LABELS);
        $value   = $this->cleanAddress($labeled);

        if ($labeled !== null && $value !== null) {
            return ['raw' => $labeled, 'value' => $value, 'confidence' => self::CONFIDENCE_LABELED];
        }

        $anchor = $block->indexOfLabel(self::LABELS_NIK) ?? $block->indexOfLabel(self::LABELS_NUMBER);
        if ($anchor === null) {
            return null;
        }

        $collected = $this->addressLinesAfter($block, $anchor);
        if ($collected === []) {
            return null;
        }

        $raw   = implode("\n", $collected);
        $value = $this->cleanAddress($raw);

        return $value === null
            ? null
            : ['raw' => $raw, 'value' => $value, 'confidence' => self::CONFIDENCE_POSITIONAL];
    }

    /** @return string[] */
    private function addressLinesAfter(TextBlock $block, int $anchor): array
    {
        $collected = [];

        for ($i = $anchor + 1; $i < $block->count() && count($collected) < 3; $i++) {
            $line = $block->line($i);

            if ($line === null) {
                break;
            }

            if ($line === '') {
                if ($collected !== []) {
                    break;
                }

                continue;
            }

            if ($block->matchesLabel($i, self::STOP_LABELS) || $this->terminatesAddress($line)) {
                break;
            }

            // Nama perorangan berada di antara nomor dan alamat bila NIK tidak
            // terbaca; barisnya dilewati, bukan menghentikan pencarian.
            $candidate = $this->cleanAddressLine($line);
            if ($candidate === '') {
                continue;
            }

            $collected[] = $candidate;
        }

        return $collected;
    }

    private function terminatesAddress(string $line): bool
    {
        $upper = mb_strtoupper($line);

        foreach (self::ADDRESS_TERMINATORS as $terminator) {
            if (str_contains($upper, $terminator)) {
                return true;
            }
        }

        return false;
    }

    /** Membuang derau OCR di tepi baris ("| KAB. TULUNGAGUNG" → "KAB. TULUNGAGUNG"). */
    private function cleanAddressLine(string $line): string
    {
        return trim((string) preg_replace('/^[^\p{L}\p{N}]+|[^\p{L}\p{N}.]+$/u', '', $line));
    }

    private function looksLikeName(string $name): bool
    {
        if (str_contains($name, ':')) {
            return false;
        }

        $letters = preg_match_all('/\p{L}/u', $name);
        $digits  = preg_match_all('/\p{N}/u', $name);

        return $letters >= 3 && $digits <= 1;
    }

    private function cleanName(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        // Derau OCR kerap menempel di tepi ("AHMAD AFIFUDIN —"). Kurung tutup dan
        // titik dipertahankan karena sah pada nama badan usaha: "PT ABC (PERSERO)".
        $name = trim((string) preg_replace(
            '/^[^\p{L}\p{N}]+|[^\p{L}\p{N}.)]+$/u',
            '',
            OcrTextNormalizer::squish($value)
        ));

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
