<?php

namespace App\Http\Controllers\Platform;

use Illuminate\Http\JsonResponse;

final class LivenessController
{
    public function __invoke(): JsonResponse
    {
        return response()->json([
            'status' => 'ok',
            'service' => (string) config('utcp.service.name', 'utcp-api'),
        ]);
    }
}
