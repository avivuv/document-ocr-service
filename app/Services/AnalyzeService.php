<?php

declare(strict_types=1);

namespace App\Services;

use App\Contracts\Repositories\DocumentRepositoryInterface;
use App\Contracts\Repositories\EngineRepositoryInterface;
use App\Contracts\Repositories\ProfileRepositoryInterface;
use App\DTO\AnalyzeRequestData;
use App\DTO\AnalyzeResult;
use App\DTO\Classification;
use App\DTO\DocumentFile;
use App\DTO\PageResult;
use App\DTO\TextLayerProbe;
use Illuminate\Support\Facades\Log;

/**
 * Orkestrator pipeline. Urutan langkah dan alasannya ada di
 * .docs/plans/build-plan.md §Pipeline.
 */
final class AnalyzeService
{
    private const PAGE_SEPARATOR = "\f";

    public function __construct(
        private readonly DocumentRepositoryInterface $documents,
        private readonly ProfileRepositoryInterface $profiles,
        private readonly EngineRepositoryInterface $engines,
        private readonly WorkspaceService $workspace,
        private readonly TextLayerService $textLayer,
        private readonly RasterizeService $rasterize,
        private readonly PreprocessService $preprocess,
        private readonly ClassificationService $classification,
        private readonly ExtractionService $extraction,
        private readonly ConfidenceService $confidence,
    ) {
    }

    public function analyze(AnalyzeRequestData $request): AnalyzeResult
    {
        $startedAt = microtime(true);

        $file      = null;
        $workspace = null;
        $warnings  = [];

        try {
            $file      = $this->documents->resolve($request->source, $request->uploaded);
            $workspace = $this->workspace->create($request->requestId);

            $profile = $this->profiles->forDocType($request->docType);
            $lang    = $request->options->lang ?? $profile['lang'];

            $probe = $this->textLayer->probe($file, $request->options->maxPages);

            /*
             * Memilih engine secara eksplisit berarti meminta jalur OCR. Tanpa ini,
             * PDF ber-text-layer akan mengabaikan pilihan itu tanpa penjelasan —
             * dan di playground pilihannya seolah tidak berpengaruh.
             */
            $useTextLayer = $probe->hasTextLayer
                && ! $request->options->forceOcr
                && $request->options->engine === null;

            if ($useTextLayer) {
                $pages       = $this->pagesFromText($probe->text);
                $engineName  = 'pdftotext';
                $version     = $this->textLayer->version();
                $mode        = AnalyzeResult::MODE_TEXT_LAYER;
            } else {
                $engine = $this->engines->forDocType($request->docType, $request->options->engine);

                if (! $this->preprocess->isAvailable()) {
                    $warnings[] = 'ImageMagick tidak terpasang — gambar diproses tanpa perbaikan citra.';
                }

                $images = $this->rasterize->toPng($file, $workspace, $profile['dpi'], $request->options->maxPages);
                $images = $this->preprocess->apply($images, $workspace, $this->preprocessProfile($file, $profile, $probe));

                $pages = [];
                foreach ($images as $index => $image) {
                    $pages[] = $engine->recognize($image, $index + 1, [
                        'psm'  => $profile['psm'],
                        'lang' => $lang,
                    ]);
                }

                $engineName = $engine->name();
                $version    = $engine->version();
                $mode       = AnalyzeResult::MODE_OCR;
            }

            $text  = $this->textOf($pages);
            $words = $this->wordsOf($pages);

            $classification = $this->resolveDocType($request->docType, $text);

            if ($classification->docType === null) {
                $warnings[] = 'Jenis dokumen tidak dapat ditentukan — tidak ada field yang diekstrak.';
                $fields     = [];
            } else {
                $fields = $this->confidence->score(
                    $this->extraction->extract($classification->docType, $text, $words),
                    $words,
                    $mode
                );
            }

            $pageCount      = max($probe->pageCount, count($pages));
            $pagesProcessed = count($pages);

            if ($pageCount > $pagesProcessed) {
                $warnings[] = sprintf(
                    'halaman %d-%d dilewati (max_pages=%d)',
                    $pagesProcessed + 1,
                    $pageCount,
                    $request->options->maxPages
                );
            }

            $result = new AnalyzeResult(
                requestId: $request->requestId,
                docType: $classification->docType,
                docTypeConfidence: $classification->confidence,
                mode: $mode,
                engineName: $engineName,
                engineVersion: $version,
                lang: $useTextLayer ? '-' : $lang,
                pageCount: $pageCount,
                pagesProcessed: $pagesProcessed,
                processingMs: (int) round((microtime(true) - $startedAt) * 1000),
                fields: $fields,
                pages: $request->options->returnWords ? $pages : [],
                rawText: $request->options->returnRawText ? $text : null,
                warnings: $warnings,
            );

            $this->log($result, $file);

            return $result;
        } finally {
            $this->workspace->destroy($workspace);

            if ($file !== null) {
                $this->documents->release($file);
            }
        }
    }

    /**
     * Ekstensi berkas bukan penanda yang bisa dipercaya: PDF hasil pindaian atau
     * foto yang dibungkus PDF isinya gambar kamera, bukan render bersih.
     *
     * Penanda yang benar adalah ada tidaknya text layer. PDF yang punya text
     * layer pasti terbit digital, sehingga rendernya bersih dan ambang batas
     * lokal menajamkan huruf. Selebihnya — gambar langsung maupun PDF tanpa text
     * layer — sumbernya kamera atau pemindai, dan ambang batas lokal justru
     * mengubah tekstur kertas serta watermark menjadi derau.
     *
     * @param array{psm:int,dpi:int,preprocess:string,lang:string} $profile
     */
    private function preprocessProfile(DocumentFile $file, array $profile, TextLayerProbe $probe): string
    {
        return $file->isPdf() && $probe->hasTextLayer
            ? $profile['preprocess']
            : (string) config('ocr.image_preprocess_profile', 'photo');
    }

    /**
     * doc_type yang dikirim consumer selalu menang; klasifikasi hanya berjalan
     * bila consumer tidak menentukannya. Sesuai kontrak API, doc_type_confidence
     * bernilai null pada kasus pertama.
     */
    private function resolveDocType(?string $docType, string $text): Classification
    {
        if ($docType !== null && $docType !== '') {
            return new Classification(mb_strtoupper($docType), null);
        }

        return $this->classification->detect($text);
    }

    /** @return PageResult[] */
    private function pagesFromText(string $text): array
    {
        $pages = [];

        foreach (explode(self::PAGE_SEPARATOR, $text) as $index => $pageText) {
            $pages[] = new PageResult($index + 1, $pageText);
        }

        return $pages;
    }

    /** @param PageResult[] $pages */
    private function textOf(array $pages): string
    {
        return implode(self::PAGE_SEPARATOR, array_map(
            static fn (PageResult $page): string => $page->text,
            $pages
        ));
    }

    /**
     * @param PageResult[] $pages
     * @return \App\DTO\WordBox[]
     */
    private function wordsOf(array $pages): array
    {
        return array_merge(...array_map(
            static fn (PageResult $page): array => $page->words,
            $pages
        ) ?: [[]]);
    }

    /** Log tidak boleh memuat raw_text — di dalamnya ada NIK, alamat, dan data pribadi lain. */
    private function log(AnalyzeResult $result, DocumentFile $file): void
    {
        Log::info('ocr.analyze', [
            'request_id'      => $result->requestId,
            'doc_type'        => $result->docType,
            'mode'            => $result->mode,
            'engine'          => $result->engineName,
            'pages_processed' => $result->pagesProcessed,
            'fields'          => count($result->fields),
            'processing_ms'   => $result->processingMs,
            'size_bytes'      => $file->sizeBytes,
        ]);
    }
}
