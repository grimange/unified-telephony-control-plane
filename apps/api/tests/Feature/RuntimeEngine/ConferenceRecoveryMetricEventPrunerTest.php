<?php

namespace Tests\Feature\RuntimeEngine;

use App\RuntimeEngine\ConferenceRecoveryMetricEventPruner;
use App\RuntimeEngine\EngineIds;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class ConferenceRecoveryMetricEventPrunerTest extends TestCase
{
    use RefreshDatabase;

    public function test_pruner_deletes_only_rows_older_than_configured_retention_cutoff(): void
    {
        $now = Carbon::parse('2026-07-21 12:00:00');
        $this->travelTo($now);

        $eligible = $this->insertMetricEvent('eligible-old', $now->copy()->subDays(7)->subSecond());
        $exactCutoff = $this->insertMetricEvent('exact-cutoff', $now->copy()->subDays(7));
        $recent = $this->insertMetricEvent('recent', $now->copy()->subMinutes(5));

        $result = app(ConferenceRecoveryMetricEventPruner::class)->pruneExpired(7, 1000, 10);

        $this->assertSame('succeeded', $result['result']);
        $this->assertSame(1, $result['rows_deleted']);
        $this->assertDatabaseMissing('conference_recovery_metric_events', ['id' => $eligible]);
        $this->assertDatabaseHas('conference_recovery_metric_events', ['id' => $exactCutoff]);
        $this->assertDatabaseHas('conference_recovery_metric_events', ['id' => $recent]);

        $threeDayEligible = $this->insertMetricEvent('three-day-eligible', $now->copy()->subDays(3)->subSecond());

        $result = app(ConferenceRecoveryMetricEventPruner::class)->pruneExpired(3, 1000, 10);

        $this->assertSame(2, $result['rows_deleted']);
        $this->assertDatabaseMissing('conference_recovery_metric_events', ['id' => $threeDayEligible]);
        $this->assertDatabaseMissing('conference_recovery_metric_events', ['id' => $exactCutoff]);
        $this->assertDatabaseHas('conference_recovery_metric_events', ['id' => $recent]);
    }

    public function test_pruner_honors_batch_and_run_caps_and_continues_backlog(): void
    {
        $now = Carbon::parse('2026-07-21 12:00:00');
        $this->travelTo($now);

        $eligibleByAge = [];
        foreach (range(8, 1) as $daysAgo) {
            $eligibleByAge[$daysAgo] = $this->insertMetricEvent('eligible-'.$daysAgo, $now->copy()->subDays($daysAgo)->subSecond());
        }
        $recent = $this->insertMetricEvent('recent', $now->copy()->subMinutes(1));

        $first = app(ConferenceRecoveryMetricEventPruner::class)->pruneExpired(1, 3, 2);

        $this->assertSame('backlog_remaining', $first['result']);
        $this->assertSame(6, $first['rows_deleted']);
        $this->assertSame(2, $first['batches_completed']);
        $this->assertTrue($first['eligible_backlog_remaining']);
        foreach ([8, 7, 6, 5, 4, 3] as $daysAgo) {
            $this->assertDatabaseMissing('conference_recovery_metric_events', ['id' => $eligibleByAge[$daysAgo]]);
        }
        foreach ([2, 1] as $daysAgo) {
            $this->assertDatabaseHas('conference_recovery_metric_events', ['id' => $eligibleByAge[$daysAgo]]);
        }
        $this->assertDatabaseHas('conference_recovery_metric_events', ['id' => $recent]);

        $second = app(ConferenceRecoveryMetricEventPruner::class)->pruneExpired(1, 3, 2);

        $this->assertSame('succeeded', $second['result']);
        $this->assertSame(2, $second['rows_deleted']);
        $this->assertFalse($second['eligible_backlog_remaining']);
        $this->assertDatabaseHas('conference_recovery_metric_events', ['id' => $recent]);

        $third = app(ConferenceRecoveryMetricEventPruner::class)->pruneExpired(1, 3, 2);

        $this->assertSame('noop', $third['result']);
        $this->assertSame(0, $third['rows_deleted']);
    }

    public function test_pruner_preserves_runtime_authority_tables(): void
    {
        $now = Carbon::parse('2026-07-21 12:00:00');
        $this->travelTo($now);

        DB::table('runtime_operations')->insert([
            'id' => EngineIds::new(),
            'tenant_id' => null,
            'operation_type' => 'conference.close',
            'aggregate_type' => 'conference',
            'aggregate_id' => EngineIds::new(),
            'runtime_node_id' => null,
            'payload_version' => 1,
            'payload' => json_encode([], JSON_THROW_ON_ERROR),
            'status' => 'succeeded',
            'priority' => 100,
            'idempotency_key' => EngineIds::new(),
            'correlation_id' => EngineIds::new(),
            'causation_id' => null,
            'request_id' => EngineIds::new(),
            'attempt_count' => 1,
            'max_attempts' => 3,
            'available_at' => $now,
            'expires_at' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        DB::table('control_plane_outbox_messages')->insert([
            'id' => EngineIds::new(),
            'tenant_id' => null,
            'aggregate_type' => 'conference',
            'aggregate_id' => EngineIds::new(),
            'event_type' => 'conference.closed',
            'event_version' => 1,
            'payload' => json_encode([], JSON_THROW_ON_ERROR),
            'correlation_id' => EngineIds::new(),
            'causation_id' => null,
            'request_id' => EngineIds::new(),
            'occurred_at' => $now,
            'available_at' => $now,
            'attempt_count' => 0,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $metricEvent = $this->insertMetricEvent('eligible', $now->copy()->subDays(8));

        $before = [
            'runtime_operations' => DB::table('runtime_operations')->count(),
            'control_plane_outbox_messages' => DB::table('control_plane_outbox_messages')->count(),
        ];

        app(ConferenceRecoveryMetricEventPruner::class)->pruneExpired(7, 1000, 10);

        $this->assertDatabaseMissing('conference_recovery_metric_events', ['id' => $metricEvent]);
        foreach ($before as $table => $count) {
            $this->assertSame($count, DB::table($table)->count(), $table.' changed during diagnostic pruning.');
        }
    }

    public function test_prune_command_and_scheduler_are_bounded_and_automatic(): void
    {
        $now = Carbon::parse('2026-07-21 12:00:00');
        $this->travelTo($now);

        $metricEvent = $this->insertMetricEvent('eligible', $now->copy()->subDays(8));

        $this->artisan('runtime-engine:prune-conference-recovery-metric-events --once')
            ->expectsOutput('conference_recovery_metric_event_prune_status=succeeded')
            ->expectsOutput('rows_deleted=1')
            ->expectsOutput('batches_completed=1')
            ->assertExitCode(0);

        $this->assertDatabaseMissing('conference_recovery_metric_events', ['id' => $metricEvent]);

        Config::set('runtime_engine.conference_recovery_metric_event_retention_days', 0);

        $this->artisan('runtime-engine:prune-conference-recovery-metric-events --once')
            ->expectsOutput('conference_recovery_metric_event_prune_status=invalid_configuration')
            ->assertExitCode(1);

        $routes = (string) file_get_contents(base_path('routes/console.php'));
        $commandStart = strpos($routes, 'runtime-engine:prune-conference-recovery-metric-events {--once');
        $this->assertNotFalse($commandStart);
        $commandEnd = strpos($routes, ")->purpose('Prune expired conference recovery metric events with bounded retention.')", $commandStart);
        $this->assertNotFalse($commandEnd);
        $command = substr($routes, (int) $commandStart, (int) $commandEnd - (int) $commandStart);

        $this->assertStringContainsString('runtime-engine:prune-conference-recovery-metric-events {--once', $routes);
        $this->assertStringContainsString("runtime-engine:prune-conference-recovery-metric-events --once')->hourly()->withoutOverlapping()", $routes);
        $this->assertStringNotContainsString('{--tenant', $command);
        $this->assertStringNotContainsString('{--retention', $command);
        $this->assertStringNotContainsString('{--dry-run', $command);
    }

    private function insertMetricEvent(string $suffix, Carbon $createdAt): string
    {
        $id = EngineIds::new();

        DB::table('conference_recovery_metric_events')->insert([
            'id' => $id,
            'adapter_key' => 'asterisk-ari',
            'resource_type' => 'conference',
            'result' => 'observed',
            'failure_class' => 'none',
            'reason' => 'healthy_present',
            'created_at' => $createdAt,
            'updated_at' => $createdAt,
        ]);

        return $id;
    }
}
