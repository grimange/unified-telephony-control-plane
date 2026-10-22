<?php

declare(strict_types=1);

// Independent test-only PHP oracle: all decisions come from unchanged Laravel services.
use App\ControlPlane\InfrastructureAuthority\CanonicalInfrastructureService;
use App\ControlPlane\Shared\ExecutionContext;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

require '/var/www/html/vendor/autoload.php';
$app = require '/var/www/html/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
if (getenv('APP_ENV') !== 'testing' || getenv('DB_DATABASE') !== 'utcp_canonical_proof'
    || ! str_starts_with((string) getenv('UTCP_CP1_RUN'), 'utcp-go-cp1-e2-')) {
    throw new RuntimeException('Disposable GO-CP1 E2 database required.');
}
require '/prior-proof/Fixture.php';
$service = new CanonicalInfrastructureService;

// The production claimant intentionally scans the global queue. Keep every
// prior negative fixture unchanged while SKIP LOCKED isolates the next exact
// operation on a separate PostgreSQL connection.
function isolatedAction(array $r, string $version = 'utcp.infrastructure.v3', string $kind = 'converge_projection'): array
{
    config(['database.connections.e2_claim_isolation' => config('database.connections.pgsql')]);
    $isolation = DB::connection('e2_claim_isolation');
    $isolation->beginTransaction();
    try {
        $isolation->table('runtime_operations')->lockForUpdate()->get(['id']);

        return CanonicalPgFixture::action($r, $version, $kind);
    } finally {
        $isolation->rollBack();
    }
}

function domainFixture(string $kind): array
{
    global $service;
    [$tenant, , $r] = CanonicalPgFixture::prepared();
    $context = ExecutionContext::system(tenantId: $tenant);
    $r = $service->bind($context, $r['id'], 'synthetic-e2-uid-'.Str::lower(Str::random(12)));
    if ($kind === 'fence') {
        $a = isolatedAction($r, 'utcp.infrastructure.v1', 'set_replicas');
        $payload = json_decode(DB::table('runtime_operations')->where('id', $a['operation_id'])->value('payload'), true, 512, JSON_THROW_ON_ERROR);
        $replacement = DB::table('runtime_nodes')->where('tenant_id', $tenant)->where('id', '<>', $r['runtime_node_id'])->value('id');
        $metadata = ['conference' => $payload['conference_id'], 'binding' => $payload['former_runtime_binding_id'], 'replacement' => $replacement];
    } else {
        $a = isolatedAction($r);
        $source = str_replace('-', '', (string) Str::uuid());
        $sourceRow = (array) DB::table('runtime_operations')->where('id', $a['operation_id'])->first();
        $sourcePayload = ['configuration_generation' => 7, 'runtime_fence_provenance' => [
            'runtime_node_disabled' => ['by_operation' => true, 'operation_id' => $source, 'runtime_node_id' => $r['runtime_node_id']],
            'scale_to_zero_requested' => ['operation_id' => $source, 'namespace' => $r['namespace'], 'deployment' => $r['physical_name'], 'pre_scale_replicas' => 1],
        ]];
        $sourceRow['id'] = $source;
        $sourceRow['operation_type'] = 'runtime.node.runtime.fence';
        $sourceRow['status'] = 'succeeded';
        $sourceRow['completed_at'] = now();
        $sourceRow['payload'] = json_encode($sourcePayload, JSON_THROW_ON_ERROR);
        $sourceRow['idempotency_key'] = null;
        DB::table('runtime_operations')->insert($sourceRow);
        $payload = ['tenant_id' => $tenant, 'runtime_node_id' => $r['runtime_node_id'], 'requested_desired_state' => 'active',
            'source_fence_operation_id' => $source, 'source_fence_generation' => 7, 'workload_namespace' => $r['namespace'],
            'deployment' => $r['physical_name'], 'target_replicas' => 1, 'expected_runtime_node_configuration_version' => 1];
        DB::table('runtime_operations')->where('id', $a['operation_id'])->update(['operation_type' => 'runtime.node.restore', 'payload' => json_encode($payload, JSON_THROW_ON_ERROR)]);
        DB::table('runtime_nodes')->where('id', $r['runtime_node_id'])->update(['desired_state' => 'disabled']);
        $metadata = ['source' => $source];
    }
    $claimed = DB::table('runtime_operations')->where('id', $a['operation_id'])->first();
    if ($claimed->lease_token !== $a['claim']['fencing_token'] || (int) $claimed->attempt_count !== $a['claim']['attempt']) {
        throw new RuntimeException('Fixture claimed a different operation.');
    }
    $service->persistIntent($context, $r['id'], $a);

    return [$r, $a, $metadata];
}

function payloadChange(string $operation, callable $mutate): void
{
    $p = json_decode(DB::table('runtime_operations')->where('id', $operation)->value('payload'), true, 512, JSON_THROW_ON_ERROR);
    $mutate($p);
    DB::table('runtime_operations')->where('id', $operation)->update(['payload' => json_encode($p, JSON_THROW_ON_ERROR)]);
}

$result = ['claims' => []];
$add = function (string $kind, string $name, ?callable $mutate = null, bool $expected = false) use (&$result, $service): void {
    [$r, $a, $m] = domainFixture($kind);
    if ($mutate !== null) {
        $mutate($r, $a, $m);
    }
    $decision = $service->verify($a);
    if ($decision['authorized'] !== $expected) {
        throw new RuntimeException('Unexpected Laravel decision: '.$kind.'.'.$name);
    }
    $result['claims'][] = ['name' => $kind.'.'.$name, 'kind' => $kind, 'action' => $a, 'authorized' => $decision['authorized'], 'metadata' => $m];
};
$add('fence', 'positive-unobserved-unlimited', expected: true);
$add('fence', 'positive-unavailable', function ($r): void {
    DB::table('runtime_nodes')->where('id', $r['runtime_node_id'])->update(['observed_state' => 'unavailable']);
}, true);
$add('fence', 'positive-no-placement-observation', expected: true);
$add('restore', 'positive-source-provenance', expected: true);
foreach (['fence', 'restore'] as $kind) {
    $add($kind, 'wrong-tenant', function ($r, &$a): void {
        $a['tenant_id'] = (string) Str::uuid();
    });
    $add($kind, 'wrong-node', function ($r, &$a): void {
        $a['runtime_node_id'] = (string) Str::uuid();
    });
    $add($kind, 'wrong-uid', function ($r, &$a): void {
        $a['target']['expected_uid'] = 'wrong-uid';
    });
    $add($kind, 'wrong-incarnation', function ($r, &$a): void {
        $a['target']['incarnation_id'] = (string) Str::uuid();
    });
    $add($kind, 'wrong-generation', function ($r, &$a): void {
        $a['target_generation'] = '9007199254740993';
    });
    $add($kind, 'wrong-fingerprint', function ($r, &$a): void {
        $a['intent_fingerprint'] = str_repeat('0', 64);
    });
    $add($kind, 'wrong-attempt', function ($r, &$a): void {
        $a['claim']['attempt']++;
    });
    $add($kind, 'wrong-token', function ($r, &$a): void {
        $a['claim']['fencing_token'] = str_repeat('f', 32);
    });
    $add($kind, 'lease-expired', function ($r, $a): void {
        DB::table('runtime_operations')->where('id', $a['operation_id'])->update(['lease_expires_at' => now()->subSecond()]);
    });
    $add($kind, 'attempt-rollover', function ($r, $a): void {
        DB::table('runtime_operations')->where('id', $a['operation_id'])->increment('attempt_count');
    });
    $add($kind, 'cancelled-operation', function ($r, $a): void {
        DB::table('runtime_operations')->where('id', $a['operation_id'])->update(['status' => 'cancelled']);
    });
    $add($kind, 'superseded-intent', function ($r): void {
        DB::table('infrastructure_intents')->where('incarnation_id', $r['id'])->update(['superseded' => true]);
    });
    $add($kind, 'retired-incarnation', function ($r): void {
        DB::table('infrastructure_incarnations')->where('id', $r['id'])->update(['state' => 'retiring']);
        DB::table('infrastructure_incarnations')->where('id', $r['id'])->update(['state' => 'retired']);
    });
    $add($kind, 'unsupported-freeswitch', function ($r): void {
        DB::table('runtime_nodes')->where('id', $r['runtime_node_id'])->update(['runtime_family' => 'freeswitch']);
    });
}
$add('fence', 'target-ready', function ($r): void {
    DB::table('runtime_nodes')->where('id', $r['runtime_node_id'])->update(['observed_state' => 'ready']);
});
$add('fence', 'missing-payload', function ($r, $a): void {
    payloadChange($a['operation_id'], function (&$p): void {
        unset($p['conference_id']);
    });
});
$add('fence', 'wrong-former-node', function ($r, $a): void {
    payloadChange($a['operation_id'], function (&$p): void {
        $p['former_runtime_node_id'] = (string) Str::uuid();
    });
});
$add('fence', 'conference-closed', function ($r, $a, $m): void {
    DB::table('conferences')->where('id', $m['conference'])->update(['desired_state' => 'closed']);
});
$add('fence', 'conference-generation', function ($r, $a, $m): void {
    DB::table('conferences')->where('id', $m['conference'])->increment('configuration_generation');
});
$add('fence', 'binding-not-active', function ($r, $a, $m): void {
    DB::table('conference_runtime_bindings')->where('id', $m['binding'])->update(['status' => 'retired']);
});
$add('fence', 'replacement-disabled', function ($r, $a, $m): void {
    DB::table('runtime_nodes')->where('id', $m['replacement'])->update(['desired_state' => 'disabled']);
});
$add('fence', 'replacement-unavailable', function ($r, $a, $m): void {
    DB::table('runtime_nodes')->where('id', $m['replacement'])->update(['observed_state' => 'unavailable']);
});
foreach (['conference.lifecycle', 'conference.participation'] as $capability) {
    $add('fence', 'missing-'.$capability, function ($r, $a, $m) use ($capability): void {
        DB::table('runtime_node_capabilities')->where('runtime_node_id', $m['replacement'])->where('capability_key', $capability)->delete();
    });
}
$add('fence', 'placement-observation-required', function ($r, $a, $m): void {
    DB::table('runtime_nodes')->where('id', $m['replacement'])->update(['placement_region' => 'proof-region']);
});
foreach (['desired-state', 'configuration-version', 'source-failed', 'source-incomplete', 'source-wrong-generation', 'source-wrong-target', 'source-not-disabled-by-operation', 'requested-replicas-mismatch'] as $mutation) {
    $add('restore', $mutation, function ($r, $a, $m) use ($mutation): void {
        match ($mutation) {
            'desired-state' => DB::table('runtime_nodes')->where('id', $r['runtime_node_id'])->update(['desired_state' => 'active']),
            'configuration-version' => DB::table('runtime_nodes')->where('id', $r['runtime_node_id'])->increment('configuration_version'),
            'source-failed' => DB::table('runtime_operations')->where('id', $m['source'])->update(['status' => 'failed']),
            'source-incomplete' => DB::table('runtime_operations')->where('id', $m['source'])->update(['completed_at' => null]),
            'source-wrong-generation' => payloadChange($a['operation_id'], function (&$p): void {
                $p['source_fence_generation'] = 8;
            }),
            'source-wrong-target' => payloadChange($m['source'], function (&$p): void {
                $p['runtime_fence_provenance']['scale_to_zero_requested']['deployment'] = 'wrong-target';
            }),
            'source-not-disabled-by-operation' => payloadChange($m['source'], function (&$p): void {
                $p['runtime_fence_provenance']['runtime_node_disabled']['by_operation'] = false;
            }),
            'requested-replicas-mismatch' => payloadChange($a['operation_id'], function (&$p): void {
                $p['target_replicas'] = 2;
            }),
        };
    });
}
// Independent source-provenance negatives, including strict JSON scalar behavior.
foreach (['missing-source', 'wrong-source-type', 'wrong-source-node', 'wrong-source-tenant', 'missing-provenance', 'disabled-operation', 'disabled-node', 'scale-operation', 'scale-namespace', 'scale-pre-replicas-float', 'requested-replicas-float', 'source-array', 'request-array', 'source-object', 'request-object'] as $mutation) {
    $add('restore', $mutation, function ($r, $a, $m) use ($mutation): void {
        if ($mutation === 'missing-source') {
            payloadChange($a['operation_id'], function (&$p): void {
                $p['source_fence_operation_id'] = (string) Str::uuid();
            });
        } elseif ($mutation === 'wrong-source-type') {
            DB::table('runtime_operations')->where('id', $m['source'])->update(['operation_type' => 'runtime.node.restore']);
        } elseif ($mutation === 'wrong-source-node' || $mutation === 'wrong-source-tenant') {
            [$otherTenant, $otherNode] = CanonicalPgFixture::prepared();
            DB::table('runtime_operations')->where('id', $m['source'])->update($mutation === 'wrong-source-node' ? ['runtime_node_id' => $otherNode] : ['tenant_id' => $otherTenant]);
        } elseif ($mutation === 'requested-replicas-float') {
            payloadChange($a['operation_id'], function (&$p): void {
                $p['target_replicas'] = 1.5;
            });
        } elseif (str_starts_with($mutation, 'source-')) {
            payloadChange($m['source'], function (&$p) use ($mutation): void {
                $p['configuration_generation'] = $mutation === 'source-array' ? [] : (object) [];
            });
            payloadChange($a['operation_id'], function (&$p): void {
                unset($p['source_fence_generation']);
            });
        } elseif (str_starts_with($mutation, 'request-')) {
            payloadChange($m['source'], function (&$p): void {
                unset($p['configuration_generation']);
            });
            payloadChange($a['operation_id'], function (&$p) use ($mutation): void {
                $p['source_fence_generation'] = $mutation === 'request-array' ? [] : (object) [];
            });
        } else {
            payloadChange($m['source'], function (&$p) use ($mutation): void {
                if ($mutation === 'missing-provenance') {
                    unset($p['runtime_fence_provenance']);

                    return;
                }
                $key = str_starts_with($mutation, 'disabled-') ? 'runtime_node_disabled' : 'scale_to_zero_requested';
                $field = match ($mutation) {
                    'disabled-operation', 'scale-operation' => 'operation_id', 'disabled-node' => 'runtime_node_id', 'scale-namespace' => 'namespace', default => 'pre_scale_replicas'
                };
                $p['runtime_fence_provenance'][$key][$field] = $field === 'pre_scale_replicas' ? 1.5 : 'wrong';
            });
        }
    });
}
$add('restore', 'competing-pending-fence', function ($r, $a, $m): void {
    $row = (array) DB::table('runtime_operations')->where('id', $m['source'])->first();
    $row['id'] = str_replace('-', '', (string) Str::uuid());
    $row['status'] = 'pending';
    $row['completed_at'] = null;
    DB::table('runtime_operations')->insert($row);
});
function addPolicyBinding(array $r, string $node): void
{
    $c = (string) Str::uuid();
    DB::table('conferences')->insert(['id' => $c, 'tenant_id' => $r['tenant_id'], 'slug' => 'e2-'.Str::lower(Str::random(16)), 'display_name' => 'Synthetic E2 policy fixture', 'runtime_node_id' => $node, 'desired_state' => 'open', 'configuration_generation' => 1, 'created_at' => now(), 'updated_at' => now()]);
    DB::table('conference_runtime_bindings')->insert(['id' => (string) Str::uuid(), 'tenant_id' => $r['tenant_id'], 'conference_id' => $c, 'runtime_node_id' => $node, 'status' => 'active', 'bound_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
}
$add('restore', 'active-open-binding', function ($r): void {
    addPolicyBinding($r, $r['runtime_node_id']);
});
$add('fence', 'conference-capacity-exhausted', function ($r, $a, $m): void {
    DB::table('runtime_nodes')->where('id', $m['replacement'])->update(['capacity_weight' => 1]);
    addPolicyBinding($r, $m['replacement']);
});
$add('fence', 'callleg-capacity-exhausted', function ($r, $a, $m): void {
    DB::table('runtime_nodes')->where('id', $m['replacement'])->update(['capacity_weight' => 1]);
    $c = (string) Str::uuid();
    DB::table('calls')->insert(['id' => $c, 'tenant_id' => $r['tenant_id'], 'direction' => 'outbound', 'observed_state' => 'originating', 'runtime_node_id' => $m['replacement'], 'created_at' => now(), 'updated_at' => now()]);
    DB::table('call_legs')->insert(['id' => (string) Str::uuid(), 'tenant_id' => $r['tenant_id'], 'call_id' => $c, 'runtime_node_id' => $m['replacement'], 'direction' => 'outbound', 'role' => 'originator', 'observed_state' => 'originating', 'created_at' => now(), 'updated_at' => now()]);
});
foreach (['placed', 'ambiguous_multiple_nodes_observed', 'wrong-zone', 'unavailable', 'stale-placed'] as $placement) {
    $add('fence', 'placement-'.$placement, function ($r, $a, $m) use ($placement): void {
        DB::table('runtime_nodes')->where('id', $m['replacement'])->update(['placement_region' => 'proof-region', 'placement_zone' => 'proof-zone']);
        DB::table('runtime_node_k5c_placement_observations')->insert(['runtime_node_id' => $m['replacement'], 'tenant_id' => $r['tenant_id'], 'status' => in_array($placement, ['unavailable', 'ambiguous_multiple_nodes_observed'], true) ? $placement : 'placed', 'observed_region' => 'proof-region', 'observed_zone' => $placement === 'wrong-zone' ? 'other-zone' : 'proof-zone', 'observed_at' => $placement === 'stale-placed' ? now()->subDays(10) : now(), 'created_at' => now(), 'updated_at' => now()]);
    }, in_array($placement, ['placed', 'stale-placed', 'ambiguous_multiple_nodes_observed'], true));
}
file_put_contents('/output/e2-oracle.json', json_encode($result, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT));
echo 'PHP E2 oracle: '.count($result['claims']).' independently evaluated Asterisk claims; meaningful positive fence and restoration decisions included'.PHP_EOL;
