<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StorePostRequest;
use App\Services\MongoSocialService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;

class StorePostController extends Controller
{
    public function __invoke(StorePostRequest $request, MongoSocialService $mongoService): JsonResponse
    {
        $post = $mongoService->createPost($request->validated());

        return response()->json(
            ['post' => $post],
            Response::HTTP_CREATED
        );
    }
}
