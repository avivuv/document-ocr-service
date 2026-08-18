<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\DTO\AnalyzeResult;
use App\DTO\FieldResult;
use App\DTO\WordBox;
use App\Services\ConfidenceService;
use PHPUnit\Framework\TestCase;

final class ConfidenceServiceTest extends TestCase
{
    private ConfidenceService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new ConfidenceService();
    }

    public function test_memakai_confidence_terendah_dari_kata_penyusun(): void
    {
        $field = new FieldResult('vendor_name', 'PT ABC INDONESIA', 'PT ABC INDONESIA', 100.0);

        $words = [
            new WordBox('PT', 98.0, 10, 20, 30, 12),
            new WordBox('ABC', 71.5, 45, 20, 40, 12),
            new WordBox('INDONESIA', 93.0, 90, 20, 120, 12),
        ];

        $scored = $this->service->score([$field], $words, AnalyzeResult::MODE_OCR)[0];

        self::assertSame(71.5, $scored->confidence);
        self::assertSame([10, 20, 200, 12], $scored->bbox);
    }

    public function test_tidak_tertukar_dengan_kata_yang_sama_di_kop_dokumen(): void
    {
        $field = new FieldResult('vendor_name', 'PT ABC INDONESIA', 'PT ABC INDONESIA', 100.0);

        // "INDONESIA" muncul dua kali: di kop dokumen dan di nama perusahaan.
        $words = [
            new WordBox('KEMENTERIAN', 96.0, 40, 95, 200, 30),
            new WordBox('KEUANGAN', 96.0, 250, 95, 160, 30),
            new WordBox('REPUBLIK', 96.0, 420, 95, 150, 30),
            new WordBox('INDONESIA', 96.3, 580, 95, 170, 30),
            new WordBox('PT', 96.5, 338, 563, 40, 30),
            new WordBox('ABC', 96.5, 390, 563, 70, 30),
            new WordBox('INDONESIA', 95.5, 470, 563, 170, 30),
        ];

        $scored = $this->service->score([$field], $words, AnalyzeResult::MODE_OCR)[0];

        self::assertSame(95.5, $scored->confidence);
        self::assertSame([338, 563, 302, 30], $scored->bbox);
    }

    public function test_memilih_deretan_paling_rapat_bila_ada_beberapa_kemungkinan(): void
    {
        $field = new FieldResult('address', 'JL JENDERAL SUDIRMAN', 'JL JENDERAL SUDIRMAN', 100.0);

        $words = [
            new WordBox('JL', 90.0, 10, 100, 30, 20),
            new WordBox('AHMAD', 90.0, 50, 100, 90, 20),
            new WordBox('JENDERAL', 90.0, 150, 100, 120, 20),
            new WordBox('PAJAK', 90.0, 280, 100, 90, 20),
            new WordBox('SUDIRMAN', 90.0, 380, 100, 120, 20),
            new WordBox('JL', 88.0, 10, 400, 30, 20),
            new WordBox('JENDERAL', 87.0, 50, 400, 120, 20),
            new WordBox('SUDIRMAN', 89.0, 180, 400, 120, 20),
        ];

        $scored = $this->service->score([$field], $words, AnalyzeResult::MODE_OCR)[0];

        self::assertSame(87.0, $scored->confidence);
        self::assertSame([10, 400, 290, 20], $scored->bbox);
    }

    public function test_mengabaikan_kecocokan_yang_terlalu_sedikit(): void
    {
        $field = new FieldResult('vendor_name', 'PT SEJAHTERA MANDIRI ABADI', 'PT SEJAHTERA MANDIRI ABADI', 77.0);

        $words = [
            new WordBox('PT', 95.0, 10, 10, 30, 20),
            new WordBox('LAINNYA', 95.0, 50, 10, 90, 20),
        ];

        $scored = $this->service->score([$field], $words, AnalyzeResult::MODE_OCR)[0];

        self::assertSame(77.0, $scored->confidence);
        self::assertNull($scored->bbox);
    }

    public function test_mode_text_layer_bernilai_100_dan_tanpa_bbox(): void
    {
        $field = new FieldResult('npwp_no', '0123456789012000', '12.345.678.9-012.000', 100.0);

        $scored = $this->service->score([$field], [], AnalyzeResult::MODE_TEXT_LAYER)[0];

        self::assertSame(100.0, $scored->confidence);
        self::assertNull($scored->bbox);
    }

    public function test_mode_text_layer_tetap_menghormati_keraguan_parser(): void
    {
        // Field yang dibaca dari posisi, bukan dari label: teksnya pasti, tetapi
        // penafsirannya tidak. Text layer tidak boleh menaikkannya jadi 100.
        $field = new FieldResult('vendor_name', 'BUDI SANTOSO', 'BUDI SANTOSO', 75.0);

        $scored = $this->service->score([$field], [], AnalyzeResult::MODE_TEXT_LAYER)[0];

        self::assertSame(75.0, $scored->confidence);
    }

    public function test_mengambil_yang_terendah_antara_keraguan_parser_dan_engine(): void
    {
        $field = new FieldResult('vendor_name', 'BUDI SANTOSO', 'BUDI SANTOSO', 75.0);

        $words = [
            new WordBox('BUDI', 98.0, 10, 20, 60, 20),
            new WordBox('SANTOSO', 97.0, 80, 20, 110, 20),
        ];

        $scored = $this->service->score([$field], $words, AnalyzeResult::MODE_OCR)[0];

        self::assertSame(75.0, $scored->confidence);
        self::assertSame([10, 20, 180, 20], $scored->bbox);
    }

    public function test_membiarkan_field_apa_adanya_bila_tidak_ada_kata_yang_cocok(): void
    {
        $field = new FieldResult('npwp_no', '0123456789012000', '12.345.678.9-012.000', 88.0);

        $scored = $this->service->score([$field], [new WordBox('LAINNYA', 30.0, 0, 0, 10, 10)], AnalyzeResult::MODE_OCR)[0];

        self::assertSame(88.0, $scored->confidence);
        self::assertNull($scored->bbox);
    }

    public function test_mencocokkan_kata_tanpa_terganggu_tanda_baca(): void
    {
        $field = new FieldResult('npwp_no', '0123456789012000', '12.345.678.9-012.000', 100.0);

        $words = [new WordBox('12.345.678.9-012.000', 64.25, 412, 233, 268, 31, 2)];

        $scored = $this->service->score([$field], $words, AnalyzeResult::MODE_OCR)[0];

        self::assertSame(64.25, $scored->confidence);
        self::assertSame(2, $scored->page);
        self::assertSame([412, 233, 268, 31], $scored->bbox);
    }
}
