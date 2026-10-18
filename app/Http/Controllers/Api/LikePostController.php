<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\PostInteractionRequest;
use App\Services\MongoSocialService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;

class LikePostController extends Controller
{
    public function __invoke(string $postId, PostInteractionRequest $request, MongoSocialService $mongoService): JsonResponse
    {
        $validated = $request->validated();

        $post = $mongoService->likePost(
            postId: $postId,
            userId: (string) $validated['user_id'],
        );

        return response()->json(
            ['post' => $post],
            Response::HTTP_OK
        );
    }
}
