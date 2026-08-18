<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\AnalyzeRequest;
use App\Http\Resources\AnalyzeResource;
use App\Services\AnalyzeService;
use Illuminate\Http\JsonResponse;

final class AnalyzeController extends Controller
{
    public function __invoke(AnalyzeRequest $request, AnalyzeService $analyze): JsonResponse
    {
        $result = $analyze->analyze($request->toData());

        return AnalyzeResource::make($result)
            ->response()
            ->header('X-Request-Id', $result->requestId);
    }
}
