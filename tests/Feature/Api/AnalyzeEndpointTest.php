<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Engines\FakeEngine;
use Illuminate\Http\UploadedFile;
use Tests\Fixture;
use Tests\TestCase;

final class AnalyzeEndpointTest extends TestCase
{
    use Fixture;

    private const TOKEN = 'token-untuk-test';

    private const PNG_1X1 = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==';

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('ocr.tokens', ['test-client' => self::TOKEN]);
        config()->set('ocr.engine.default', 'fake');

        // Workspace tersendiri: tanpa ini, pemeriksaan "tidak ada berkas
        // tertinggal" ikut melihat sisa milik proses lain di direktori bersama.
        $workspace = storage_path('app/testing/analyze-'.bin2hex(random_bytes(4)));
        mkdir($workspace, 0775, true);
        config()->set('ocr.workspace_path', $workspace);

        $this->app->make(FakeEngine::class)->reset();
    }

    protected function tearDown(): void
    {
        $this->removeDir((string) config('ocr.workspace_path'));

        parent::tearDown();
    }

    public function test_menganalisa_dokumen_lewat_upload(): void
    {
        $this->app->make(FakeEngine::class)->queue($this->fixture('npwp_clean.txt'));

        $response = $this->withHeaders($this->authHeaders())
            ->post('/api/v1/documents/analyze', [
                'file' => $this->pngUpload(),
                'doc_type' => 'NPWP',
            ]);

        $response->assertOk()
            ->assertJsonPath('doc_type', 'NPWP')
            ->assertJsonPath('doc_type_confidence', null)
            ->assertJsonPath('mode', 'OCR')
            ->assertJsonPath('engine.name', 'fake')
            ->assertJsonPath('fields.npwp_no.value', '0123456789012000')
            ->assertJsonPath('fields.npwp_no.raw', '12.345.678.9-012.000')
            ->assertJsonPath('fields.vendor_name.value', 'PT ABC INDONESIA');

        self::assertEqualsWithDelta(95.0, $response->json('fields.npwp_no.confidence'), 0.01);
        self::assertIsArray($response->json('fields.npwp_no.bbox'));
    }

    public function test_mengklasifikasi_sendiri_bila_doc_type_tidak_dikirim(): void
    {
        $this->app->make(FakeEngine::class)->queue($this->fixture('nib_clean.txt'));

        $this->withHeaders($this->authHeaders())
            ->post('/api/v1/documents/analyze', ['file' => $this->pngUpload()])
            ->assertOk()
            ->assertJsonPath('doc_type', 'NIB')
            ->assertJsonPath('fields.nib_no.value', '8120014561234');
    }

    public function test_field_yang_tidak_ditemukan_tidak_muncul_di_response(): void
    {
        $this->app->make(FakeEngine::class)->queue($this->fixture('no_target_fields.txt'));

        $response = $this->withHeaders($this->authHeaders())
            ->post('/api/v1/documents/analyze', [
                'file' => $this->pngUpload(),
                'doc_type' => 'NPWP',
            ]);

        $response->assertOk();
        self::assertSame([], (array) $response->json('fields'));
    }

    public function test_menolak_request_tanpa_token(): void
    {
        $this->postJson('/api/v1/documents/analyze', [
            'source' => ['type' => 'path', 'value' => 'apa saja'],
        ])
            ->assertStatus(401)
            ->assertJsonPath('error.code', 'UNAUTHORIZED');
    }

    public function test_menolak_payload_yang_salah_bentuk(): void
    {
        $this->withHeaders($this->authHeaders())
            ->postJson('/api/v1/documents/analyze', ['source' => ['type' => 'entah']])
            ->assertStatus(400)
            ->assertJsonPath('error.code', 'INVALID_PAYLOAD');
    }

    public function test_menolak_doc_type_yang_belum_didukung(): void
    {
        $this->withHeaders($this->authHeaders())
            ->postJson('/api/v1/documents/analyze', [
                'source' => ['type' => 'path', 'value' => 'apa saja'],
                'doc_type' => 'AKTA_PENDIRIAN',
            ])
            ->assertStatus(400)
            ->assertJsonPath('error.code', 'INVALID_PAYLOAD');
    }

    public function test_menolak_path_di_luar_direktori_yang_diizinkan(): void
    {
        $this->withHeaders($this->authHeaders())
            ->postJson('/api/v1/documents/analyze', [
                'source' => ['type' => 'path', 'value' => base_path('composer.json')],
            ])
            ->assertStatus(400)
            ->assertJsonPath('error.code', 'PATH_NOT_ALLOWED');
    }

    public function test_meneruskan_request_id_dari_consumer(): void
    {
        $this->app->make(FakeEngine::class)->queue($this->fixture('npwp_clean.txt'));

        $this->withHeaders($this->authHeaders() + ['X-Request-Id' => 'job-8842'])
            ->post('/api/v1/documents/analyze', [
                'file' => $this->pngUpload(),
                'doc_type' => 'NPWP',
            ])
            ->assertOk()
            ->assertJsonPath('request_id', 'job-8842')
            ->assertHeader('X-Request-Id', 'job-8842');
    }

    public function test_tidak_meninggalkan_berkas_turunan(): void
    {
        $this->app->make(FakeEngine::class)->queue($this->fixture('npwp_clean.txt'));

        $this->withHeaders($this->authHeaders())
            ->post('/api/v1/documents/analyze', [
                'file' => $this->pngUpload(),
                'doc_type' => 'NPWP',
            ])
            ->assertOk();

        $uploadDir = config('ocr.workspace_path').DIRECTORY_SEPARATOR.'upload';
        $leftovers = glob($uploadDir.DIRECTORY_SEPARATOR.'*') ?: [];

        self::assertSame([], $leftovers, 'Berkas upload sementara harus dihapus setelah request.');
    }

    /** @return array<string,string> */
    private function authHeaders(): array
    {
        return ['Authorization' => 'Bearer '.self::TOKEN];
    }

    private function removeDir(string $path): void
    {
        if (! is_dir($path) || ! str_contains($path, 'testing')) {
            return;
        }

        foreach ((array) glob($path.DIRECTORY_SEPARATOR.'*') as $item) {
            if (is_string($item)) {
                is_dir($item) ? $this->removeDir($item) : @unlink($item);
            }
        }

        @rmdir($path);
    }

    private function pngUpload(): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'ocr').'.png';
        file_put_contents($path, (string) base64_decode(self::PNG_1X1, true));

        return new UploadedFile($path, 'dokumen.png', 'image/png', null, true);
    }
}
