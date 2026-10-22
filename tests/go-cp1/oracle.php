<?php

declare(strict_types=1);

// Test-only behavioral oracle. The implementation under test never generates these decisions.
use App\ControlPlane\InfrastructureAuthority\ActiveFingerprint;
use App\ControlPlane\InfrastructureAuthority\CanonicalInfrastructureService;
use App\ControlPlane\Shared\ExecutionContext;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Str;

require '/var/www/html/vendor/autoload.php';
$app = require '/var/www/html/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
if (getenv('APP_ENV') !== 'testing' || getenv('DB_DATABASE') !== 'utcp_canonical_proof'
    || ! str_starts_with((string) getenv('UTCP_CP1_RUN'), 'utcp-go-cp1-')) {
    throw new RuntimeException('Disposable GO-CP1 database required.');
}
require '/prior-proof/Fixture.php';
$service = new CanonicalInfrastructureService;
$result = ['fingerprints' => [], 'claims' => []];
foreach (['utcp.infrastructure.v1', 'utcp.infrastructure.v2', 'utcp.infrastructure.v3'] as $version) {
    [$tenant, , $r] = CanonicalPgFixture::prepared();
    $context = ExecutionContext::system(tenantId: $tenant);
    if ($version !== 'utcp.infrastructure.v2') {
        $r = $service->bind($context, $r['id'], 'synthetic-uid-'.Str::lower(Str::random(12)));
    }
    $kind = match ($version) {
        'utcp.infrastructure.v1' => 'admit_generation',
        'utcp.infrastructure.v2' => 'create_suspended',
        default => 'converge_projection',
    };
    $action = CanonicalPgFixture::action($r, $version, $kind);
    $service->persistIntent($context, $r['id'], $action);
    $result['fingerprints'][] = ['name' => $version, 'action' => $action, 'fingerprint' => ActiveFingerprint::versioned($action)];
    $add = function (string $name, array $candidate) use (&$result, $service): void {
        $decision = $service->verify($candidate);
        $result['claims'][] = ['name' => $name, 'action' => $candidate, 'authorized' => $decision['authorized']];
    };
    $add($version.'.current', $action);
    foreach (['attempt', 'token', 'tenant', 'generation', 'fingerprint', 'uid'] as $mutation) {
        $negative = $action;
        match ($mutation) {
            'attempt' => $negative['claim']['attempt']++,
            'token' => $negative['claim']['fencing_token'] = str_repeat('f', 32),
            'tenant' => $negative['tenant_id'] = (string) Str::uuid(),
            'generation' => $negative['target_generation'] = '9007199254740993',
            'fingerprint' => $negative['intent_fingerprint'] = str_repeat('0', 64),
            'uid' => $negative['target']['expected_uid'] = 'wrong-uid',
        };
        $add($version.'.wrong-'.$mutation, $negative);
    }
}
// The private output contains only synthetic claim tokens, and is never copied into repository evidence.
file_put_contents('/output/oracle.json', json_encode($result, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT));
echo 'PHP oracle: '.count($result['fingerprints']).' fingerprints, '.count($result['claims'])." claim decisions\n";
