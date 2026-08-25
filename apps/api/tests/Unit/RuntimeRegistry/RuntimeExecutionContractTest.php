<?php

namespace Tests\Unit\RuntimeRegistry;

use App\RuntimeRegistry\RuntimeExecutionContract;
use PHPUnit\Framework\TestCase;

final class RuntimeExecutionContractTest extends TestCase
{
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
}
