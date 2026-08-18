<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use Tests\TestCase;

final class MetaEndpointTest extends TestCase
{
    private const TOKEN = 'token-untuk-test';

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('ocr.tokens', ['test-client' => self::TOKEN]);
    }

    public function test_health_melaporkan_ketersediaan_binary(): void
    {
        $response = $this->withHeaders(['X-Api-Key' => self::TOKEN])->getJson('/api/v1/health');

        $response->assertOk()
            ->assertJsonStructure(['status', 'engine', 'binaries', 'languages', 'text_layer_enabled']);

        self::assertContains($response->json('status'), ['ok', 'degraded']);
    }

    public function test_health_menolak_tanpa_token(): void
    {
        $this->getJson('/api/v1/health')
            ->assertStatus(401)
            ->assertJsonPath('error.code', 'UNAUTHORIZED');
    }

    public function test_doc_types_dibangun_dari_registry_parser(): void
    {
        $response = $this->withHeaders(['Authorization' => 'Bearer '.self::TOKEN])
            ->getJson('/api/v1/doc-types');

        $response->assertOk();

        $codes = array_column((array) $response->json('doc_types'), 'code');

        self::assertContains('NPWP', $codes);
        self::assertContains('NIB', $codes);
        self::assertSame(['npwp_no', 'nik', 'vendor_name', 'address'], $response->json('doc_types.0.fields'));
    }
}
