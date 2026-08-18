<?php

declare(strict_types=1);

namespace Tests\Unit\Parsers;

use App\Parsers\NpwpParser;
use PHPUnit\Framework\TestCase;
use Tests\Fixture;

final class NpwpParserTest extends TestCase
{
    use Fixture;

    private NpwpParser $parser;

    protected function setUp(): void
    {
        parent::setUp();
        $this->parser = new NpwpParser();
    }

    public function test_membaca_dokumen_normal(): void
    {
        $values = $this->values($this->parser->parse($this->fixture('npwp_clean.txt')));

        self::assertSame('0123456789012000', $values['npwp_no']);
        self::assertSame('PT ABC INDONESIA', $values['vendor_name']);
        self::assertSame(
            'JL JENDERAL SUDIRMAN KAV 52-53 KEBAYORAN BARU, JAKARTA SELATAN DKI JAKARTA 12190',
            $values['address']
        );
    }

    public function test_mempertahankan_bentuk_asli_pada_raw(): void
    {
        $fields = $this->parser->parse($this->fixture('npwp_clean.txt'));
        $npwp   = array_values(array_filter($fields, static fn ($f) => $f->name === 'npwp_no'))[0];

        self::assertSame('12.345.678.9-012.000', $npwp->raw);
        self::assertSame('0123456789012000', $npwp->value);
    }

    public function test_memperbaiki_huruf_yang_tertukar_dengan_angka(): void
    {
        $values = $this->values($this->parser->parse($this->fixture('npwp_noisy.txt')));

        self::assertSame('0123456789012000', $values['npwp_no']);
        self::assertSame('PT ABC INDONESIA', $values['vendor_name']);
    }

    public function test_menerima_format_baru_16_digit_apa_adanya(): void
    {
        $values = $this->values($this->parser->parse($this->fixture('npwp_new_format.txt')));

        self::assertSame('0012345678912000', $values['npwp_no']);
        self::assertSame('PT SEJAHTERA MANDIRI', $values['vendor_name']);
    }

    public function test_membaca_kartu_perorangan_yang_tidak_berlabel(): void
    {
        $values = $this->values($this->parser->parse($this->fixture('npwp_kartu_perorangan.txt')));

        self::assertSame('0456789123456000', $values['npwp_no']);
        self::assertSame('3573012509880004', $values['nik']);
        self::assertSame('BUDI SANTOSO', $values['vendor_name']);
        self::assertStringContainsString('KALIDAWIR', $values['address']);
    }

    public function test_menandai_field_hasil_pembacaan_posisi_dengan_confidence_lebih_rendah(): void
    {
        $fields = [];
        foreach ($this->parser->parse($this->fixture('npwp_kartu_perorangan.txt')) as $field) {
            $fields[$field->name] = $field->confidence;
        }

        self::assertSame(100.0, $fields['npwp_no'], 'npwp_no berlabel — kepastian penuh');
        self::assertSame(100.0, $fields['nik'], 'nik berlabel — kepastian penuh');
        self::assertSame(75.0, $fields['vendor_name'], 'nama dibaca dari posisi');
        self::assertSame(75.0, $fields['address'], 'alamat dibaca dari posisi');
    }

    public function test_tidak_mengambil_nik_sebagai_npwp_saat_nomor_gagal_terbaca(): void
    {
        $values = $this->values($this->parser->parse($this->fixture('npwp_nomor_gagal_terbaca.txt')));

        self::assertArrayNotHasKey('npwp_no', $values, 'NIK 16 digit tidak boleh mengisi npwp_no');
        self::assertSame('3573012509880004', $values['nik']);
        self::assertSame('BUDI SANTOSO', $values['vendor_name']);
    }

    public function test_tetap_membaca_kartu_perorangan_dari_teks_ocr_yang_kotor(): void
    {
        $values = $this->values($this->parser->parse($this->fixture('npwp_kartu_ocr_kotor.txt')));

        self::assertSame('0456789123456000', $values['npwp_no']);
        self::assertSame('3573012509880004', $values['nik']);
        self::assertSame('BUDI SANTOSO', $values['vendor_name']);
        self::assertStringNotContainsString('KPP', $values['address'], 'baris KPP bukan bagian alamat');
    }

    public function test_tidak_membaca_nik_di_luar_baris_berlabel(): void
    {
        $tanpaLabelNik = "DIREKTORAT JENDERAL PAJAK\nNPWP : 45.678.912.3-456.000\nBUDI SANTOSO\n3573012509880004\n";

        $values = $this->values($this->parser->parse($tanpaLabelNik));

        self::assertArrayNotHasKey('nik', $values);
        self::assertSame('0456789123456000', $values['npwp_no']);
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

    public function test_match_score_lebih_tinggi_untuk_dokumen_npwp(): void
    {
        $npwp  = $this->parser->matchScore($this->fixture('npwp_clean.txt'));
        $lain  = $this->parser->matchScore($this->fixture('no_target_fields.txt'));

        self::assertGreaterThan(0.5, $npwp);
        self::assertLessThan(0.2, $lain);
    }
}
