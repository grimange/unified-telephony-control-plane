<?php

namespace Tests\Feature\Platform;

use Tests\TestCase;

final class VersionEndpointTest extends TestCase
{
    public function test_version_endpoint_returns_documented_schema(): void
    {
        $this->getJson('/api/version')
            ->assertOk()
            ->assertJsonStructure([
                'service',
                'version',
                'commit',
                'built_at',
            ])
            ->assertJsonPath('service', 'utcp-api')
            ->assertJsonPath('version', '0.1.0-dev')
            ->assertJsonPath('commit', 'unknown')
            ->assertJsonPath('built_at', 'unknown');
    }

    public function test_version_endpoint_uses_configured_build_metadata(): void
    {
        config()->set('utcp.build.version', '0.1.0-test');
        config()->set('utcp.build.commit', 'abc1234');
        config()->set('utcp.build.built_at', '2026-07-13T09:00:00Z');

        $this->getJson('/api/version')
            ->assertOk()
            ->assertExactJson([
                'service' => 'utcp-api',
                'version' => '0.1.0-test',
                'commit' => 'abc1234',
                'built_at' => '2026-07-13T09:00:00Z',
            ]);
    }
}
