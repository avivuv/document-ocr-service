<?php

declare(strict_types=1);

namespace Tests\Unit\Parsers;

use App\Parsers\NibParser;
use PHPUnit\Framework\TestCase;
use Tests\Fixture;

final class NibParserTest extends TestCase
{
    use Fixture;

    private NibParser $parser;

    protected function setUp(): void
    {
        parent::setUp();
        $this->parser = new NibParser();
    }

    public function test_membaca_dokumen_normal(): void
    {
        $values = $this->values($this->parser->parse($this->fixture('nib_clean.txt')));

        self::assertSame('8120014561234', $values['nib_no']);
        self::assertSame('PT ABC INDONESIA', $values['vendor_name']);
        self::assertSame('12190', $values['postal_code']);
        self::assertStringContainsString('JL JENDERAL SUDIRMAN', $values['address']);
    }

    public function test_mengembalikan_seluruh_kbli_yang_ditemukan(): void
    {
        $values = $this->values($this->parser->parse($this->fixture('nib_clean.txt')));

        self::assertSame('46100, 62019', $values['kbli']);
    }

    public function test_tidak_tertukar_dengan_npwp_pada_dokumen_yang_sama(): void
    {
        $values = $this->values($this->parser->parse($this->fixture('nib_clean.txt')));

        self::assertSame('8120014561234', $values['nib_no']);
        self::assertStringNotContainsString('12345678', $values['nib_no']);
    }

    public function test_memperbaiki_huruf_yang_tertukar_dengan_angka(): void
    {
        $values = $this->values($this->parser->parse($this->fixture('nib_noisy.txt')));

        self::assertSame('8120014561234', $values['nib_no']);
        self::assertSame('57131', $values['postal_code']);
        self::assertSame('46100', $values['kbli']);
        self::assertSame('PT SOLO BERSINAR', $values['vendor_name']);
    }

    public function test_mengembalikan_kosong_bila_field_tidak_ada(): void
    {
        self::assertSame([], $this->parser->parse($this->fixture('no_target_fields.txt')));
    }

    public function test_mengembalikan_kosong_untuk_teks_acak_dan_halaman_kosong(): void
    {
        self::assertSame([], $this->parser->parse($this->fixture('garbage.txt')));
        self::assertSame([], $this->parser->parse($this->fixture('blank.txt')));
    }

    public function test_match_score_lebih_tinggi_untuk_dokumen_nib(): void
    {
        self::assertGreaterThan(0.6, $this->parser->matchScore($this->fixture('nib_clean.txt')));
        self::assertLessThan(0.2, $this->parser->matchScore($this->fixture('no_target_fields.txt')));
    }
}
