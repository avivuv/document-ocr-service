<?php

declare(strict_types=1);

namespace App\Services;

use App\Contracts\Repositories\WorkspaceRepositoryInterface;

/**
 * Siklus hidup direktori kerja per request.
 *
 * destroy() sengaja menerima null dan aman dipanggil berkali-kali, karena
 * pemanggilnya berada di blok finally yang bisa dijalankan sebelum direktori
 * sempat dibuat.
 */
final class WorkspaceService
{
    public function __construct(private readonly WorkspaceRepositoryInterface $workspaces)
    {
    }

    public function create(string $requestId): string
    {
        $this->workspaces->purgeStale((int) config('ocr.workspace_ttl_hours', 24));

        return $this->workspaces->create($this->slug($requestId).'-'.bin2hex(random_bytes(6)));
    }

    public function destroy(?string $directory): void
    {
        if ($directory !== null && $directory !== '') {
            $this->workspaces->destroy($directory);
        }
    }

    /** request_id berasal dari consumer — tidak boleh dipakai membentuk path apa adanya. */
    private function slug(string $requestId): string
    {
        $slug = preg_replace('/[^A-Za-z0-9_-]+/', '-', $requestId) ?? '';
        $slug = trim(mb_substr($slug, 0, 40), '-');

        return $slug === '' ? 'req' : $slug;
    }
}
