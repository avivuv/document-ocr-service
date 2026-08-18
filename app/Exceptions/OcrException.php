<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

/**
 * Kegagalan yang sudah dipetakan ke kode error dan status HTTP kontrak API.
 *
 * Pembedaan retry vs permanen ada di sisi consumer, dan bergantung pada status
 * yang dikembalikan di sini — 5xx boleh diulang, 4xx tidak. Karena itu jangan
 * memakai 500 untuk kesalahan input.
 */
class OcrException extends RuntimeException
{
    public function __construct(
        string $message,
        private readonly string $errorCode,
        private readonly int $httpStatus,
    ) {
        parent::__construct($message);
    }

    public function errorCode(): string
    {
        return $this->errorCode;
    }

    public function httpStatus(): int
    {
        return $this->httpStatus;
    }

    public static function invalidPayload(string $message): self
    {
        return new self($message, 'INVALID_PAYLOAD', 400);
    }

    public static function pathNotAllowed(string $message): self
    {
        return new self($message, 'PATH_NOT_ALLOWED', 400);
    }

    public static function fileNotFound(string $message): self
    {
        return new self($message, 'FILE_NOT_FOUND', 404);
    }

    public static function fileTooLarge(string $message): self
    {
        return new self($message, 'FILE_TOO_LARGE', 413);
    }

    public static function unsupportedMediaType(string $message): self
    {
        return new self($message, 'UNSUPPORTED_MEDIA_TYPE', 415);
    }

    public static function unreadableDocument(string $message): self
    {
        return new self($message, 'UNREADABLE_DOCUMENT', 422);
    }

    public static function engineFailure(string $message): self
    {
        return new self($message, 'ENGINE_FAILURE', 500);
    }

    public static function timeout(string $message): self
    {
        return new self($message, 'TIMEOUT', 504);
    }

    public static function unauthorized(string $message = 'Token tidak valid.'): self
    {
        return new self($message, 'UNAUTHORIZED', 401);
    }
}
