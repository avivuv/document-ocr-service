<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Exceptions\OcrException;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Tanpa database, token bersifat statis dan didefinisikan di config. Pembandingan
 * memakai hash_equals() supaya lama pembandingan tidak membocorkan seberapa jauh
 * tebakan penyerang mendekati token yang benar.
 */
final class AuthenticateApiToken
{
    public function handle(Request $request, Closure $next): Response
    {
        $presented = $this->presentedToken($request);
        $known     = (array) config('ocr.tokens', []);

        if ($presented === null) {
            throw OcrException::unauthorized('Token tidak dikirim. Sertakan header Authorization: Bearer <token> atau X-Api-Key.');
        }

        if ($known === []) {
            throw OcrException::unauthorized('Tidak ada token terdaftar di server. Isi OCR_API_TOKENS di .env.');
        }

        foreach ($known as $client => $token) {
            if (is_string($token) && $token !== '' && hash_equals($token, $presented)) {
                $request->attributes->set('ocr_client', (string) $client);

                return $next($request);
            }
        }

        throw OcrException::unauthorized();
    }

    /**
     * Spasi dan baris baru di ujung dipangkas. Token yang dibangkitkan service
     * ini tidak pernah memuat spasi, jadi memangkasnya tidak mungkin membuat dua
     * token berbeda menjadi sama — sementara satu spasi yang ikut tersalin dari
     * .env adalah penyebab 401 yang paling sering dan paling membingungkan.
     */
    private function presentedToken(Request $request): ?string
    {
        foreach ([$request->bearerToken(), $request->header('X-Api-Key')] as $candidate) {
            if (! is_string($candidate)) {
                continue;
            }

            $token = trim($candidate);
            if ($token !== '') {
                return $token;
            }
        }

        return null;
    }
}
