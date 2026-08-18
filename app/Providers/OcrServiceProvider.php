<?php

declare(strict_types=1);

namespace App\Providers;

use App\Contracts\OcrEngineInterface;
use App\Contracts\Repositories\BinaryRepositoryInterface;
use App\Contracts\Repositories\DocumentRepositoryInterface;
use App\Contracts\Repositories\EngineRepositoryInterface;
use App\Contracts\Repositories\ParserRepositoryInterface;
use App\Contracts\Repositories\ProfileRepositoryInterface;
use App\Contracts\Repositories\WorkspaceRepositoryInterface;
use App\Engines\FakeEngine;
use App\Engines\TesseractEngine;
use App\Parsers\NibParser;
use App\Parsers\NpwpParser;
use App\Repositories\BinaryRepository;
use App\Repositories\DocumentRepository;
use App\Repositories\EngineRepository;
use App\Repositories\ParserRepository;
use App\Repositories\ProfileRepository;
use App\Repositories\WorkspaceRepository;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\ServiceProvider;

final class OcrServiceProvider extends ServiceProvider
{
    /**
     * Registry parser. Menambah jenis dokumen baru cukup menambah satu baris di
     * sini — endpoint /doc-types membacanya, jadi tidak ada daftar yang perlu
     * disalin ke tempat lain (RULES §10).
     */
    private const PARSERS = [
        NpwpParser::class,
        NibParser::class,
    ];

    public function register(): void
    {
        // Versi binary di-cache per instance, jadi repository ini singleton.
        $this->app->singleton(BinaryRepositoryInterface::class, BinaryRepository::class);
        $this->app->singleton(FakeEngine::class);

        $this->app->bind(DocumentRepositoryInterface::class, DocumentRepository::class);
        $this->app->bind(ProfileRepositoryInterface::class, ProfileRepository::class);
        $this->app->bind(WorkspaceRepositoryInterface::class, WorkspaceRepository::class);

        $this->app->singleton(ParserRepositoryInterface::class, fn (Application $app): ParserRepository => new ParserRepository(
            array_map(static fn (string $parser) => $app->make($parser), self::PARSERS)
        ));

        $this->app->singleton(EngineRepositoryInterface::class, fn (Application $app): EngineRepository => new EngineRepository([
            'tesseract' => $app->make(TesseractEngine::class),
            'fake'      => $app->make(FakeEngine::class),
        ]));

        $this->app->bind(
            OcrEngineInterface::class,
            fn (Application $app): OcrEngineInterface => $app->make(EngineRepositoryInterface::class)->default()
        );
    }
}
