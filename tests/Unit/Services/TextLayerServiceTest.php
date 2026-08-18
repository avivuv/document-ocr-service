<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Contracts\Repositories\BinaryRepositoryInterface;
use App\DTO\DocumentFile;
use App\Exceptions\OcrException;
use App\Services\TextLayerService;
use Tests\TestCase;

final class TextLayerServiceTest extends TestCase
{
    public function test_mengenali_pdf_digital_sebagai_text_layer(): void
    {
        $page = str_repeat('Nomor Induk Berusaha 8120014561234 PT ABC INDONESIA ', 20);

        $probe = $this->serviceReturning($page."\f".$page."\f")->probe($this->pdf(), 5);

        self::assertTrue($probe->hasTextLayer);
        self::assertSame(2, $probe->pageCount);
        self::assertSame(2, $probe->pagesRead);
    }

    public function test_membatasi_teks_pada_max_pages(): void
    {
        $page = str_repeat('kata bermakna sebanyak mungkin supaya ambang terlampaui ', 20);

        $probe = $this->serviceReturning(implode("\f", array_fill(0, 4, $page)))->probe($this->pdf(), 2);

        self::assertSame(4, $probe->pageCount);
        self::assertSame(2, $probe->pagesRead);
        self::assertSame(2, substr_count($probe->text, "\f") + 1);
    }

    public function test_menganggap_tidak_ada_text_layer_bila_teks_terlalu_sedikit(): void
    {
        self::assertFalse($this->serviceReturning("halaman scan\f")->probe($this->pdf(), 5)->hasTextLayer);
    }

    public function test_menganggap_tidak_ada_text_layer_bila_teks_kotor(): void
    {
        // PDF hasil scan yang sudah di-OCR mesin pemindai: text layer ada tapi kacau.
        $garbage = str_repeat('~^*|}{><=+`\\ ', 60);

        self::assertFalse($this->serviceReturning($garbage)->probe($this->pdf(), 5)->hasTextLayer);
    }

    public function test_kembali_ke_jalur_ocr_bila_pdftotext_gagal(): void
    {
        $binaries = new class implements BinaryRepositoryInterface
        {
            public function run(string $binKey, array $args, ?int $timeout = null): string
            {
                throw OcrException::engineFailure('pdftotext tidak terpasang.');
            }

            public function version(string $binKey): ?string
            {
                return null;
            }

            public function isAvailable(string $binKey): bool
            {
                return false;
            }
        };

        $probe = (new TextLayerService($binaries))->probe($this->pdf(), 5);

        self::assertFalse($probe->hasTextLayer);
        self::assertSame('', $probe->text);
    }

    public function test_melewati_berkas_yang_bukan_pdf(): void
    {
        $image = new DocumentFile('C:/tmp/ktp.png', 'png', 1024, 'ktp.png');

        self::assertFalse($this->serviceReturning('apa pun')->probe($image, 5)->hasTextLayer);
    }

    public function test_menghormati_saklar_konfigurasi(): void
    {
        config()->set('ocr.text_layer.enabled', false);

        $page = str_repeat('teks yang sangat panjang dan bermakna sekali ', 30);

        self::assertFalse($this->serviceReturning($page)->probe($this->pdf(), 5)->hasTextLayer);
    }

    private function pdf(): DocumentFile
    {
        return new DocumentFile('C:/tmp/nib.pdf', 'pdf', 2048, 'nib.pdf');
    }

    private function serviceReturning(string $output): TextLayerService
    {
        $binaries = new class($output) implements BinaryRepositoryInterface
        {
            public function __construct(private readonly string $output)
            {
            }

            public function run(string $binKey, array $args, ?int $timeout = null): string
            {
                return $this->output;
            }

            public function version(string $binKey): ?string
            {
                return '24.02.0';
            }

            public function isAvailable(string $binKey): bool
            {
                return true;
            }
        };

        return new TextLayerService($binaries);
    }
}
