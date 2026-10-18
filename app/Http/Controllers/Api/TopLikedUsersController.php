<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\MongoSocialService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;

class TopLikedUsersController extends Controller
{
    public function __invoke(MongoSocialService $mongoService): JsonResponse
    {
        return response()->json(
            ['most_liked_users' => $mongoService->getTopUsersByLikes(10)],
            Response::HTTP_OK
        );
    }
}
