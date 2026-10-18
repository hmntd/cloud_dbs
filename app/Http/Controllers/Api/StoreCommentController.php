<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreCommentRequest;
use App\Services\MongoSocialService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;

class StoreCommentController extends Controller
{
    public function __invoke(string $postId, StoreCommentRequest $request, MongoSocialService $mongoService): JsonResponse
    {
        $validated = $request->validated();

        $post = $mongoService->addComment(
            postId: $postId,
            userId: (string) $validated['user_id'],
            content: (string) $validated['content'],
        );

        return response()->json(
            ['post' => $post],
            Response::HTTP_CREATED
        );
    }
}
