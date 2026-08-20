<?php

declare(strict_types=1);

namespace App\Engines;

use App\Contracts\OcrEngineInterface;
use App\DTO\PageResult;
use App\DTO\WordBox;
use App\Exceptions\OcrException;

/**
 * Menggabungkan Tesseract dan VLM: Tesseract berjalan lebih dulu, VLM hanya
 * dipanggil bila hasilnya meragukan.
 *
 * Bukan sekadar penghematan waktu. Dokumen yang sudah terbaca baik tidak boleh
 * membayar belasan detik tambahan tanpa perbaikan apa pun, dan Tesseract tetap
 * satu-satunya sumber bbox serta confidence per kata.
 *
 * Ketika keduanya berjalan, bacaan VLM yang dipakai sebagai kerangka teks dan
 * baris Tesseract yang tidak tertangkap VLM disisipkan pada posisinya.
 *
 * Urutan baris penting: parser membaca alamat secara posisional — beberapa baris
 * tepat setelah label NIK. Menempelkan bacaan VLM di akhir teks membuat baris
 * yang dipulihkan berada di luar jendela itu, sehingga tidak terparsing meski
 * sudah terbaca. Word box tetap milik Tesseract.
 */
final class HybridEngine implements OcrEngineInterface
{
    private const PAGE_SEPARATOR = "\n";

    public function __construct(
        private readonly OcrEngineInterface $tesseract,
        private readonly OcrEngineInterface $vlm,
    ) {
    }

    public function name(): string
    {
        return 'hybrid';
    }

    public function version(): string
    {
        return $this->tesseract->version().'+'.$this->vlm->version();
    }

    public function isAvailable(): bool
    {
        return $this->tesseract->isAvailable();
    }

    public function recognize(string $imagePath, int $pageNo, array $options = []): PageResult
    {
        $base = $this->tesseract->recognize($imagePath, $pageNo, $options);

        if (! $this->isDoubtful($base)) {
            return $base;
        }

        try {
            $assisted = $this->vlm->recognize($imagePath, $pageNo, $options);
        } catch (OcrException) {
            /*
             * VLM adalah penolong, bukan syarat. Host model mati tidak boleh
             * menggagalkan permintaan yang Tesseract sudah sanggup layani —
             * hasil Tesseract dikembalikan apa adanya.
             */
            return $base;
        }

        if (trim($assisted->text) === '') {
            return $base;
        }

        $text = $this->merge($base->text, $assisted->text);

        return new PageResult(
            pageNo: $pageNo,
            text: $text,
            words: $this->wordsSurviving($base->words, $text),
            avgConfidence: $base->avgConfidence,
        );
    }

    /**
     * Word box hanya boleh mewakili teks yang benar-benar ada di hasil akhir.
     *
     * ConfidenceService menilai field dengan mengambil confidence TERENDAH dari
     * kata yang menyusunnya. Bila word box baris yang sudah dibuang ikut terbawa,
     * field yang teksnya dipulihkan VLM akan dinilai memakai keyakinan Tesseract
     * atas bacaan yang justru salah — nilainya benar tetapi confidence-nya jatuh.
     *
     * @param WordBox[] $words
     * @return WordBox[]
     */
    private function wordsSurviving(array $words, string $text): array
    {
        $kept = [];

        foreach ($this->linesOf($text) as $line) {
            foreach (preg_split('/\s+/u', $line, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $token) {
                $fingerprint = $this->fingerprint($token);

                if ($fingerprint !== '') {
                    $kept[$fingerprint] = true;
                }
            }
        }

        return array_values(array_filter(
            $words,
            fn (WordBox $word): bool => isset($kept[$this->fingerprint($word->text)])
        ));
    }

    /**
     * Rata-rata confidence sengaja TIDAK dipakai sebagai penanda.
     *
     * Pada foto NPWP yang dua baris alamatnya hilang, rata-ratanya tetap 66,1
     * karena kata yang terbaca baik menutupi kata yang gagal — dokumen rusak
     * lolos begitu saja. Proporsi kata berconfidence rendah memisahkan keduanya
     * dengan jelas: 31% pada dokumen gagal, 0% pada dokumen yang terbaca utuh.
     */
    private function isDoubtful(PageResult $page): bool
    {
        if (mb_strlen(trim($page->text)) < (int) config('ocr.hybrid.min_text_length')) {
            return true;
        }

        if ($page->words === []) {
            return false;
        }

        $threshold = (float) config('ocr.hybrid.low_word_confidence');

        $low = count(array_filter(
            $page->words,
            static fn (WordBox $word): bool => $word->confidence < $threshold
        ));

        return $low / count($page->words) > (float) config('ocr.hybrid.max_low_word_ratio');
    }

    /**
     * VLM menjadi kerangka, bukan tambahan di akhir.
     *
     * Pada dokumen yang memicu jalur ini, bacaan VLM lebih utuh — itulah sebabnya
     * ia dipanggil. Baris Tesseract yang tidak punya padanan di bacaan VLM
     * disisipkan mengikuti tetangganya yang masih dikenali, sehingga tidak ada
     * informasi yang hilang tanpa merusak urutan baca.
     */
    private function merge(string $base, string $assisted): string
    {
        $assistedLines = $this->linesOf($assisted);

        if ($assistedLines === []) {
            return $base;
        }

        $known = [];

        foreach ($assistedLines as $position => $line) {
            $known[$this->fingerprint($line)] = $position;
        }

        /** @var array<int,string[]> $orphans baris Tesseract yang tidak dikenali VLM */
        $orphans = [];
        $after   = -1;

        foreach ($this->linesOf($base) as $line) {
            $position = $known[$this->fingerprint($line)] ?? null;

            if ($position !== null) {
                $after = $position;

                continue;
            }

            if ($this->isNoise($line) || $this->isLikelyMisread($line, $assistedLines)) {
                continue;
            }

            $orphans[$after][] = $line;
        }

        $merged = $orphans[-1] ?? [];

        foreach ($assistedLines as $position => $line) {
            $merged[] = $line;

            foreach ($orphans[$position] ?? [] as $orphan) {
                $merged[] = $orphan;
            }
        }

        return implode(self::PAGE_SEPARATOR, $merged);
    }

    /**
     * Baris yang terlalu pendek untuk membawa informasi.
     *
     * Parser membaca alamat secara posisional dan hanya mengambil tiga baris
     * pertama setelah label. Serpihan seperti "N" atau "3 N" bukan sekadar kotor:
     * ia menyita satu slot dan mendorong baris alamat yang sesungguhnya keluar
     * dari jendela itu — data hilang, bukan cuma derau ikut terbawa.
     *
     * Bacaan VLM dipercaya untuk halaman ini, jadi baris Tesseract sependek ini
     * tidak menambah apa pun yang belum ada.
     */
    private function isNoise(string $line): bool
    {
        return mb_strlen($this->fingerprint($line)) < 4;
    }

    /**
     * Baris Tesseract yang merupakan versi rusak dari baris yang sudah dibaca VLM.
     *
     * "ee PALDAWIR" adalah bacaan gagal atas "BETAK, KALIDAWIR" — mempertahankannya
     * membuat alamat memuat potongan yang salah. Dikenali lewat kemiripan karakter:
     * bila sebagian besar isi baris pendek ini terkandung di salah satu baris VLM,
     * yang dipercaya adalah bacaan VLM.
     *
     * @param string[] $assistedLines
     */
    private function isLikelyMisread(string $line, array $assistedLines): bool
    {
        $needle = $this->fingerprint($line);
        $length = mb_strlen($needle);

        if ($length < 4 || $length > 24) {
            return false;
        }

        foreach ($assistedLines as $assisted) {
            $haystack = $this->fingerprint($assisted);

            if ($haystack === '' || mb_strlen($haystack) < $length) {
                continue;
            }

            similar_text($needle, $haystack, $percent);

            if ($percent >= 60.0) {
                return true;
            }
        }

        return false;
    }

    /** @return string[] */
    private function linesOf(string $text): array
    {
        $lines = preg_split('/\R/u', $text) ?: [];

        return array_values(array_filter(array_map('trim', $lines), static fn (string $l): bool => $l !== ''));
    }

    /**
     * Perbandingan longgar: Tesseract dan VLM sering membaca baris yang sama
     * dengan spasi dan tanda baca berbeda, dan perbedaan itu bukan informasi baru.
     */
    private function fingerprint(string $line): string
    {
        return preg_replace('/[^a-z0-9]/', '', mb_strtolower($line)) ?? '';
    }
}
