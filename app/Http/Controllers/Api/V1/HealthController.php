<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Contracts\Repositories\BinaryRepositoryInterface;
use App\Contracts\Repositories\EngineRepositoryInterface;
use App\Exceptions\OcrException;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;

final class HealthController extends Controller
{
    private const BINARIES = ['tesseract', 'pdftotext', 'pdftoppm', 'pdfinfo', 'magick', 'gs'];

    public function __invoke(
        BinaryRepositoryInterface $binaries,
        EngineRepositoryInterface $engines,
    ): JsonResponse {
        $versions = [];
        foreach (self::BINARIES as $binary) {
            $versions[$binary] = $binaries->version($binary);
        }

        // Tetap 200 meski degraded: monitoring perlu membaca detail binary mana
        // yang hilang, bukan sekadar tahu bahwa ada yang salah.
        return response()->json([
            'status'             => $this->status($versions) ? 'ok' : 'degraded',
            'engine'             => $engines->default()->name(),
            'binaries'           => $versions,
            'languages'          => $this->languages($binaries),
            'text_layer_enabled' => (bool) config('ocr.text_layer.enabled'),
        ]);
    }

    /** @param array<string,string|null> $versions */
    private function status(array $versions): bool
    {
        $hasRasterizer = $versions['pdftoppm'] !== null || $versions['gs'] !== null;

        return $versions['tesseract'] !== null && $versions['pdftotext'] !== null && $hasRasterizer;
    }

    /** @return string[] */
    private function languages(BinaryRepositoryInterface $binaries): array
    {
        try {
            $output = $binaries->run('tesseract', ['--list-langs'], (int) config('ocr.timeout.version'));
        } catch (OcrException) {
            return [];
        }

        $lines = preg_split('/\R/', trim($output)) ?: [];

        return array_values(array_filter(
            array_map('trim', $lines),
            static fn (string $line): bool => $line !== '' && ! str_contains($line, ':')
        ));
    }
}
