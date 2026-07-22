<?php

namespace Tests\Feature\Platform;

use Illuminate\Broadcasting\BroadcastManager;
use Symfony\Component\Process\Process;
use Tests\TestCase;

final class ReverbRealtimeInfrastructureTest extends TestCase
{
    public function test_reverb_runtime_is_deployed_as_private_platform_workload(): void
    {
        $deployment = $this->repoFile('infrastructure/kubernetes/base/platform/reverb-deployment.yaml');
        $service = $this->repoFile('infrastructure/kubernetes/base/platform/reverb-service.yaml');
        $entrypoint = $this->repoFile('infrastructure/docker/api/entrypoint');
        $overlay = $this->repoFile('infrastructure/kubernetes/overlays/local/platform/application-config.properties');

        $this->assertStringContainsString('name: reverb', $deployment);
        $this->assertStringContainsString('replicas: 1', $deployment);
        $this->assertStringContainsString('image: utcp-api', $deployment);
        $this->assertMatchesRegularExpression('/args:\s+- reverb/', $deployment);
        $this->assertStringContainsString('containerPort: 8080', $deployment);
        $this->assertStringContainsString('name: utcp-local-reverb-credentials', $deployment);
        $this->assertStringContainsString('startupProbe:', $deployment);
        $this->assertStringContainsString('readinessProbe:', $deployment);
        $this->assertStringContainsString('livenessProbe:', $deployment);
        $this->assertStringContainsString('tcpSocket:', $deployment);

        $this->assertStringContainsString('kind: Service', $service);
        $this->assertStringContainsString('name: reverb', $service);
        $this->assertStringContainsString('type: ClusterIP', $service);
        $this->assertStringContainsString('port: 8080', $service);
        $this->assertStringNotContainsString('NodePort', $service);
        $this->assertStringNotContainsString('LoadBalancer', $service);

        $this->assertStringContainsString('reverb)', $entrypoint);
        $this->assertStringContainsString('exec php artisan reverb:start --host=0.0.0.0 --port=8080', $entrypoint);
        $this->assertStringContainsString('BROADCAST_CONNECTION=reverb', $overlay);
        $this->assertStringNotContainsString('BROADCAST_CONNECTION=log', $overlay);
        $this->assertStringContainsString('REVERB_HOST=reverb.utcp-platform.svc.cluster.local', $overlay);
    }

    public function test_reverb_credentials_are_generated_not_checked_in_as_real_secret(): void
    {
        $script = $this->repoFile('scripts/kubernetes/lib');
        $gitignore = $this->repoFile('.gitignore');
        $apiExample = $this->repoFile('apps/api/.env.example');
        $webExample = $this->repoFile('apps/web/.env.example');
        $platformOverlay = $this->repoFile('infrastructure/kubernetes/overlays/local/platform/kustomization.yaml');
        $localOverlay = $this->repoFile('infrastructure/kubernetes/overlays/local/kustomization.yaml');

        $this->assertStringContainsString('ensure_local_reverb_credentials', $script);
        $this->assertStringContainsString('REVERB_APP_ID=utcp-local', $script);
        $this->assertStringContainsString('od -An -N16 -tx1 /dev/urandom', $script);
        $this->assertStringContainsString('od -An -N32 -tx1 /dev/urandom', $script);
        $this->assertStringContainsString('/infrastructure/kubernetes/overlays/local/platform/local-reverb-credentials.properties', $gitignore);

        $this->assertStringContainsString('REVERB_APP_KEY=replace-with-public-reverb-application-key', $apiExample);
        $this->assertStringContainsString('REVERB_APP_SECRET=replace-with-backend-only-reverb-application-secret', $apiExample);
        $this->assertStringContainsString('VITE_UTCP_REVERB_APP_KEY=replace-with-public-reverb-application-key', $webExample);
        $this->assertStringNotContainsString('REVERB_APP_SECRET', $webExample);

        $this->assertStringContainsString('name: utcp-local-reverb-credentials', $platformOverlay);
        $this->assertStringContainsString('local-reverb-credentials.properties', $platformOverlay);
        $this->assertStringContainsString('name: utcp-local-reverb-credentials', $localOverlay);
        $this->assertStringContainsString('platform/local-reverb-credentials.properties', $localOverlay);
    }

    public function test_gateway_routes_wss_to_reverb_before_api_and_frontend_routes(): void
    {
        $nginx = $this->repoFile('infrastructure/docker/gateway/nginx.conf');

        $appPosition = strpos($nginx, 'location ^~ /app/');
        $apiPosition = strpos($nginx, 'location ^~ /api/');
        $frontendPosition = strpos($nginx, 'location / {');

        $this->assertIsInt($appPosition);
        $this->assertIsInt($apiPosition);
        $this->assertIsInt($frontendPosition);
        $this->assertLessThan($apiPosition, $appPosition);
        $this->assertLessThan($frontendPosition, $appPosition);

        $appLocation = substr($nginx, $appPosition, $apiPosition - $appPosition);
        $this->assertStringContainsString('proxy_http_version 1.1;', $appLocation);
        $this->assertStringContainsString('proxy_set_header Upgrade $http_upgrade;', $appLocation);
        $this->assertStringContainsString('proxy_set_header Connection "upgrade";', $appLocation);
        $this->assertStringContainsString('proxy_set_header Host $host;', $appLocation);
        $this->assertStringContainsString('proxy_read_timeout 60s;', $appLocation);
        $this->assertStringContainsString('proxy_send_timeout 60s;', $appLocation);
        $this->assertStringContainsString('proxy_pass http://reverb:8080;', $appLocation);

        $this->assertMatchesRegularExpression('/location \^~ \/api\/ \{(?:(?!location ).)*fastcgi_pass api:9000;/s', $nginx);
    }

    public function test_reverb_network_policy_allows_only_gateway_and_publishers_to_connect(): void
    {
        $reverbPolicy = $this->repoFile('infrastructure/kubernetes/security/platform/allow-reverb.yaml');
        $gatewayPolicy = $this->repoFile('infrastructure/kubernetes/security/platform/allow-gateway.yaml');
        $apiPolicy = $this->repoFile('infrastructure/kubernetes/security/platform/allow-api.yaml');
        $workerPolicy = $this->repoFile('infrastructure/kubernetes/security/platform/allow-worker.yaml');

        $this->assertStringContainsString('name: allow-reverb-required-traffic', $reverbPolicy);
        $this->assertStringContainsString('utcp.io/network-role: reverb', $reverbPolicy);
        $this->assertStringContainsString('utcp.io/network-role: gateway', $reverbPolicy);
        $this->assertStringContainsString('utcp.io/network-role: api', $reverbPolicy);
        $this->assertStringContainsString('utcp.io/network-role: worker', $reverbPolicy);
        $this->assertSame(2, substr_count($reverbPolicy, 'port: 8080'));
        $this->assertStringContainsString('utcp.io/network-role: redis', $reverbPolicy);
        $this->assertStringContainsString('port: 6379', $reverbPolicy);
        $this->assertStringNotContainsString('podSelector: {}', $reverbPolicy);
        $this->assertStringNotContainsString('0.0.0.0/0', $reverbPolicy);

        foreach ([$gatewayPolicy, $apiPolicy, $workerPolicy] as $publisherPolicy) {
            $this->assertStringContainsString('utcp.io/network-role: reverb', $publisherPolicy);
            $this->assertStringContainsString('port: 8080', $publisherPolicy);
        }
    }

    public function test_local_reverb_allowed_origin_is_host_only_gateway_hostname(): void
    {
        $objects = $this->kustomizeObjects('infrastructure/kubernetes/overlays/local/platform');
        $config = $objects['ConfigMap/utcp-platform/utcp-application-config']['data'];

        $this->assertSame('app.utcp.local.test', $config['REVERB_ALLOWED_ORIGIN']);
        $this->assertReverbAllowedOriginIsHostOnly($config['REVERB_ALLOWED_ORIGIN']);

        $assignments = [
            'apps/api/.env.example' => $this->extractEnvAssignment(
                $this->repoFile('apps/api/.env.example'),
                'REVERB_ALLOWED_ORIGIN'
            ),
            'infrastructure/kubernetes/overlays/local/application-config.properties' => $this->extractEnvAssignment(
                $this->repoFile('infrastructure/kubernetes/overlays/local/application-config.properties'),
                'REVERB_ALLOWED_ORIGIN'
            ),
            'infrastructure/kubernetes/overlays/local/platform/application-config.properties' => $this->extractEnvAssignment(
                $this->repoFile('infrastructure/kubernetes/overlays/local/platform/application-config.properties'),
                'REVERB_ALLOWED_ORIGIN'
            ),
            'infrastructure/compose/env.example' => $this->extractEnvAssignment(
                $this->repoFile('infrastructure/compose/env.example'),
                'UTCP_REVERB_ALLOWED_ORIGIN'
            ),
            'infrastructure/compose/compose.yaml' => $this->extractComposeDefault(
                $this->repoFile('infrastructure/compose/compose.yaml'),
                'REVERB_ALLOWED_ORIGIN',
                'UTCP_REVERB_ALLOWED_ORIGIN'
            ),
        ];

        foreach ($assignments as $path => $allowedOrigin) {
            $this->assertSame('app.utcp.local.test', $allowedOrigin, $path);
            $this->assertReverbAllowedOriginIsHostOnly($allowedOrigin, $path);
        }
    }

    public function test_reverb_allowed_origin_fallback_derives_hostname_from_app_url(): void
    {
        $config = $this->reverbConfigWithEnvironment([
            'APP_URL' => 'https://app.utcp.local.test',
            'REVERB_ALLOWED_ORIGIN' => null,
        ]);

        $this->assertSame(
            ['app.utcp.local.test'],
            $config['apps']['apps'][0]['allowed_origins']
        );

        $config = $this->reverbConfigWithEnvironment([
            'APP_URL' => 'https://app.utcp.local.test',
            'REVERB_ALLOWED_ORIGIN' => 'runtime.example.test',
        ]);

        $this->assertSame(
            ['runtime.example.test'],
            $config['apps']['apps'][0]['allowed_origins']
        );

        $config = $this->reverbConfigWithEnvironment([
            'APP_URL' => 'not a valid url',
            'REVERB_ALLOWED_ORIGIN' => null,
        ]);

        $this->assertSame(
            ['localhost'],
            $config['apps']['apps'][0]['allowed_origins']
        );
    }

    public function test_redis_network_policy_allows_reverb_only_on_redis_port(): void
    {
        $objects = $this->kustomizeObjects('infrastructure/kubernetes/security');
        $policy = $objects['NetworkPolicy/utcp-data/allow-redis-from-backend-roles'];
        $spec = $policy['spec'];

        $this->assertSame(['utcp.io/network-role' => 'redis'], $spec['podSelector']['matchLabels']);
        $this->assertSame(['Ingress', 'Egress'], $spec['policyTypes']);
        $this->assertSame([], $spec['egress']);
        $this->assertCount(1, $spec['ingress']);
        $this->assertCount(1, $spec['ingress'][0]['ports']);
        $this->assertSame('TCP', $spec['ingress'][0]['ports'][0]['protocol']);
        $this->assertSame(6379, $spec['ingress'][0]['ports'][0]['port']);

        $roles = [];
        foreach ($spec['ingress'][0]['from'] as $source) {
            $this->assertSame(
                ['kubernetes.io/metadata.name' => 'utcp-platform'],
                $source['namespaceSelector']['matchLabels'] ?? []
            );
            $this->assertArrayHasKey('matchLabels', $source['podSelector'] ?? []);
            $this->assertNotSame([], $source['podSelector']);
            $roles[] = $source['podSelector']['matchLabels']['utcp.io/network-role'] ?? null;
        }

        sort($roles);
        $this->assertSame([
            'api',
            'asterisk-ari-events',
            'migration',
            'reverb',
            'scheduler',
            'simulator-event-source',
            'worker',
        ], $roles);
    }

    public function test_broadcasting_auth_uses_session_middleware_path(): void
    {
        $bootstrap = $this->repoFile('apps/api/bootstrap/app.php');
        $channels = $this->repoFile('apps/api/routes/channels.php');

        $this->assertStringContainsString('->withBroadcasting(', $bootstrap);
        $this->assertStringContainsString("__DIR__.'/../routes/channels.php'", $bootstrap);
        $this->assertStringContainsString("'prefix' => 'api'", $bootstrap);
        $this->assertStringContainsString("'middleware' => ['web', 'identity.session']", $bootstrap);
        $this->assertStringContainsString("Broadcast::channel('tenant.{tenantId}.runtime-nodes'", $channels);
        $this->assertStringContainsString("'runtime.nodes.view'", $channels);
        $this->assertStringContainsString("request()->session()->get('active_tenant_id')", $channels);
    }

    public function test_migration_job_uses_log_broadcaster_without_reverb_credentials(): void
    {
        $objects = $this->kustomizeObjects('infrastructure/kubernetes/overlays/local/migration');
        $job = $objects['Job/utcp-platform/utcp-migrate'];
        $config = $objects['ConfigMap/utcp-platform/utcp-application-config'];
        $container = $job['spec']['template']['spec']['containers'][0];
        $envFrom = $this->envFromNames($container);
        $configData = $config['data'];

        $this->assertSame(['migrate'], $container['args']);
        $this->assertSame('log', $configData['BROADCAST_CONNECTION']);
        $this->assertSame(['utcp-application-config'], $envFrom['configMaps']);
        $this->assertSame([
            'utcp-local-data-credentials',
            'utcp-local-kamailio-db-credentials',
        ], $envFrom['secrets']);
        $this->assertSame('pgsql', $configData['DB_CONNECTION']);
        $this->assertSame('postgres.utcp-data.svc.cluster.local', $configData['DB_HOST']);
        $this->assertSame('redis', $configData['QUEUE_CONNECTION']);

        $renderedJob = json_encode($job, JSON_THROW_ON_ERROR);
        $renderedConfig = json_encode($configData, JSON_THROW_ON_ERROR);

        $this->assertStringNotContainsString('utcp-local-reverb-credentials', $renderedJob);
        foreach ([
            'REVERB_APP_ID',
            'REVERB_APP_KEY',
            'REVERB_APP_SECRET',
            'REVERB_HOST',
            'REVERB_PORT',
            'REVERB_SCHEME',
        ] as $reverbKey) {
            $this->assertArrayNotHasKey($reverbKey, $configData);
            $this->assertStringNotContainsString($reverbKey, $renderedConfig);
        }
    }

    public function test_platform_publishers_keep_reverb_broadcaster_and_credentials(): void
    {
        $objects = $this->kustomizeObjects('infrastructure/kubernetes/overlays/local/platform');
        $config = $objects['ConfigMap/utcp-platform/utcp-application-config']['data'];

        $this->assertSame('reverb', $config['BROADCAST_CONNECTION']);
        $this->assertSame('reverb.utcp-platform.svc.cluster.local', $config['REVERB_HOST']);
        $this->assertSame('8080', $config['REVERB_PORT']);
        $this->assertArrayHasKey('Secret/utcp-platform/utcp-local-reverb-credentials', $objects);

        foreach ([
            'Deployment/utcp-platform/api' => 'api',
            'Deployment/utcp-platform/worker' => 'worker',
            'Deployment/utcp-platform/control-plane-outbox-dispatcher' => 'outbox-dispatcher',
        ] as $key => $containerName) {
            $deployment = $objects[$key];
            $this->assertSame($containerName, $deployment['spec']['template']['spec']['containers'][0]['name']);
            $this->assertContains('utcp-local-reverb-credentials', $this->envFromNames($deployment['spec']['template']['spec']['containers'][0])['secrets']);
        }
    }

    public function test_reverb_workload_keeps_credentials_and_private_clusterip_service(): void
    {
        $objects = $this->kustomizeObjects('infrastructure/kubernetes/overlays/local/platform');
        $deployment = $objects['Deployment/utcp-platform/reverb'];
        $service = $objects['Service/utcp-platform/reverb'];
        $container = $deployment['spec']['template']['spec']['containers'][0];

        $this->assertSame('reverb', $container['name']);
        $this->assertSame(['reverb'], $container['args']);
        $this->assertContains('utcp-local-reverb-credentials', $this->envFromNames($container)['secrets']);
        $this->assertSame(8080, $container['ports'][0]['containerPort']);

        $this->assertSame('ClusterIP', $service['spec']['type']);
        $this->assertSame(8080, $service['spec']['ports'][0]['port']);
        $this->assertSame('ws', $service['spec']['ports'][0]['targetPort']);
        $this->assertArrayNotHasKey('nodePort', $service['spec']['ports'][0]);
    }

    public function test_reverb_broadcaster_requires_a_real_application_key(): void
    {
        config([
            'broadcasting.default' => 'reverb',
            'broadcasting.connections.reverb.key' => null,
            'broadcasting.connections.reverb.secret' => 'test-secret',
            'broadcasting.connections.reverb.app_id' => 'test-app',
            'broadcasting.connections.reverb.options.host' => 'reverb.utcp-platform.svc.cluster.local',
            'broadcasting.connections.reverb.options.port' => 8080,
            'broadcasting.connections.reverb.options.scheme' => 'http',
            'broadcasting.connections.reverb.options.useTLS' => false,
        ]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/Failed to create broadcaster for connection "reverb".*\$auth_key\) must be of type string, null given/s');

        $this->app->make(BroadcastManager::class)->connection('reverb');
    }

    private function repoFile(string $relativePath): string
    {
        return (string) file_get_contents(dirname(base_path(), 2).'/'.$relativePath);
    }

    private function assertReverbAllowedOriginIsHostOnly(string $allowedOrigin, string $message = ''): void
    {
        $this->assertStringNotContainsString('://', $allowedOrigin, $message);
        $this->assertStringNotContainsString('/', $allowedOrigin, $message);
        $this->assertStringNotContainsString(':443', $allowedOrigin, $message);
    }

    private function extractEnvAssignment(string $content, string $key): string
    {
        $this->assertMatchesRegularExpression(
            '/^'.preg_quote($key, '/').'=(?<value>[^\r\n]+)$/m',
            $content
        );
        preg_match('/^'.preg_quote($key, '/').'=(?<value>[^\r\n]+)$/m', $content, $matches);

        return $matches['value'];
    }

    private function extractComposeDefault(string $content, string $key, string $environmentKey): string
    {
        $this->assertMatchesRegularExpression(
            '/^\s+'.preg_quote($key, '/').':\s+\$\{'.preg_quote($environmentKey, '/').':-(?<value>[^}]+)\}$/m',
            $content
        );
        preg_match(
            '/^\s+'.preg_quote($key, '/').':\s+\$\{'.preg_quote($environmentKey, '/').':-(?<value>[^}]+)\}$/m',
            $content,
            $matches
        );

        return $matches['value'];
    }

    /**
     * @param  array<string, string|null>  $environment
     * @return array<string, mixed>
     */
    private function reverbConfigWithEnvironment(array $environment): array
    {
        $keys = array_unique(array_merge(['APP_URL', 'REVERB_ALLOWED_ORIGIN'], array_keys($environment)));
        $previous = [];

        foreach ($keys as $key) {
            $previous[$key] = [
                'getenv' => getenv($key),
                'env' => $_ENV[$key] ?? null,
                'server' => $_SERVER[$key] ?? null,
            ];
            putenv($key);
            unset($_ENV[$key], $_SERVER[$key]);
        }

        foreach ($environment as $key => $value) {
            if ($value === null) {
                continue;
            }

            putenv($key.'='.$value);
            $_ENV[$key] = $value;
            $_SERVER[$key] = $value;
        }

        try {
            return require dirname(base_path(), 2).'/apps/api/config/reverb.php';
        } finally {
            foreach ($previous as $key => $values) {
                if ($values['getenv'] === false) {
                    putenv($key);
                } else {
                    putenv($key.'='.$values['getenv']);
                }

                if ($values['env'] === null) {
                    unset($_ENV[$key]);
                } else {
                    $_ENV[$key] = $values['env'];
                }

                if ($values['server'] === null) {
                    unset($_SERVER[$key]);
                } else {
                    $_SERVER[$key] = $values['server'];
                }
            }
        }
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function kustomizeObjects(string $path): array
    {
        $root = dirname(base_path(), 2);
        $render = new Process(['kubectl', 'kustomize', $path], $root);
        $render->run();
        $this->assertSame(0, $render->getExitCode(), $render->getErrorOutput());

        $parse = new Process(['python3', '-c', <<<'PY'
import json
import sys
import yaml

items = {}
for doc in yaml.safe_load_all(sys.stdin.read()):
    if not doc:
        continue
    metadata = doc.get("metadata", {})
    key = f"{doc.get('kind')}/{metadata.get('namespace')}/{metadata.get('name')}"
    items[key] = doc
print(json.dumps({key: items[key] for key in sorted(items)}))
PY], $root);
        $parse->setInput($render->getOutput());
        $parse->run();
        $this->assertSame(0, $parse->getExitCode(), $parse->getErrorOutput());

        $objects = json_decode($parse->getOutput(), true, flags: JSON_THROW_ON_ERROR);
        $this->assertIsArray($objects);

        return $objects;
    }

    /**
     * @param  array<string, mixed>  $container
     * @return array{configMaps: list<string>, secrets: list<string>}
     */
    private function envFromNames(array $container): array
    {
        $configMaps = [];
        $secrets = [];

        foreach ($container['envFrom'] ?? [] as $entry) {
            if (isset($entry['configMapRef']['name'])) {
                $configMaps[] = $entry['configMapRef']['name'];
            }
            if (isset($entry['secretRef']['name'])) {
                $secrets[] = $entry['secretRef']['name'];
            }
        }

        return [
            'configMaps' => $configMaps,
            'secrets' => $secrets,
        ];
    }
}
