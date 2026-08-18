<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Contracts\Repositories\BinaryRepositoryInterface;
use App\Contracts\Repositories\ParserRepositoryInterface;
use Illuminate\Console\Command;

final class OcrDoctorCommand extends Command
{
    protected $signature = 'ocr:doctor';

    protected $description = 'Periksa ketersediaan binary dan konfigurasi service di mesin ini';

    /** Binary yang ketiadaannya membuat sebagian pipeline tidak bisa berjalan. */
    private const REQUIREMENT = [
        'tesseract' => 'OCR untuk dokumen hasil scan',
        'pdftotext' => 'jalur text layer (PDF digital)',
        'pdftoppm'  => 'rasterisasi PDF',
        'pdfinfo'   => 'ukuran halaman PDF, penjaga agar rasterisasi tidak meledak',
        'magick'    => 'perbaikan citra sebelum OCR',
        'gs'        => 'rasterisasi cadangan bila poppler tidak ada',
    ];

    public function handle(BinaryRepositoryInterface $binaries, ParserRepositoryInterface $parsers): int
    {
        $rows    = [];
        $missing = [];

        foreach (self::REQUIREMENT as $binary => $purpose) {
            $version = $binaries->version($binary);

            if ($version === null) {
                $missing[] = $binary;
            }

            $rows[] = [
                $binary,
                $version ?? '-',
                $version === null ? 'tidak terpasang' : 'ok',
                $purpose,
            ];
        }

        $this->table(['binary', 'versi', 'status', 'dipakai untuk'], $rows);

        $this->line('');
        $this->line('Engine default      : '.config('ocr.engine.default'));
        $this->line('Text layer          : '.(config('ocr.text_layer.enabled') ? 'aktif' : 'nonaktif'));
        $this->line('Jenis dokumen       : '.implode(', ', $parsers->supportedDocTypes()));
        $this->line('Token terdaftar     : '.count((array) config('ocr.tokens')));
        $this->line('Playground          : '.(config('ocr.playground_enabled') ? 'AKTIF (matikan di produksi)' : 'nonaktif'));
        $this->line('Workspace           : '.config('ocr.workspace_path'));
        $this->line('Base path diizinkan :');

        foreach ((array) config('ocr.allowed_base_paths') as $path) {
            $this->line('  - '.$path.(is_dir($path) ? '' : '  [direktori tidak ditemukan]'));
        }

        if ($missing === []) {
            $this->newLine();
            $this->info('Seluruh binary terpasang.');

            return self::SUCCESS;
        }

        $this->newLine();
        $this->warn('Belum terpasang: '.implode(', ', $missing));

        if (in_array('tesseract', $missing, true)) {
            $this->line('Jalur OCR belum bisa diuji. Untuk sementara pakai OCR_ENGINE=fake, atau pasang');
            $this->line('Tesseract (UB-Mannheim) beserta ind.traineddata dari tessdata_best.');
        }

        return self::SUCCESS;
    }
}
