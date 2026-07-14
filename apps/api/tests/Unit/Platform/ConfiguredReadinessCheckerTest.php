<?php

namespace Tests\Unit\Platform;

use App\Support\Health\ConfiguredReadinessChecker;
use App\Support\Health\DependencyStatus;
use Tests\TestCase;

final class ConfiguredReadinessCheckerTest extends TestCase
{
    public function test_unknown_required_dependency_is_unavailable(): void
    {
        $report = (new ConfiguredReadinessChecker)->check(['unknown']);

        self::assertFalse($report->ready());
        self::assertSame([
            'unknown' => DependencyStatus::Unavailable,
        ], $report->dependencies);
    }
}
