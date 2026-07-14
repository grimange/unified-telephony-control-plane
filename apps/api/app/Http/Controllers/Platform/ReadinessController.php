<?php

namespace App\Http\Controllers\Platform;

use App\Support\Health\ReadinessChecker;
use Illuminate\Http\JsonResponse;

final readonly class ReadinessController
{
    public function __construct(
        private ReadinessChecker $readiness,
    ) {}

    public function __invoke(): JsonResponse
    {
        $report = $this->readiness->check(config('utcp.readiness.required_dependencies', []));

        return response()->json([
            'status' => $report->ready() ? 'ready' : 'not_ready',
            'service' => (string) config('utcp.service.name', 'utcp-api'),
            'dependencies' => $report->dependencyPayload(),
        ], $report->ready() ? 200 : 503);
    }
}
