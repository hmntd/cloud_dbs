<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\RecommendationsRequest;
use App\Services\Neo4jGraphService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;

class SubscriptionRecommendationsController extends Controller
{
    public function __invoke(RecommendationsRequest $request, Neo4jGraphService $neo4jService): JsonResponse
    {
        $validated = $request->validated();

        $recommendations = $neo4jService->getSubscriptionRecommendations(
            userId: (string) $validated['user_id'],
            limit: 10,
        );

        return response()->json(
            ['recommendations' => $recommendations],
            Response::HTTP_OK
        );
    }
}
