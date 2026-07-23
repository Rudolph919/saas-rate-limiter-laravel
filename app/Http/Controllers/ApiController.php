<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;

class ApiController extends Controller
{
    public function health(): JsonResponse
    {
        return response()->json([
            'status' => 'ok',
            'service' => 'saas-rate-limiter',
        ]);
    }

    public function indexItems(): JsonResponse
    {
        return response()->json([
            'data' => [
                ['id' => 1, 'name' => 'Widget'],
                ['id' => 2, 'name' => 'Gadget'],
            ],
        ]);
    }

    public function storeItem(): JsonResponse
    {
        return response()->json([
            'id' => 3,
            'name' => 'New item',
        ], 201);
    }

    public function destroyItem(string $id): JsonResponse
    {
        return response()->json([
            'deleted' => $id,
        ]);
    }
}
