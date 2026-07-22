<?php

namespace Tests\Feature\Platform;

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
        $this->assertStringNotContainsString('podSelector: {}', $reverbPolicy);
        $this->assertStringNotContainsString('0.0.0.0/0', $reverbPolicy);

        foreach ([$gatewayPolicy, $apiPolicy, $workerPolicy] as $publisherPolicy) {
            $this->assertStringContainsString('utcp.io/network-role: reverb', $publisherPolicy);
            $this->assertStringContainsString('port: 8080', $publisherPolicy);
        }
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

    private function repoFile(string $relativePath): string
    {
        return (string) file_get_contents(dirname(base_path(), 2).'/'.$relativePath);
    }
}
