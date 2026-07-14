<?php

namespace Tests\Feature\Platform;

use App\Support\Health\DependencyStatus;
use App\Support\Health\ReadinessChecker;
use App\Support\Health\ReadinessReport;
use Tests\TestCase;

final class HealthEndpointTest extends TestCase
{
    public function test_liveness_returns_http_200(): void
    {
        $this->getJson('/api/health/live')
            ->assertOk()
            ->assertExactJson([
                'status' => 'ok',
                'service' => 'utcp-api',
            ]);
    }

    public function test_liveness_does_not_invoke_dependency_checks(): void
    {
        $this->app->bind(ReadinessChecker::class, fn () => new class implements ReadinessChecker
        {
            public function check(array $requiredDependencies): ReadinessReport
            {
                throw new \RuntimeException('liveness must not call readiness checks');
            }
        });

        $this->getJson('/api/health/live')->assertOk();
    }

    public function test_readiness_returns_http_200_when_required_checks_pass(): void
    {
        config()->set('utcp.readiness.required_dependencies', ['postgres', 'redis']);
        $this->app->instance(ReadinessChecker::class, new class implements ReadinessChecker
        {
            public function check(array $requiredDependencies): ReadinessReport
            {
                return new ReadinessReport([
                    'postgres' => DependencyStatus::Ok,
                    'redis' => DependencyStatus::Ok,
                ]);
            }
        });

        $this->getJson('/api/health/ready')
            ->assertOk()
            ->assertExactJson([
                'status' => 'ready',
                'service' => 'utcp-api',
                'dependencies' => [
                    'postgres' => 'ok',
                    'redis' => 'ok',
                ],
            ]);
    }

    public function test_readiness_returns_http_503_when_a_required_check_fails(): void
    {
        config()->set('utcp.readiness.required_dependencies', ['postgres', 'redis']);
        $this->app->instance(ReadinessChecker::class, new class implements ReadinessChecker
        {
            public function check(array $requiredDependencies): ReadinessReport
            {
                return new ReadinessReport([
                    'postgres' => DependencyStatus::Unavailable,
                    'redis' => DependencyStatus::Ok,
                ]);
            }
        });

        $this->getJson('/api/health/ready')
            ->assertStatus(503)
            ->assertExactJson([
                'status' => 'not_ready',
                'service' => 'utcp-api',
                'dependencies' => [
                    'postgres' => 'unavailable',
                    'redis' => 'ok',
                ],
            ]);
    }

    public function test_readiness_response_does_not_expose_raw_exception_messages(): void
    {
        config()->set('utcp.readiness.required_dependencies', ['postgres']);
        $this->app->instance(ReadinessChecker::class, new class implements ReadinessChecker
        {
            public function check(array $requiredDependencies): ReadinessReport
            {
                return new ReadinessReport([
                    'postgres' => DependencyStatus::Unavailable,
                ]);
            }
        });

        $response = $this->getJson('/api/health/ready')
            ->assertStatus(503)
            ->assertJsonPath('dependencies.postgres', 'unavailable');

        $response->assertDontSee('SQLSTATE', false)
            ->assertDontSee('password', false)
            ->assertDontSee('connection refused', false);
    }
}
