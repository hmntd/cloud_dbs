<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\SocialOverviewService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;

class SeedDemoDataController extends Controller
{
    public function __invoke(SocialOverviewService $overviewService): JsonResponse
    {
        return response()->json(
            $overviewService->reseed(),
            Response::HTTP_CREATED
        );
    }
}
