<?php

namespace Tests\Feature\Platform;

use Tests\TestCase;

final class ApiRoutingTest extends TestCase
{
    public function test_unknown_api_routes_retain_normal_laravel_behavior(): void
    {
        $this->getJson('/api/not-found')->assertNotFound();
    }
}
