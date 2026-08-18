<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\DTO\AnalyzeOptions;
use App\DTO\AnalyzeRequestData;
use App\Exceptions\OcrException;
use App\Http\Resources\AnalyzeResource;
use App\Repositories\DocumentRepository;
use App\Services\AnalyzeService;
use Illuminate\Console\Command;

final class OcrAnalyzeCommand extends Command
{
    protected $signature = 'ocr:analyze
        {file : path berkas yang akan dianalisa}
        {--doc-type= : NPWP, NIB, ... (kosongkan agar service mengklasifikasi sendiri)}
        {--max-pages= : batasi jumlah halaman}
        {--force-ocr : abaikan text layer, paksa jalur OCR}
        {--words : sertakan bbox per kata}
        {--raw : tampilkan raw_text}';

    protected $description = 'Analisa satu berkas dan tampilkan hasilnya sebagai JSON';

    public function handle(AnalyzeService $analyze): int
    {
        $path = (string) $this->argument('file');
        $real = realpath($path);

        if ($real === false) {
            $this->error("Berkas tidak ditemukan: {$path}");

            return self::FAILURE;
        }

        $docType = $this->option('doc-type');

        $request = new AnalyzeRequestData(
            requestId: 'cli-'.bin2hex(random_bytes(4)),
            source: ['type' => DocumentRepository::SOURCE_PATH, 'value' => $real],
            options: AnalyzeOptions::fromArray(array_filter([
                'max_pages'       => $this->option('max-pages'),
                'return_raw_text' => (bool) $this->option('raw'),
                'return_words'    => (bool) $this->option('words'),
                'force_ocr'       => (bool) $this->option('force-ocr'),
            ], static fn ($value): bool => $value !== null)),
            docType: is_string($docType) && $docType !== '' ? mb_strtoupper($docType) : null,
        );

        try {
            $result = $analyze->analyze($request);
        } catch (OcrException $e) {
            $this->error('['.$e->errorCode().'] '.$e->getMessage());

            if ($e->errorCode() === 'PATH_NOT_ALLOWED') {
                $this->line('Tambahkan direktorinya ke OCR_ALLOWED_BASE_PATHS di .env.');
            }

            return self::FAILURE;
        }

        $this->line((string) json_encode(
            AnalyzeResource::make($result)->toArray(request()),
            JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
        ));

        return self::SUCCESS;
    }
}
