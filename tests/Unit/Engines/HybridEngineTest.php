<?php

declare(strict_types=1);

namespace Tests\Unit\Engines;

use App\Contracts\OcrEngineInterface;
use App\Contracts\Repositories\VlmRepositoryInterface;
use App\DTO\PageResult;
use App\DTO\WordBox;
use App\Engines\HybridEngine;
use App\Engines\VlmEngine;
use App\Exceptions\OcrException;
use Tests\TestCase;

final class HybridEngineTest extends TestCase
{
    public function test_tidak_memanggil_vlm_bila_tesseract_sudah_meyakinkan(): void
    {
        $vlm    = $this->vlm();
        $engine = $this->engine(
            text: "NPWP : 82.354.186.7-629.000\nAHMAD AFIFUDIN\nKAB. TULUNGAGUNG JAWA TIMUR",
            confidence: 88.0,
            vlm: $vlm,
            wordConfidences: self::words(32, 0),
        );

        $result = $engine->recognize('dummy.png', 1);

        self::assertSame(0, $vlm->calls, 'VLM tidak boleh dipanggil untuk hasil yang sudah baik');
        self::assertStringContainsString('AHMAD AFIFUDIN', $result->text);
    }

    public function test_memanggil_vlm_bila_confidence_rendah(): void
    {
        $vlm    = $this->vlm("DSN KRAJAN 3 RT. 002 RW. 005\nBETAK, KALIDAWIR");
        $engine = $this->engine(
            text: "NPWP : 82.354.186.7-629.000\nee PALDAWIR\nKAB. TULUNGAGUNG JAWA TIMUR",
            confidence: 66.1,
            vlm: $vlm,
            wordConfidences: self::words(32, 10),
        );

        $result = $engine->recognize('dummy.png', 1);

        self::assertSame(1, $vlm->calls);
        self::assertStringContainsString('DSN KRAJAN 3', $result->text);
        self::assertStringContainsString('BETAK, KALIDAWIR', $result->text);
    }

    /**
     * Kasus nyata dari korpus uji: foto NPWP dengan dua baris alamat hilang tetap
     * memberi rata-rata confidence 66,1 — cukup tinggi untuk lolos ambang rata-rata,
     * padahal 31% katanya terbaca buruk. Penanda berbasis rata-rata melewatkan ini.
     */
    public function test_menangkap_dokumen_rusak_yang_rata_ratanya_masih_tinggi(): void
    {
        $vlm    = $this->vlm('DSN KRAJAN 3 RT. 002 RW. 005');
        $engine = $this->engine(
            text: "NPWP : 82.354.186.7-629.000\nee PALDAWIR\nKAB. TULUNGAGUNG JAWA TIMUR",
            confidence: 66.1,
            vlm: $vlm,
            wordConfidences: self::words(32, 10),
        );

        $engine->recognize('dummy.png', 1);

        self::assertSame(1, $vlm->calls, 'rata-rata 66,1 tidak boleh menutupi 31% kata yang gagal');
    }

    public function test_tidak_memanggil_vlm_saat_kata_rendah_masih_sedikit(): void
    {
        $vlm    = $this->vlm('TIDAK SEHARUSNYA DIPANGGIL');
        $engine = $this->engine(
            text: 'DOKUMEN PANJANG YANG TERBACA DENGAN BAIK SEKALI',
            confidence: 90.0,
            vlm: $vlm,
            wordConfidences: self::words(40, 2),
        );

        $engine->recognize('dummy.png', 1);

        self::assertSame(0, $vlm->calls);
    }

    public function test_memanggil_vlm_bila_teks_terlalu_pendek(): void
    {
        $vlm    = $this->vlm('NPWP : 82.354.186.7-629.000');
        $engine = $this->engine(text: 'ee', confidence: 95.0, vlm: $vlm, wordConfidences: self::words(4, 0));

        $engine->recognize('dummy.png', 1);

        self::assertSame(1, $vlm->calls);
    }

    public function test_mempertahankan_teks_tesseract_saat_menggabung(): void
    {
        $vlm    = $this->vlm('BARIS TAMBAHAN DARI VLM');
        $engine = $this->engine(text: 'BARIS ASLI TESSERACT', confidence: 10.0, vlm: $vlm, wordConfidences: self::words(10, 5));

        $result = $engine->recognize('dummy.png', 1);

        self::assertStringContainsString('BARIS ASLI TESSERACT', $result->text);
        self::assertStringContainsString('BARIS TAMBAHAN DARI VLM', $result->text);
    }

    public function test_tidak_menggandakan_baris_yang_dibaca_kedua_engine(): void
    {
        $vlm    = $this->vlm("NPWP : 82.354.186.7-629.000\nBARIS BARU");
        $engine = $this->engine(text: 'NPWP : 82.354.186.7-629.000', confidence: 10.0, vlm: $vlm, wordConfidences: self::words(10, 5));

        $result = $engine->recognize('dummy.png', 1);

        self::assertSame(1, substr_count($result->text, '82.354.186.7-629.000'));
        self::assertStringContainsString('BARIS BARU', $result->text);
    }

    public function test_mengabaikan_perbedaan_spasi_saat_menilai_baris_kembar(): void
    {
        $vlm    = $this->vlm('NPWP: 82.354.186.7-629.000');
        $engine = $this->engine(text: 'NPWP : 82.354.186.7-629.000', confidence: 10.0, vlm: $vlm, wordConfidences: self::words(10, 5));

        $result = $engine->recognize('dummy.png', 1);

        self::assertSame(1, substr_count($result->text, '82.354.186.7-629.000'));
    }

    /**
     * Parser membaca alamat secara posisional — beberapa baris tepat setelah NIK.
     * Baris yang dipulihkan VLM harus berada di posisi itu, bukan ditempel di akhir.
     */
    public function test_menempatkan_baris_pulihan_pada_urutan_bacanya(): void
    {
        $vlm = $this->vlm(
            "NIK : 3504142707920002\nDSN KRAJAN 3 RT. 002 RW. 005\n"
            ."BETAK, KALIDAWIR\nKAB. TULUNGAGUNG JAWA TIMUR"
        );
        $engine = $this->engine(
            text: "NIK : 3504142707920002\nKAB. TULUNGAGUNG JAWA TIMUR",
            confidence: 66.1,
            vlm: $vlm,
            wordConfidences: self::words(32, 10),
        );

        $lines = explode("\n", $engine->recognize('dummy.png', 1)->text);

        self::assertSame(
            ['NIK : 3504142707920002', 'DSN KRAJAN 3 RT. 002 RW. 005', 'BETAK, KALIDAWIR', 'KAB. TULUNGAGUNG JAWA TIMUR'],
            $lines
        );
    }

    public function test_membuang_baris_tesseract_yang_salah_baca(): void
    {
        $vlm = $this->vlm("BETAK, KALIDAWIR\nKAB. TULUNGAGUNG JAWA TIMUR");
        $engine = $this->engine(
            text: "ee PALDAWIR\nKAB. TULUNGAGUNG JAWA TIMUR",
            confidence: 66.1,
            vlm: $vlm,
            wordConfidences: self::words(32, 10),
        );

        $result = $engine->recognize('dummy.png', 1);

        self::assertStringNotContainsString('ee PALDAWIR', $result->text);
        self::assertStringContainsString('BETAK, KALIDAWIR', $result->text);
    }

    public function test_mempertahankan_baris_tesseract_yang_tidak_dibaca_vlm(): void
    {
        $vlm = $this->vlm("NPWP : 82.354.186.7-629.000");
        $engine = $this->engine(
            text: "NPWP : 82.354.186.7-629.000\nKPP PRATAMA TULUNGAGUNG SELATAN",
            confidence: 66.1,
            vlm: $vlm,
            wordConfidences: self::words(32, 10),
        );

        $result = $engine->recognize('dummy.png', 1);

        self::assertStringContainsString('KPP PRATAMA TULUNGAGUNG SELATAN', $result->text);
    }

    /**
     * Parser alamat hanya mengambil tiga baris setelah label. Serpihan seperti "N"
     * menyita satu slot dan mendorong baris ketiga keluar — alamat jadi terpotong,
     * bukan sekadar kotor.
     */
    public function test_membuang_serpihan_yang_menggeser_baris_alamat(): void
    {
        $vlm = $this->vlm(
            "NIK : 3504142707920002\nDSN KRAJAN 3 RT. 002 RW. 005\n"
            ."BETAK, KALIDAWIR\nKAB. TULUNGAGUNG JAWA TIMUR"
        );
        $engine = $this->engine(
            text: "NIK : 3504142707920002\nN\nKAB. TULUNGAGUNG JAWA TIMUR",
            confidence: 66.1,
            vlm: $vlm,
            wordConfidences: self::words(32, 10),
        );

        $lines = explode("\n", $engine->recognize('dummy.png', 1)->text);

        self::assertNotContains('N', $lines);
        self::assertSame(
            ['NIK : 3504142707920002', 'DSN KRAJAN 3 RT. 002 RW. 005', 'BETAK, KALIDAWIR', 'KAB. TULUNGAGUNG JAWA TIMUR'],
            $lines
        );
    }

    public function test_mengembalikan_hasil_tesseract_bila_host_vlm_mati(): void
    {
        $vlm    = $this->vlm(throws: true);
        $engine = $this->engine(text: 'HASIL TESSERACT', confidence: 10.0, vlm: $vlm, wordConfidences: self::words(10, 5));

        $result = $engine->recognize('dummy.png', 1);

        self::assertSame('HASIL TESSERACT', $result->text);
    }

    public function test_mempertahankan_word_box_tesseract(): void
    {
        $vlm    = $this->vlm('BARIS TAMBAHAN');
        $engine = $this->engine(text: 'HASIL PANJANG UNTUK LOLOS AMBANG MINIMUM', confidence: 10.0, vlm: $vlm, wordConfidences: self::words(10, 5));

        $result = $engine->recognize('dummy.png', 1);

        self::assertNotEmpty($result->words, 'bbox dari Tesseract tidak boleh hilang saat VLM ikut membaca');
    }

    /** @param float[] $wordConfidences */
    private function engine(
        string $text,
        float $confidence,
        object $vlm,
        array $wordConfidences = []
    ): HybridEngine {
        return new HybridEngine(
            new FakeTesseract($text, $confidence, $wordConfidences),
            new VlmEngine($vlm),
        );
    }

    /** @return float[] kata dengan proporsi tertentu berconfidence rendah */
    private static function words(int $total, int $low): array
    {
        return array_merge(array_fill(0, $low, 10.0), array_fill(0, $total - $low, 95.0));
    }

    private function vlm(string $text = '', bool $throws = false): object
    {
        return new class($text, $throws) implements VlmRepositoryInterface {
            public int $calls = 0;

            public function __construct(private string $text, private bool $throws)
            {
            }

            public function transcribe(string $imagePath): string
            {
                $this->calls++;

                if ($this->throws) {
                    throw OcrException::engineFailure('host mati');
                }

                return $this->text;
            }

            public function isAvailable(): bool
            {
                return ! $this->throws;
            }

            public function model(): string
            {
                return 'qwen3-vl:4b';
            }
        };
    }
}

/** Menggantikan TesseractEngine tanpa memanggil binary apa pun. */
final class FakeTesseract implements OcrEngineInterface
{
    /** @param float[] $wordConfidences */
    public function __construct(
        private readonly string $text,
        private readonly float $confidence,
        private readonly array $wordConfidences = [],
    ) {
    }

    public function name(): string
    {
        return 'tesseract';
    }

    public function version(): string
    {
        return '5.4.0';
    }

    public function isAvailable(): bool
    {
        return true;
    }

    /**
     * Word box dibangun dari teks yang dibaca, seperti Tesseract sungguhan —
     * bukan kata karangan yang tidak ada di halaman.
     */
    public function recognize(string $imagePath, int $pageNo, array $options = []): PageResult
    {
        $tokens = preg_split('/\s+/u', trim($this->text), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $words  = [];

        foreach ($this->wordConfidences as $i => $conf) {
            $words[] = new WordBox($tokens[$i] ?? 'KATA'.$i, $conf, 10 * $i, 10, 50, 20, $pageNo);
        }

        return new PageResult($pageNo, $this->text, $words, $this->confidence);
    }
}
