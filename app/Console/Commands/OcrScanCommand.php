<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\DTO\AnalyzeOptions;
use App\DTO\AnalyzeRequestData;
use App\DTO\AnalyzeResult;
use App\Exceptions\OcrException;
use App\Repositories\DocumentRepository;
use App\Services\AnalyzeService;
use Illuminate\Console\Command;

/**
 * Uji akurasi massal: satu folder berisi dokumen contoh diproses sekaligus,
 * hasilnya ditampilkan sebagai tabel ringkas supaya mudah dibandingkan dengan
 * isi dokumen sebenarnya.
 */
final class OcrScanCommand extends Command
{
    protected $signature = 'ocr:scan
        {dir : direktori berisi berkas yang akan diproses}
        {--doc-type= : paksa jenis dokumen untuk seluruh berkas}
        {--max-pages= : batasi jumlah halaman}
        {--force-ocr : abaikan text layer, paksa jalur OCR}
        {--engine= : tesseract, vlm, atau hybrid (kosongkan agar memakai default)}';

    protected $description = 'Proses seluruh berkas dalam satu folder dan tampilkan ringkasannya';

    public function handle(AnalyzeService $analyze): int
    {
        $dir = realpath((string) $this->argument('dir'));

        if ($dir === false || ! is_dir($dir)) {
            $this->error('Direktori tidak ditemukan: '.$this->argument('dir'));

            return self::FAILURE;
        }

        $files = $this->documentsIn($dir);

        if ($files === []) {
            $this->warn('Tidak ada berkas yang didukung di '.$dir);

            return self::SUCCESS;
        }

        $docType = $this->option('doc-type');
        $rows    = [];

        foreach ($files as $file) {
            $rows[] = $this->process($analyze, $file, is_string($docType) && $docType !== '' ? mb_strtoupper($docType) : null);
        }

        $this->table(['berkas', 'doc_type', 'mode', 'field', 'ms', 'catatan'], $rows);

        return self::SUCCESS;
    }

    /** @return string[] */
    private function documentsIn(string $dir): array
    {
        $found = [];

        foreach ((array) config('ocr.allowed_extensions') as $extension) {
            foreach ((array) glob($dir.DIRECTORY_SEPARATOR.'*.'.$extension) as $path) {
                if (is_string($path) && is_file($path)) {
                    $found[] = $path;
                }
            }
        }

        sort($found, SORT_NATURAL);

        return $found;
    }

    /** @return array{0:string,1:string,2:string,3:string,4:string,5:string} */
    private function process(AnalyzeService $analyze, string $file, ?string $docType): array
    {
        $request = new AnalyzeRequestData(
            requestId: 'scan-'.bin2hex(random_bytes(4)),
            source: ['type' => DocumentRepository::SOURCE_PATH, 'value' => $file],
            options: AnalyzeOptions::fromArray(array_filter([
                'max_pages'       => $this->option('max-pages'),
                'return_raw_text' => false,
                'force_ocr'       => (bool) $this->option('force-ocr'),
                'engine'          => $this->option('engine'),
            ], static fn ($value): bool => $value !== null)),
            docType: $docType,
        );

        try {
            $result = $analyze->analyze($request);
        } catch (OcrException $e) {
            return [basename($file), '-', '-', '-', '-', $e->errorCode()];
        }

        return [
            basename($file),
            $result->docType ?? '-',
            $result->mode,
            $this->fieldSummary($result),
            (string) $result->processingMs,
            $result->warnings === [] ? '' : $result->warnings[0],
        ];
    }

    private function fieldSummary(AnalyzeResult $result): string
    {
        if ($result->fields === []) {
            return '-';
        }

        return implode(', ', array_map(
            static fn ($field): string => $field->name.'='.mb_substr($field->value, 0, 24),
            $result->fields
        ));
    }
}
