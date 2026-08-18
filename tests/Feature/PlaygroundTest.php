<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Engines\FakeEngine;
use Illuminate\Http\UploadedFile;
use Tests\Fixture;
use Tests\TestCase;

final class PlaygroundTest extends TestCase
{
    use Fixture;

    private const PNG_1X1 = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==';

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('ocr.playground_enabled', true);
        config()->set('ocr.engine.default', 'fake');

        $this->app->make(FakeEngine::class)->reset();
    }

    public function test_menampilkan_form_dan_jenis_dokumen_yang_didukung(): void
    {
        $this->get('/playground')
            ->assertOk()
            ->assertSee('Playground')
            ->assertSee('NPWP')
            ->assertSee('NIB');
    }

    public function test_menganalisa_berkas_yang_diunggah(): void
    {
        $this->app->make(FakeEngine::class)->queue($this->fixture('npwp_clean.txt'));

        $this->post('/playground', [
            'file' => $this->pngUpload(),
            'doc_type' => 'NPWP',
        ])
            ->assertOk()
            ->assertSee('0123456789012000')
            ->assertSee('PT ABC INDONESIA');
    }

    public function test_menampilkan_pesan_kesalahan_alih_alih_halaman_error(): void
    {
        $this->post('/playground', ['doc_type' => 'NPWP'])
            ->assertOk()
            ->assertSee('INVALID_PAYLOAD');
    }

    public function test_tidak_dapat_diakses_saat_dimatikan(): void
    {
        config()->set('ocr.playground_enabled', false);

        $this->get('/playground')->assertNotFound();
        $this->post('/playground', ['file' => $this->pngUpload()])->assertNotFound();
    }

    private function pngUpload(): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'ocr').'.png';
        file_put_contents($path, (string) base64_decode(self::PNG_1X1, true));

        return new UploadedFile($path, 'dokumen.png', 'image/png', null, true);
    }
}
