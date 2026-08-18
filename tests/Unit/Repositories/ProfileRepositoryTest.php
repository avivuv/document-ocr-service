<?php

declare(strict_types=1);

namespace Tests\Unit\Repositories;

use App\Repositories\ProfileRepository;
use Tests\TestCase;

final class ProfileRepositoryTest extends TestCase
{
    private ProfileRepository $repository;

    protected function setUp(): void
    {
        parent::setUp();
        $this->repository = new ProfileRepository();
    }

    public function test_mengambil_profil_jenis_dokumen(): void
    {
        $profile = $this->repository->forDocType('NPWP');

        self::assertSame(6, $profile['psm']);
        self::assertSame(300, $profile['dpi']);
        self::assertSame('document', $profile['preprocess']);
        self::assertSame('ind+eng', $profile['lang']);
    }

    public function test_tidak_membedakan_huruf_besar_kecil(): void
    {
        self::assertSame($this->repository->forDocType('NPWP'), $this->repository->forDocType('npwp'));
    }

    public function test_kembali_ke_profil_default_untuk_jenis_yang_tidak_dikenal(): void
    {
        $default = $this->repository->forDocType(null);

        self::assertSame($default, $this->repository->forDocType('BELUM_ADA'));
        self::assertSame(config('ocr.default_profile.psm'), $default['psm']);
    }

    public function test_melengkapi_kunci_yang_hilang_dari_profil_default(): void
    {
        config()->set('ocr.profiles.PARSIAL', ['psm' => 11]);

        $profile = $this->repository->forDocType('PARSIAL');

        self::assertSame(11, $profile['psm']);
        self::assertSame(config('ocr.default_profile.dpi'), $profile['dpi']);
        self::assertSame(config('ocr.default_profile.lang'), $profile['lang']);
    }

    public function test_mengembalikan_argumen_preprocessing(): void
    {
        self::assertContains('-deskew', $this->repository->preprocessArgs('document'));
        self::assertSame([], $this->repository->preprocessArgs('none'));
        self::assertSame([], $this->repository->preprocessArgs('profil-yang-tidak-ada'));
    }
}
