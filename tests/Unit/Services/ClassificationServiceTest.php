<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Parsers\NibParser;
use App\Parsers\NpwpParser;
use App\Repositories\ParserRepository;
use App\Services\ClassificationService;
use Tests\Fixture;
use Tests\TestCase;

final class ClassificationServiceTest extends TestCase
{
    use Fixture;

    private ClassificationService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = new ClassificationService(
            new ParserRepository([new NpwpParser(), new NibParser()])
        );
    }

    public function test_mengenali_npwp(): void
    {
        $classification = $this->service->detect($this->fixture('npwp_clean.txt'));

        self::assertSame('NPWP', $classification->docType);
        self::assertGreaterThan(0.5, $classification->confidence);
    }

    public function test_mengenali_nib_meski_dokumen_juga_memuat_npwp(): void
    {
        $classification = $this->service->detect($this->fixture('nib_clean.txt'));

        self::assertSame('NIB', $classification->docType);
    }

    public function test_tidak_menebak_bila_tidak_ada_yang_meyakinkan(): void
    {
        $classification = $this->service->detect($this->fixture('no_target_fields.txt'));

        self::assertNull($classification->docType);
        self::assertNull($classification->confidence);
    }

    public function test_tidak_menebak_untuk_teks_kosong(): void
    {
        self::assertNull($this->service->detect('')->docType);
        self::assertNull($this->service->detect("   \n  ")->docType);
    }

    public function test_menghormati_ambang_skor_dari_config(): void
    {
        // Petunjuk lemah: ada kata "NPWP" dan sebuah nomor, tanpa konteks DJP.
        $lemah = "NPWP : 12.345.678.9-012.000\n";

        self::assertSame('NPWP', $this->service->detect($lemah)->docType);

        config()->set('ocr.classification.min_score', 0.5);

        self::assertNull($this->service->detect($lemah)->docType);
    }
}
