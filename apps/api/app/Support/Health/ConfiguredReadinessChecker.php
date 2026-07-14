<?php

namespace App\Support\Health;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;
use Throwable;

final class ConfiguredReadinessChecker implements ReadinessChecker
{
    public function check(array $requiredDependencies): ReadinessReport
    {
        $results = [];

        foreach ($requiredDependencies as $dependency) {
            $results[$dependency] = $this->checkDependency($dependency);
        }

        return new ReadinessReport($results);
    }

    private function checkDependency(string $dependency): DependencyStatus
    {
        try {
            return match ($dependency) {
                'postgres' => $this->checkPostgres(),
                'redis' => $this->checkRedis(),
                default => DependencyStatus::Unavailable,
            };
        } catch (Throwable $exception) {
            Log::warning('Readiness dependency unavailable', [
                'dependency' => $dependency,
                'exception_class' => $exception::class,
            ]);

            return DependencyStatus::Unavailable;
        }
    }

    private function checkPostgres(): DependencyStatus
    {
        DB::connection('pgsql')->getPdo();

        return DependencyStatus::Ok;
    }

    private function checkRedis(): DependencyStatus
    {
        Redis::connection()->ping();

        return DependencyStatus::Ok;
    }
}
