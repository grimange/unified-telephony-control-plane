<?php

namespace Tests\Unit\RuntimeRegistry;

use App\RuntimeRegistry\RuntimeExecutionContract;
use PHPUnit\Framework\TestCase;

final class RuntimeExecutionContractTest extends TestCase
{
    public function test_qualified_immutable_image_reference_accepts_explicit_registries_without_path_allowlists(): void
    {
        $digest = 'sha256:'.str_repeat('a', 64);

        foreach ([
            'ghcr.io/grimange/utcp-asterisk@'.$digest,
            'ghcr.io/grimange/utcp-freeswitch@'.$digest,
            'registry.example.test/utcp/asterisk-ari@'.$digest,
            'registry.example.test/utcp/freeswitch@'.$digest,
            'utcp-local-registry:5000/utcp/asterisk-ari@'.$digest,
            'utcp-local-registry:5000/utcp/freeswitch@'.$digest,
        ] as $image) {
            $this->assertTrue(RuntimeExecutionContract::isQualifiedImmutableImageReference($image), $image);
        }
    }

    public function test_qualified_immutable_image_reference_rejects_unqualified_mutable_or_corrupt_references(): void
    {
        $digest = str_repeat('a', 64);

        foreach ([
            '',
            'utcp-asterisk',
            'utcp-asterisk:latest',
            'ghcr.io/grimange/utcp-asterisk:latest',
            'ghcr.io/grimange/utcp-asterisk',
            'utcp-asterisk@sha256:'.$digest,
            'registry.example.test/utcp/asterisk-ari@sha256:short',
            'registry.example.test/utcp/asterisk-ari@sha256:'.strtoupper($digest),
            "ghcr.io/grimange/utcp-asterisk @sha256:$digest",
            "ghcr.io/grimange/utcp-asterisk@sha256:$digest\n",
            'docker.io/library/utcp-asterisk@sha256:'.$digest.'?tag=latest',
        ] as $image) {
            $this->assertFalse(RuntimeExecutionContract::isQualifiedImmutableImageReference($image), $image);
        }
    }

    public function test_digest_qualified_desired_reference_matches_kubernetes_image_id_by_digest(): void
    {
        $digest = 'sha256:'.str_repeat('a', 64);

        $this->assertSame($digest, RuntimeExecutionContract::digest('registry.example.test/utcp/asterisk-ari@'.$digest));
        $this->assertSame($digest, RuntimeExecutionContract::digest('docker-pullable://registry.example.test/utcp/asterisk-ari@'.$digest));
        $this->assertTrue(RuntimeExecutionContract::isCurrent(
            'registry.example.test/utcp/asterisk-ari@'.$digest,
            'docker-pullable://registry.example.test/utcp/asterisk-ari@'.$digest,
        ));
    }

    public function test_mutable_or_missing_image_identity_never_matches(): void
    {
        $digest = 'sha256:'.str_repeat('b', 64);

        $this->assertNull(RuntimeExecutionContract::digest('registry.example.test/utcp/asterisk-ari:latest'));
        $this->assertNull(RuntimeExecutionContract::digest(null));
        $this->assertFalse(RuntimeExecutionContract::isCurrent('registry.example.test/utcp/asterisk-ari@'.$digest, null));
        $this->assertFalse(RuntimeExecutionContract::isCurrent('registry.example.test/utcp/asterisk-ari@'.$digest, 'docker-pullable://registry.example.test/utcp/asterisk-ari@sha256:'.str_repeat('c', 64)));
    }

    public function test_native_inbound_runtime_projection_uses_the_same_digest_contract(): void
    {
        $digest = 'sha256:'.str_repeat('d', 64);
        foreach ([
            [$digest, $digest, true],
            [null, $digest, false],
            [$digest, null, false],
            ['sha256:'.str_repeat('e', 64), $digest, false],
            ['registry.example.test/utcp/runtime:latest', $digest, false],
        ] as [$desired, $observed, $expected]) {
            $this->assertSame($expected, RuntimeExecutionContract::isCurrent($desired, $observed));
        }

        $migration = dirname(__DIR__, 3).'/database/migrations/2026_08_26_101000_create_kamailio_inbound_runtime_target_view.php';
        $sql = file_get_contents($migration);
        $this->assertIsString($sql);
        $this->assertStringContainsString("n.desired_execution_image ~ '(^|@)sha256:[0-9a-f]{64}($|[?#])'", $sql);
        $this->assertStringContainsString("n.observed_execution_image ~ '(^|@)sha256:[0-9a-f]{64}($|[?#])'", $sql);
        $this->assertStringContainsString("substring(n.desired_execution_image from '(sha256:[0-9a-f]{64})') = substring(n.observed_execution_image from '(sha256:[0-9a-f]{64})')", $sql);
    }
}
