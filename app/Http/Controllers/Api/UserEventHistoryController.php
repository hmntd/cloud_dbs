<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\UserEventHistoryRequest;
use App\Services\CassandraEventService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;

class UserEventHistoryController extends Controller
{
    public function __invoke(UserEventHistoryRequest $request, CassandraEventService $cassandraService): JsonResponse
    {
        $validated = $request->validated();
        $userId = isset($validated['user_id']) ? trim((string) $validated['user_id']) : '';
        $limit = (int) ($validated['limit'] ?? 20);

        if ($userId !== '') {
            $events = $cassandraService->getUserHistory(
                userId: $userId,
                dateFrom: $validated['date_from'] ?? null,
                dateTo: $validated['date_to'] ?? null,
                limit: $limit,
            );
        } else {
            $events = $cassandraService->getRecentEvents($limit);
        }

        return response()->json(
            ['events' => $events],
            Response::HTTP_OK
        );
    }
}
