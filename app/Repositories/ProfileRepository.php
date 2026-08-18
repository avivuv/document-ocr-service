<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Contracts\Repositories\ProfileRepositoryInterface;

final class ProfileRepository implements ProfileRepositoryInterface
{
    public function forDocType(?string $docType): array
    {
        $default = (array) config('ocr.default_profile');
        $profile = $docType === null
            ? []
            : (array) config('ocr.profiles.'.mb_strtoupper($docType), []);

        $merged = $profile + $default;

        return [
            'psm'        => (int) $merged['psm'],
            'dpi'        => (int) $merged['dpi'],
            'preprocess' => (string) $merged['preprocess'],
            'lang'       => (string) $merged['lang'],
        ];
    }

    public function preprocessArgs(string $profileName): array
    {
        $args = config('ocr.preprocess_profiles.'.$profileName);

        return is_array($args) ? array_values(array_map('strval', $args)) : [];
    }
}
