<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\DTO\AnalyzeResult;
use App\DTO\FieldResult;
use App\DTO\PageResult;
use App\DTO\WordBox;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Bentuk response wajib persis seperti .docs/API.md — setiap aplikasi consumer
 * membacanya. Menambah key baru aman; mengubah atau menghapus yang sudah ada
 * berarti memutus kontrak.
 *
 * @property-read AnalyzeResult $resource
 */
final class AnalyzeResource extends JsonResource
{
    public static $wrap = null;

    public function toArray(Request $request): array
    {
        $result = $this->resource;

        $payload = [
            'request_id'          => $result->requestId,
            'doc_type'            => $result->docType,
            'doc_type_confidence' => $result->docTypeConfidence,
            'mode'                => $result->mode,
            'engine'              => [
                'name'    => $result->engineName,
                'version' => $result->engineVersion,
                'lang'    => $result->lang,
            ],
            'page_count'      => $result->pageCount,
            'pages_processed' => $result->pagesProcessed,
            'processing_ms'   => $result->processingMs,
            'fields'          => (object) $this->fields($result->fields),
            'raw_text'        => $result->rawText,
            'warnings'        => array_values($result->warnings),
        ];

        if ($result->pages !== []) {
            $payload['words'] = $this->words($result->pages);
        }

        return $payload;
    }

    /**
     * Field yang tidak ditemukan tidak muncul sama sekali — itulah yang
     * membedakan "tidak ada" dari "ada tapi kosong".
     *
     * @param FieldResult[] $fields
     */
    private function fields(array $fields): array
    {
        $shaped = [];

        foreach ($fields as $field) {
            $shaped[$field->name] = [
                'value'      => $field->value,
                'raw'        => $field->raw,
                'confidence' => round($field->confidence, 2),
                'page'       => $field->page,
                'bbox'       => $field->bbox,
            ];
        }

        return $shaped;
    }

    /** @param PageResult[] $pages */
    private function words(array $pages): array
    {
        $words = [];

        foreach ($pages as $page) {
            foreach ($page->words as $word) {
                /** @var WordBox $word */
                $words[] = [
                    'page'       => $word->page,
                    'text'       => $word->text,
                    'confidence' => round($word->confidence, 2),
                    'bbox'       => $word->bbox(),
                ];
            }
        }

        return $words;
    }
}
