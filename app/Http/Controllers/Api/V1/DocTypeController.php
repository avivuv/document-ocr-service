<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Contracts\DocumentParserInterface;
use App\Contracts\Repositories\ParserRepositoryInterface;
use App\Contracts\Repositories\ProfileRepositoryInterface;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;

/**
 * Consumer membaca kemampuan service dari sini, jadi daftarnya dibangun dari
 * registry parser — bukan ditulis ulang di controller (RULES §10 langkah 6).
 */
final class DocTypeController extends Controller
{
    public function __invoke(
        ParserRepositoryInterface $parsers,
        ProfileRepositoryInterface $profiles,
    ): JsonResponse {
        $docTypes = array_map(function (DocumentParserInterface $parser) use ($profiles): array {
            $profile = $profiles->forDocType($parser->docType());

            return [
                'code'    => $parser->docType(),
                'fields'  => $parser->fieldNames(),
                'profile' => [
                    'psm'  => $profile['psm'],
                    'dpi'  => $profile['dpi'],
                    'lang' => $profile['lang'],
                ],
            ];
        }, $parsers->all());

        return response()->json(['doc_types' => array_values($docTypes)]);
    }
}
