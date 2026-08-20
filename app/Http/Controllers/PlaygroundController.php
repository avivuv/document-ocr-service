<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Contracts\Repositories\ParserRepositoryInterface;
use App\DTO\AnalyzeOptions;
use App\DTO\AnalyzeRequestData;
use App\Exceptions\OcrException;
use App\Http\Resources\AnalyzeResource;
use App\Repositories\DocumentRepository;
use App\Services\AnalyzeService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;

/**
 * Sarana uji coba manual tanpa Postman. Bukan bagian dari kontrak API dan
 * WAJIB dimatikan di produksi lewat OCR_PLAYGROUND_ENABLED=false — halaman ini
 * memproses dokumen tanpa token.
 */
final class PlaygroundController extends Controller
{
    public function show(ParserRepositoryInterface $parsers): View
    {
        $this->assertEnabled();

        return view('playground', [
            'docTypes' => $parsers->supportedDocTypes(),
            'engines'  => $this->selectableEngines(),
            'result'   => null,
            'error'    => null,
        ]);
    }

    public function analyze(Request $request, AnalyzeService $analyze, ParserRepositoryInterface $parsers): View
    {
        $this->assertEnabled();

        $docType = $request->input('doc_type');
        $result  = null;
        $error   = null;

        try {
            if (! $request->hasFile('file')) {
                throw OcrException::invalidPayload('Pilih berkas terlebih dahulu.');
            }

            $analyzed = $analyze->analyze(new AnalyzeRequestData(
                requestId: 'playground-'.bin2hex(random_bytes(4)),
                source: ['type' => DocumentRepository::SOURCE_UPLOAD],
                options: AnalyzeOptions::fromArray([
                    'return_raw_text' => $request->boolean('return_raw_text'),
                    'return_words'    => $request->boolean('return_words'),
                    'force_ocr'       => $request->boolean('force_ocr'),
                    'engine'          => $request->input('engine'),
                ]),
                docType: is_string($docType) && $docType !== '' ? mb_strtoupper($docType) : null,
                uploaded: $request->file('file'),
            ));

            $result = AnalyzeResource::make($analyzed)->toArray($request);
        } catch (OcrException $e) {
            $error = '['.$e->errorCode().'] '.$e->getMessage();
        }

        return view('playground', [
            'docTypes' => $parsers->supportedDocTypes(),
            'engines'  => $this->selectableEngines(),
            'result'   => $result,
            'error'    => $error,
        ]);
    }

    /** @return string[] */
    private function selectableEngines(): array
    {
        return array_values((array) config('ocr.engine.selectable', []));
    }

    private function assertEnabled(): void
    {
        abort_unless((bool) config('ocr.playground_enabled'), 404);
    }
}
