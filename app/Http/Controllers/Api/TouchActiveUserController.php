<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\TouchActiveUserRequest;
use App\Services\MongoSocialService;
use App\Services\RedisActivityService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;

class TouchActiveUserController extends Controller
{
    public function __invoke(
        TouchActiveUserRequest $request,
        MongoSocialService $mongoService,
        RedisActivityService $redisService,
    ): JsonResponse {
        $validated = $request->validated();
        $user = $mongoService->findUser((string) $validated['user_id']);

        if ($user === null) {
            return response()->json(
                ['message' => 'User not found.'],
                Response::HTTP_NOT_FOUND
            );
        }

        $activeUser = $redisService->touchActiveUser(
            userId: $user['id'],
            username: $user['username'],
            fullName: $user['full_name'],
            lastAction: (string) $validated['action'],
            ttlSeconds: (int) ($validated['ttl_seconds'] ?? 900),
        );

        return response()->json(
            [
                'active_user' => $activeUser,
                'active_users' => $redisService->getActiveUsers(),
            ],
            Response::HTTP_OK
        );
    }
}
