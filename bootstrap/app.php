<?php

use App\Exceptions\OcrException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        // routes/api.php didaftarkan manual. "php artisan install:api" sengaja
        // tidak dipakai karena memasang Sanctum beserta migration yang butuh
        // database, bertentangan dengan sifat stateless service ini.
        api: __DIR__.'/../routes/api.php',
        apiPrefix: 'api',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Session memakai driver "array" (stateless), sehingga token CSRF tidak
        // bertahan antar-request. Playground adalah sarana uji coba lokal yang
        // dimatikan di produksi, jadi dikecualikan alih-alih memaksa session.
        $middleware->validateCsrfTokens(except: ['playground']);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->render(function (OcrException $e, Request $request) {
            return response()->json([
                'error' => [
                    'code'       => $e->errorCode(),
                    'message'    => $e->getMessage(),
                    'request_id' => $request->header('X-Request-Id'),
                ],
            ], $e->httpStatus());
        });

        // Consumer adalah worker yang mengurai JSON. Halaman error HTML bawaan
        // Laravel akan membuatnya gagal mengurai penyebab kegagalan.
        $exceptions->render(function (Throwable $e, Request $request) {
            if (! $request->is('api/*')) {
                return null;
            }

            $status = $e instanceof HttpExceptionInterface ? $e->getStatusCode() : 500;

            $code = match ($status) {
                404     => 'NOT_FOUND',
                405     => 'METHOD_NOT_ALLOWED',
                413     => 'FILE_TOO_LARGE',
                default => $status >= 500 ? 'ENGINE_FAILURE' : 'INVALID_PAYLOAD',
            };

            $message = $status >= 500 && ! config('app.debug')
                ? 'Terjadi kesalahan internal.'
                : $e->getMessage();

            return response()->json([
                'error' => [
                    'code'       => $code,
                    'message'    => $message,
                    'request_id' => $request->header('X-Request-Id'),
                ],
            ], $status);
        });
    })->create();
