<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Contracts\Repositories\ParserRepositoryInterface;
use App\DTO\AnalyzeOptions;
use App\DTO\AnalyzeRequestData;
use App\Exceptions\OcrException;
use App\Repositories\DocumentRepository;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class AnalyzeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Bentuk multipart dan JSON disatukan di sini supaya lapis di bawahnya hanya
     * mengenal satu bentuk payload.
     */
    protected function prepareForValidation(): void
    {
        $merge = [];

        if ($this->hasFile('file') && ! is_array($this->input('source'))) {
            $merge['source'] = ['type' => DocumentRepository::SOURCE_UPLOAD];
        }

        $options = $this->input('options');
        if (is_string($options) && $options !== '') {
            $decoded = json_decode($options, true);

            if (! is_array($decoded)) {
                throw OcrException::invalidPayload('options bukan JSON yang valid.');
            }

            $merge['options'] = $decoded;
        }

        if ($merge !== []) {
            $this->merge($merge);
        }
    }

    public function rules(): array
    {
        $supported = app(ParserRepositoryInterface::class)->supportedDocTypes();

        return [
            'source'                  => ['required', 'array'],
            'source.type'             => ['required', 'string', Rule::in([
                DocumentRepository::SOURCE_PATH,
                DocumentRepository::SOURCE_BASE64,
                DocumentRepository::SOURCE_UPLOAD,
            ])],
            'source.value'            => ['required_if:source.type,path,base64', 'string'],
            'source.filename'         => ['nullable', 'string', 'max:255'],
            'doc_type'                => ['nullable', 'string', Rule::in($supported)],
            'options'                 => ['nullable', 'array'],
            'options.max_pages'       => ['nullable', 'integer', 'min:1'],
            'options.lang'            => ['nullable', 'string', 'max:32'],
            'options.return_raw_text' => ['nullable', 'boolean'],
            'options.return_words'    => ['nullable', 'boolean'],
            'options.force_ocr'       => ['nullable', 'boolean'],
            'file'                    => ['nullable', 'file'],
        ];
    }

    /** Kesalahan payload wajib 400 INVALID_PAYLOAD, bukan 422 bawaan Laravel. */
    protected function failedValidation(Validator $validator): void
    {
        throw OcrException::invalidPayload($validator->errors()->first());
    }

    public function toData(): AnalyzeRequestData
    {
        $docType = $this->input('doc_type');

        return new AnalyzeRequestData(
            requestId: $this->requestId(),
            source: (array) $this->input('source'),
            options: AnalyzeOptions::fromArray((array) $this->input('options', [])),
            docType: is_string($docType) && $docType !== '' ? mb_strtoupper($docType) : null,
            uploaded: $this->file('file'),
        );
    }

    /**
     * X-Request-Id diisi job_id milik consumer. Nilainya ikut di log dan response
     * supaya satu dokumen bisa ditelusuri melintasi log dua aplikasi.
     */
    private function requestId(): string
    {
        $header = $this->header('X-Request-Id');

        return is_string($header) && trim($header) !== ''
            ? mb_substr(trim($header), 0, 64)
            : 'req-'.bin2hex(random_bytes(6));
    }
}
