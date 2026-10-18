<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\SearchPostsRequest;
use App\Services\MongoSocialService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;

class SearchPostsController extends Controller
{
    public function __invoke(SearchPostsRequest $request, MongoSocialService $mongoService): JsonResponse
    {
        $validated = $request->validated();

        $posts = $mongoService->searchPosts(
            authorId: $validated['author_id'] ?? null,
            dateFrom: $validated['date_from'] ?? null,
            dateTo: $validated['date_to'] ?? null,
            tag: $validated['tag'] ?? null,
        );

        return response()->json(
            ['posts' => $posts],
            Response::HTTP_OK
        );
    }
}
