<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\MutualFriendsRequest;
use App\Services\Neo4jGraphService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;

class MutualFriendsController extends Controller
{
    public function __invoke(MutualFriendsRequest $request, Neo4jGraphService $neo4jService): JsonResponse
    {
        $validated = $request->validated();

        $mutualFriends = $neo4jService->getMutualFriends(
            userAId: (string) $validated['user_a_id'],
            userBId: (string) $validated['user_b_id'],
        );

        return response()->json(
            ['mutual_friends' => $mutualFriends],
            Response::HTTP_OK
        );
    }
}
