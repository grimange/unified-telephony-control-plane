<?php

declare(strict_types=1);

// Test-only process entrypoint. No Laravel production route is registered.
// The verifier owns this loopback-published container and the mTLS frontend.
use App\ControlPlane\InfrastructureAuthority\CanonicalInfrastructureService;
use Illuminate\Contracts\Console\Kernel;

require '/var/www/html/vendor/autoload.php';
$app = require '/var/www/html/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
header('Content-Type: application/json');
header('Cache-Control: no-store');
$version = 'utcp.infrastructure.v1';
try {
    if (getenv('APP_ENV') !== 'testing' || ! str_starts_with((string) getenv('DB_DATABASE'), 'utcp_canonical_')) {
        throw new RuntimeException('Test target required.');
    }
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
        http_response_code(405);
        throw new RuntimeException('POST required.');
    }
    $body = file_get_contents('php://input', false, null, 0, 65537);
    if ($body === false || strlen($body) > 65536) {
        http_response_code(413);
        throw new RuntimeException('Bounded body required.');
    }
    $action = json_decode($body, true, 32, JSON_THROW_ON_ERROR);
    $version = $action['contract_version'] ?? $version;
    echo json_encode($app->make(CanonicalInfrastructureService::class)->verify($action), JSON_THROW_ON_ERROR);
} catch (Throwable) {
    // Never expose supplied envelopes, lease tokens, SQL, credentials or exceptions.
    echo json_encode(['contract_version' => $version, 'authorized' => false], JSON_THROW_ON_ERROR);
}
