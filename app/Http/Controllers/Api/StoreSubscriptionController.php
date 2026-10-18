<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreSubscriptionRequest;
use App\Services\MongoSocialService;
use App\Services\Neo4jGraphService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;

class StoreSubscriptionController extends Controller
{
    public function __invoke(
        StoreSubscriptionRequest $request,
        MongoSocialService $mongoService,
        Neo4jGraphService $neo4jService,
    ): JsonResponse {
        $validated = $request->validated();

        $subscription = $mongoService->subscribeUser(
            followerId: (string) $validated['follower_id'],
            followeeId: (string) $validated['followee_id'],
        );

        return response()->json(
            [
                'subscription' => $subscription,
                'subscriptions' => $neo4jService->getSubscriptions(),
            ],
            Response::HTTP_CREATED
        );
    }
}
