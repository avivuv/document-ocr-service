<?php

declare(strict_types=1);

namespace Tests\Unit\Repositories;

use App\Repositories\WorkspaceRepository;
use Tests\TestCase;

final class WorkspaceRepositoryTest extends TestCase
{
    private string $root;

    private WorkspaceRepository $repository;

    protected function setUp(): void
    {
        parent::setUp();

        $this->root = storage_path('app/testing/work');
        if (! is_dir($this->root)) {
            mkdir($this->root, 0775, true);
        }

        config()->set('ocr.workspace_path', (string) realpath($this->root));

        $this->repository = new WorkspaceRepository();
    }

    protected function tearDown(): void
    {
        $this->removeDir(storage_path('app/testing/work'));

        parent::tearDown();
    }

    public function test_membuat_direktori_kerja_di_dalam_root(): void
    {
        $directory = $this->repository->create('job-8842');

        self::assertDirectoryExists($directory);
        self::assertStringStartsWith((string) realpath($this->root), $directory);
    }

    public function test_menghapus_seluruh_isi_direktori(): void
    {
        $directory = $this->repository->create('job-1');
        mkdir($directory.DIRECTORY_SEPARATOR.'nested');
        file_put_contents($directory.DIRECTORY_SEPARATOR.'page-001.png', 'x');
        file_put_contents($directory.DIRECTORY_SEPARATOR.'nested'.DIRECTORY_SEPARATOR.'ocr.tsv', 'y');

        $this->repository->destroy($directory);

        self::assertDirectoryDoesNotExist($directory);
    }

    public function test_aman_dipanggil_untuk_direktori_yang_sudah_hilang(): void
    {
        $directory = $this->repository->create('job-2');
        $this->repository->destroy($directory);
        $this->repository->destroy($directory);

        self::assertDirectoryDoesNotExist($directory);
    }

    public function test_menolak_menghapus_direktori_di_luar_root(): void
    {
        $outside = storage_path('app/testing/bukan-workspace');
        if (! is_dir($outside)) {
            mkdir($outside, 0775, true);
        }
        file_put_contents($outside.DIRECTORY_SEPARATOR.'penting.txt', 'jangan dihapus');

        $this->repository->destroy($outside);

        self::assertFileExists($outside.DIRECTORY_SEPARATOR.'penting.txt');

        $this->removeDir($outside);
    }

    public function test_mendaftar_berkas_sesuai_pola_dan_terurut(): void
    {
        $directory = $this->repository->create('job-3');
        foreach (['page-002.png', 'page-001.png', 'page-010.png', 'catatan.txt'] as $name) {
            file_put_contents($directory.DIRECTORY_SEPARATOR.$name, 'x');
        }

        $found = array_map('basename', $this->repository->filesIn($directory, 'page*.png'));

        self::assertSame(['page-001.png', 'page-002.png', 'page-010.png'], $found);
    }

    public function test_membersihkan_sisa_direktori_yang_sudah_usang(): void
    {
        $lama = $this->repository->create('job-lama');
        $baru = $this->repository->create('job-baru');

        touch($lama, time() - (3 * 3600));

        $purged = $this->repository->purgeStale(1);

        self::assertSame(1, $purged);
        self::assertDirectoryDoesNotExist($lama);
        self::assertDirectoryExists($baru);
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
