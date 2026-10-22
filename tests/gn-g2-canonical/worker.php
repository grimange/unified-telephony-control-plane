<?php

declare(strict_types=1);

use App\ControlPlane\InfrastructureAuthority\CanonicalInfrastructureService;
use App\ControlPlane\Shared\ExecutionContext;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

require '/var/www/html/vendor/autoload.php';
$app = require '/var/www/html/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$input = json_decode(stream_get_contents(STDIN), true, 512, JSON_THROW_ON_ERROR);
$service = new CanonicalInfrastructureService;
$context = ExecutionContext::system(tenantId: $input['tenant']);
$pid = (int) DB::selectOne('select pg_backend_pid() as pid')->pid;
DB::statement("SET application_name TO 'canonical-proof-worker'");
if (isset($input['barrier'])) {
    $base = $input['barrier'];
    if (! str_starts_with($base, '/output/worker-')) {
        exit(72);
    }
    file_put_contents($base.'.ready', (string) $pid);
    $end = microtime(true) + 20;
    while (! is_file($base.'.release')) {
        if (microtime(true) > $end) {
            exit(73);
        }
        usleep(10000);
    }
}
try {
    $args = $input['args'] ?? [];
    $result = match ($input['method']) {
        'prepare' => $service->prepare($context, ...$args),
        'nextGeneration' => $service->nextGeneration($context, ...$args),
        'bind' => $service->bind($context, ...$args),
        'retire' => $service->retire($context, ...$args),
        'verify' => $service->verify(...$args),
        'recordOutcome' => $service->recordOutcome($context, ...$args),
        'unresolved' => $service->unresolved($input['tenant']),
        'unknown_and_wait' => (function () use ($service, $context, $args, $input): array {
            $service->recordOutcome($context, ...array_merge($args, ['dispatch_attempted', 1]));
            $result = $service->recordOutcome($context, ...array_merge($args, ['outcome_unknown', 1]));
            file_put_contents($input['committed'], 'committed');
            while (true) {
                usleep(10000);
            }
        })(),
        default => throw new InvalidArgumentException('Unsupported proof worker operation.'),
    };
    echo json_encode(['ok' => true, 'result' => $result, 'pid' => $pid], JSON_THROW_ON_ERROR);
} catch (Throwable $e) {
    // Return a safe class category, never operation envelopes, database errors or credentials.
    echo json_encode(['ok' => false, 'error' => $e::class, 'pid' => $pid], JSON_THROW_ON_ERROR);
}
