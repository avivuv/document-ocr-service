<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use Tests\TestCase;

final class AuthenticationTest extends TestCase
{
    private const TOKEN = 'SkpJk7CIrlud2oZKzMxRP-YaXrE3wJI2';

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('ocr.tokens', ['app-intranet' => self::TOKEN]);
    }

    public function test_menerima_token_lewat_bearer_maupun_api_key(): void
    {
        $this->withHeaders(['Authorization' => 'Bearer '.self::TOKEN])
            ->getJson('/api/v1/health')
            ->assertOk();

        $this->withHeaders(['X-Api-Key' => self::TOKEN])
            ->getJson('/api/v1/health')
            ->assertOk();
    }

    public function test_memaafkan_spasi_yang_ikut_tersalin(): void
    {
        $this->withHeaders(['Authorization' => 'Bearer '.self::TOKEN.' '])
            ->getJson('/api/v1/health')
            ->assertOk();

        $this->withHeaders(['X-Api-Key' => ' '.self::TOKEN])
            ->getJson('/api/v1/health')
            ->assertOk();
    }

    public function test_menolak_token_yang_masih_membawa_nama_consumer(): void
    {
        // Kesalahan lumrah: menyalin seluruh baris OCR_API_TOKENS, bukan bagian
        // setelah tanda titik dua.
        $this->withHeaders(['Authorization' => 'Bearer app-intranet:'.self::TOKEN])
            ->getJson('/api/v1/health')
            ->assertStatus(401)
            ->assertJsonPath('error.code', 'UNAUTHORIZED');
    }

    public function test_menjelaskan_bila_token_sama_sekali_tidak_dikirim(): void
    {
        $response = $this->getJson('/api/v1/health')->assertStatus(401);

        self::assertStringContainsString('tidak dikirim', (string) $response->json('error.message'));
    }

    public function test_menjelaskan_bila_server_belum_punya_token_terdaftar(): void
    {
        config()->set('ocr.tokens', []);

        $response = $this->withHeaders(['Authorization' => 'Bearer apa-saja'])
            ->getJson('/api/v1/health')
            ->assertStatus(401);

        self::assertStringContainsString('OCR_API_TOKENS', (string) $response->json('error.message'));
    }

    public function test_menolak_token_yang_salah(): void
    {
        $this->withHeaders(['Authorization' => 'Bearer token-yang-salah'])
            ->getJson('/api/v1/health')
            ->assertStatus(401)
            ->assertJsonPath('error.code', 'UNAUTHORIZED');
    }
}
