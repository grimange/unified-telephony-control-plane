<?php

namespace App\Http\Controllers\Platform;

use App\Support\Build\BuildInfo;
use Illuminate\Http\JsonResponse;

final readonly class VersionController
{
    public function __construct(
        private BuildInfo $build,
    ) {}

    public function __invoke(): JsonResponse
    {
        return response()->json($this->build->toArray());
    }
}
