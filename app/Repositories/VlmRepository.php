<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Contracts\Repositories\BinaryRepositoryInterface;
use App\Contracts\Repositories\VlmRepositoryInterface;
use App\Exceptions\OcrException;
use Illuminate\Support\Facades\Http;
use Throwable;

final class VlmRepository implements VlmRepositoryInterface
{
    public function __construct(private readonly BinaryRepositoryInterface $binaries)
    {
    }

    public function transcribe(string $imagePath): string
    {
        $prepared = $this->shrink($imagePath);

        try {
            $response = Http::timeout((int) config('ocr.vlm.timeout'))
                ->acceptJson()
                ->post($this->endpoint('/api/generate'), [
                    'model'      => $this->model(),
                    'prompt'     => (string) config('ocr.vlm.prompt'),
                    'images'     => [base64_encode($this->read($prepared))],
                    'stream'     => false,
                    'think'      => false,
                    'keep_alive' => (string) config('ocr.vlm.keep_alive'),
                    // Suhu 0: dokumen yang sama harus menghasilkan bacaan yang sama.
                    // Pada mode hybrid, kesepakatan antar-pembaca adalah dasar
                    // confidence — pembacaan yang berubah-ubah merusak dasar itu.
                    'options'    => ['temperature' => 0],
                ]);

            if (! $response->successful()) {
                throw OcrException::engineFailure(
                    'Host VLM menolak permintaan (HTTP '.$response->status().').'
                );
            }

            return trim((string) $response->json('response', ''));
        } catch (OcrException $e) {
            throw $e;
        } catch (Throwable $e) {
            throw OcrException::engineFailure('Host VLM tidak dapat dihubungi: '.$e->getMessage());
        } finally {
            if ($prepared !== $imagePath && is_file($prepared)) {
                @unlink($prepared);
            }
        }
    }

    public function isAvailable(): bool
    {
        try {
            return Http::timeout(5)->get($this->endpoint('/api/tags'))->successful();
        } catch (Throwable) {
            return false;
        }
    }

    public function model(): string
    {
        return (string) config('ocr.vlm.model');
    }

    /**
     * Batasi sisi terpanjang sebelum dikirim ke model.
     *
     * Gambar resolusi penuh dipecah menjadi ribuan token citra dan membuat KV
     * cache melewati VRAM yang tersedia. Bila ImageMagick tidak ada, gambar
     * dikirim apa adanya — model tetap bekerja, hanya lebih berat.
     */
    private function shrink(string $imagePath): string
    {
        $maxPixels = (int) config('ocr.vlm.max_pixels');

        if ($maxPixels <= 0 || ! $this->binaries->isAvailable('magick')) {
            return $imagePath;
        }

        $target = preg_replace('/\.[^.]+$/', '', $imagePath).'-vlm.png';

        try {
            $this->binaries->run('magick', [
                $imagePath,
                '-auto-orient',
                '-resize', $maxPixels.'x'.$maxPixels.'>',
                $target,
            ], (int) config('ocr.timeout.preprocess'));
        } catch (Throwable) {
            return $imagePath;
        }

        return is_file($target) ? $target : $imagePath;
    }

    private function read(string $path): string
    {
        $contents = @file_get_contents($path);

        if ($contents === false) {
            throw OcrException::engineFailure('Gambar untuk VLM tidak dapat dibaca.');
        }

        return $contents;
    }

    private function endpoint(string $path): string
    {
        return rtrim((string) config('ocr.vlm.base_url'), '/').$path;
    }
}
