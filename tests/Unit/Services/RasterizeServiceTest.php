<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Contracts\Repositories\BinaryRepositoryInterface;
use App\Contracts\Repositories\WorkspaceRepositoryInterface;
use App\DTO\DocumentFile;
use App\Exceptions\OcrException;
use App\Services\RasterizeService;
use Tests\TestCase;

final class RasterizeServiceTest extends TestCase
{
    /** @var array<int,array{bin:string,args:string[]}> */
    private array $calls = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->calls = [];
        config()->set('ocr.limits.max_raster_px', 3500);
    }

    public function test_meneruskan_berkas_gambar_tanpa_rasterisasi(): void
    {
        $image = new DocumentFile('C:/tmp/ktp.png', 'png', 2048, 'ktp.png');

        $result = $this->service('595 x 842')->toPng($image, 'C:/tmp/work', 300, 5);

        self::assertSame(['C:/tmp/ktp.png'], $result);
        self::assertSame([], $this->calls, 'berkas gambar tidak boleh memanggil binary apa pun');
    }

    public function test_mempertahankan_dpi_untuk_halaman_berukuran_wajar(): void
    {
        $this->service('595 x 842')->toPng($this->pdf(), 'C:/tmp/work', 300, 5);

        $dpi = $this->dpiUsed();

        self::assertLessThanOrEqual(300, $dpi);
        self::assertGreaterThan(280, $dpi, 'halaman A4 tidak boleh diturunkan drastis');
        self::assertLessThanOrEqual(3500, (int) round(842 / 72 * $dpi));
    }

    /**
     * PDF hasil bungkus foto memakai ukuran halaman sebesar piksel gambarnya.
     * Tanpa penjaga ini, 4000 pt pada 300 dpi menjadi 16.667 px dan prosesnya
     * memakan dua menit lalu gagal.
     */
    public function test_menurunkan_dpi_untuk_halaman_raksasa_hasil_bungkus_foto(): void
    {
        $this->service('4000 x 3000')->toPng($this->pdf(), 'C:/tmp/work', 300, 5);

        $dpi = $this->dpiUsed();

        self::assertLessThan(100, $dpi);
        self::assertLessThanOrEqual(3500, (int) round(4000 / 72 * $dpi));
    }

    public function test_menghormati_batas_piksel_dari_config(): void
    {
        config()->set('ocr.limits.max_raster_px', 1200);

        $this->service('4000 x 3000')->toPng($this->pdf(), 'C:/tmp/work', 300, 5);

        self::assertLessThanOrEqual(1200, (int) round(4000 / 72 * $this->dpiUsed()));
    }

    public function test_memakai_dpi_apa_adanya_bila_pdfinfo_tidak_tersedia(): void
    {
        $this->service(null)->toPng($this->pdf(), 'C:/tmp/work', 300, 5);

        self::assertSame(300, $this->dpiUsed());
    }

    public function test_menolak_dokumen_yang_tidak_menghasilkan_halaman(): void
    {
        $this->expectException(OcrException::class);

        $this->service('595 x 842', produceFiles: false)->toPng($this->pdf(), 'C:/tmp/work', 300, 5);
    }

    private function pdf(): DocumentFile
    {
        return new DocumentFile('C:/tmp/npwp.pdf', 'pdf', 4096, 'npwp.pdf');
    }

    private function dpiUsed(): int
    {
        foreach ($this->calls as $call) {
            if ($call['bin'] !== 'pdftoppm') {
                continue;
            }

            $position = array_search('-r', $call['args'], true);
            if ($position !== false) {
                return (int) $call['args'][$position + 1];
            }
        }

        self::fail('pdftoppm tidak pernah dipanggil dengan -r');
    }

    private function service(?string $pageSize, bool $produceFiles = true): RasterizeService
    {
        $calls = &$this->calls;

        $binaries = new class($pageSize, $calls) implements BinaryRepositoryInterface
        {
            /** @param array<int,array{bin:string,args:string[]}> $calls */
            public function __construct(private readonly ?string $pageSize, private array &$calls)
            {
            }

            public function run(string $binKey, array $args, ?int $timeout = null): string
            {
                $this->calls[] = ['bin' => $binKey, 'args' => $args];

                if ($binKey === 'pdfinfo') {
                    return "Pages:           1\nPage size:       {$this->pageSize} pts\n";
                }

                return '';
            }

            public function version(string $binKey): ?string
            {
                if ($binKey === 'pdfinfo' && $this->pageSize === null) {
                    return null;
                }

                return '25.07.0';
            }

            public function isAvailable(string $binKey): bool
            {
                return $this->version($binKey) !== null;
            }
        };

        $workspaces = new class($produceFiles) implements WorkspaceRepositoryInterface
        {
            public function __construct(private readonly bool $produceFiles)
            {
            }

            public function create(string $name): string
            {
                return 'C:/tmp/work';
            }

            public function destroy(string $directory): void
            {
            }

            public function filesIn(string $directory, string $pattern = '*'): array
            {
                return $this->produceFiles ? ['C:/tmp/work/page-1.png'] : [];
            }

            public function purgeStale(int $olderThanHours): int
            {
                return 0;
            }
        };

        return new RasterizeService($binaries, $workspaces);
    }
}
