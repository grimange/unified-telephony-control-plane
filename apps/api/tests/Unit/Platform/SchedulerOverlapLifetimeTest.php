<?php

namespace Tests\Unit\Platform;

use PHPUnit\Framework\TestCase;

final class SchedulerOverlapLifetimeTest extends TestCase
{
    public function test_minute_cadence_overlap_protection_has_a_bounded_expiry(): void
    {
        $routes = file_get_contents(dirname(__DIR__, 3).'/routes/console.php');
        $this->assertIsString($routes);

        preg_match_all('/everyMinute\(\)->withoutOverlapping\((\d+)\)/', $routes, $boundedMatches);
        preg_match_all('/everyMinute\(\)->withoutOverlapping\(\)/', $routes, $implicitMatches);

           $this->assertCount(15, $boundedMatches[1]);
        $this->assertSame([], $implicitMatches[0]);
           $this->assertSame(array_fill(0, 15, '5'), $boundedMatches[1]);
    }

    public function test_live_proven_minute_tasks_keep_explicit_five_minute_expiry(): void
    {
        $routes = file_get_contents(dirname(__DIR__, 3).'/routes/console.php');
        $this->assertIsString($routes);

        foreach ([
            'runtime-engine:k5c-placement-observer',
            'telephony-domain:expire-sessions',
            'telephony-domain:reclaim-orphan-participant-channels --once',
        ] as $command) {
            $this->assertStringContainsString(
                "{$command}')->everyMinute()->withoutOverlapping(5)",
                $routes,
            );
        }
    }
}
