<?php

declare(strict_types=1);

namespace Tests\Unit\Repositories;

use App\Exceptions\OcrException;
use App\Repositories\DocumentRepository;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

final class DocumentRepositoryTest extends TestCase
{
    private const PDF_BYTES = "%PDF-1.4\nisi dokumen contoh\n";

    private string $allowed;

    private string $forbidden;

    private DocumentRepository $repository;

    protected function setUp(): void
    {
        parent::setUp();

        $this->allowed   = $this->makeDir('allowed');
        $this->forbidden = $this->makeDir('forbidden');

        config()->set('ocr.allowed_base_paths', [$this->allowed]);
        config()->set('ocr.workspace_path', $this->makeDir('work'));

        $this->repository = new DocumentRepository();
    }

    protected function tearDown(): void
    {
        foreach (['allowed', 'forbidden', 'work'] as $name) {
            $this->removeDir(storage_path('app/testing/'.$name));
        }

        parent::tearDown();
    }

    public function test_menerima_berkas_di_dalam_direktori_yang_diizinkan(): void
    {
        $path = $this->writeFile($this->allowed, 'npwp.pdf', self::PDF_BYTES);

        $file = $this->repository->resolve(['type' => 'path', 'value' => $path]);

        self::assertSame('pdf', $file->extension);
        self::assertTrue($file->isPdf());
        self::assertFalse($file->isTemporary);
        self::assertSame(strlen(self::PDF_BYTES), $file->sizeBytes);
        self::assertSame('npwp.pdf', $file->originalName);
    }

    public function test_menolak_jenis_sumber_yang_tidak_dikenal(): void
    {
        $this->assertOcrError('INVALID_PAYLOAD', fn () => $this->repository->resolve(['type' => 'ftp']));
    }

    public function test_menolak_path_kosong(): void
    {
        $this->assertOcrError('INVALID_PAYLOAD', fn () => $this->repository->resolve(['type' => 'path', 'value' => '']));
    }

    public function test_menolak_berkas_yang_tidak_ada(): void
    {
        $this->assertOcrError('FILE_NOT_FOUND', fn () => $this->repository->resolve([
            'type'  => 'path',
            'value' => $this->allowed.DIRECTORY_SEPARATOR.'tidak-ada.pdf',
        ]));
    }

    public function test_menolak_path_di_luar_whitelist(): void
    {
        $path = $this->writeFile($this->forbidden, 'rahasia.pdf', self::PDF_BYTES);

        $this->assertOcrError('PATH_NOT_ALLOWED', fn () => $this->repository->resolve(['type' => 'path', 'value' => $path]));
    }

    public function test_menolak_bila_tidak_ada_whitelist_sama_sekali(): void
    {
        config()->set('ocr.allowed_base_paths', []);
        $path = $this->writeFile($this->allowed, 'npwp.pdf', self::PDF_BYTES);

        $this->assertOcrError('PATH_NOT_ALLOWED', fn () => $this->repository->resolve(['type' => 'path', 'value' => $path]));
    }

    public function test_menolak_ekstensi_yang_tidak_didukung(): void
    {
        $path = $this->writeFile($this->allowed, 'dokumen.docx', 'apa saja');

        $this->assertOcrError('UNSUPPORTED_MEDIA_TYPE', fn () => $this->repository->resolve(['type' => 'path', 'value' => $path]));
    }

    public function test_menolak_isi_yang_tidak_cocok_dengan_ekstensinya(): void
    {
        $path = $this->writeFile($this->allowed, 'palsu.pdf', 'ini sebenarnya bukan pdf');

        $this->assertOcrError('UNSUPPORTED_MEDIA_TYPE', fn () => $this->repository->resolve(['type' => 'path', 'value' => $path]));
    }

    public function test_menolak_berkas_kosong(): void
    {
        $path = $this->writeFile($this->allowed, 'kosong.pdf', '');

        $this->assertOcrError('INVALID_PAYLOAD', fn () => $this->repository->resolve(['type' => 'path', 'value' => $path]));
    }

    public function test_menolak_berkas_yang_melebihi_batas_ukuran(): void
    {
        config()->set('ocr.limits.max_file_bytes', 8);
        $path = $this->writeFile($this->allowed, 'besar.pdf', self::PDF_BYTES);

        $this->assertOcrError('FILE_TOO_LARGE', fn () => $this->repository->resolve(['type' => 'path', 'value' => $path]));
    }

    public function test_menerima_base64_dan_menandainya_sementara(): void
    {
        $file = $this->repository->resolve([
            'type'     => 'base64',
            'value'    => base64_encode(self::PDF_BYTES),
            'filename' => 'npwp.pdf',
        ]);

        self::assertTrue($file->isTemporary);
        self::assertFileExists($file->path);

        $this->repository->release($file);
        self::assertFileDoesNotExist($file->path);

        // release() harus aman dipanggil berkali-kali.
        $this->repository->release($file);
    }

    public function test_menerima_data_uri_base64(): void
    {
        $file = $this->repository->resolve([
            'type'     => 'base64',
            'value'    => 'data:application/pdf;base64,'.base64_encode(self::PDF_BYTES),
            'filename' => 'npwp.pdf',
        ]);

        self::assertSame(strlen(self::PDF_BYTES), $file->sizeBytes);
        $this->repository->release($file);
    }

    public function test_menolak_base64_kosong_dan_tidak_valid(): void
    {
        $this->assertOcrError('INVALID_PAYLOAD', fn () => $this->repository->resolve(['type' => 'base64', 'value' => '']));
        $this->assertOcrError('INVALID_PAYLOAD', fn () => $this->repository->resolve([
            'type'     => 'base64',
            'value'    => '!!! bukan base64 !!!',
            'filename' => 'npwp.pdf',
        ]));
    }

    public function test_membersihkan_berkas_sementara_saat_magic_bytes_tidak_cocok(): void
    {
        $workspace = (string) config('ocr.workspace_path');

        $this->assertOcrError('UNSUPPORTED_MEDIA_TYPE', fn () => $this->repository->resolve([
            'type'     => 'base64',
            'value'    => base64_encode('bukan pdf sama sekali'),
            'filename' => 'npwp.pdf',
        ]));

        self::assertSame([], glob($workspace.DIRECTORY_SEPARATOR.'upload'.DIRECTORY_SEPARATOR.'*') ?: []);
    }

    public function test_menolak_upload_yang_tidak_ada(): void
    {
        $this->assertOcrError('INVALID_PAYLOAD', fn () => $this->repository->resolve(['type' => 'upload'], null));
    }

    public function test_menerima_upload_yang_valid(): void
    {
        $source = $this->writeFile($this->allowed, 'sumber.pdf', self::PDF_BYTES);

        $file = $this->repository->resolve(
            ['type' => 'upload'],
            new UploadedFile($source, 'npwp.pdf', 'application/pdf', null, true)
        );

        self::assertTrue($file->isTemporary);
        self::assertSame('npwp.pdf', $file->originalName);

        $this->repository->release($file);
    }

    private function assertOcrError(string $code, callable $action): void
    {
        try {
            $action();
        } catch (OcrException $e) {
            self::assertSame($code, $e->errorCode());

            return;
        }

        self::fail("Diharapkan OcrException dengan kode {$code}, tetapi tidak ada exception yang dilempar.");
    }

    private function makeDir(string $name): string
    {
        $path = storage_path('app/testing/'.$name);

        if (! is_dir($path)) {
            mkdir($path, 0775, true);
        }

        return (string) realpath($path);
    }

    private function writeFile(string $dir, string $name, string $contents): string
    {
        $path = $dir.DIRECTORY_SEPARATOR.$name;
        file_put_contents($path, $contents);

        return $path;
    }

    private function removeDir(string $path): void
    {
        if (! is_dir($path)) {
            return;
        }

        foreach ((array) glob($path.DIRECTORY_SEPARATOR.'*') as $item) {
            if (is_string($item)) {
                is_dir($item) ? $this->removeDir($item) : @unlink($item);
            }
        }

        @rmdir($path);
    }
}
