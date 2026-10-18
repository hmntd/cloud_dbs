<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreUserRequest;
use App\Services\MongoSocialService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;

class StoreUserController extends Controller
{
    public function __invoke(StoreUserRequest $request, MongoSocialService $mongoService): JsonResponse
    {
        $user = $mongoService->createUser($request->validated());

        return response()->json(
            ['user' => $user],
            Response::HTTP_CREATED
        );
    }
}
