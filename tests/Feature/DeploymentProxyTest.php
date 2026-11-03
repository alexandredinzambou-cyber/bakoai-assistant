<?php

namespace Tests\Feature;

use Illuminate\Http\Middleware\TrustProxies;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class DeploymentProxyTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        TrustProxies::flushState();

        Route::get('/deployment-proxy-test', fn (Request $request) => [
            'secure' => $request->isSecure(),
            'ip' => $request->ip(),
            'host' => $request->getHost(),
        ]);
    }

    protected function tearDown(): void
    {
        TrustProxies::flushState();
        parent::tearDown();
    }

    public function test_managed_proxy_preserves_https_and_client_ip_without_trusting_forwarded_host(): void
    {
        config(['deployment.trusted_proxies' => '*']);

        $this->withHeaders([
            'X-Forwarded-Proto' => 'https',
            'X-Forwarded-Port' => '443',
            'X-Forwarded-For' => '203.0.113.42',
            'X-Forwarded-Host' => 'unrelated.example',
        ])->get('/deployment-proxy-test')
            ->assertOk()
            ->assertJsonPath('secure', true)
            ->assertJsonPath('ip', '203.0.113.42')
            ->assertJsonPath('host', 'localhost');
    }

    public function test_local_installation_does_not_trust_forwarded_headers_by_default(): void
    {
        config(['deployment.trusted_proxies' => null]);

        $this->withHeaders([
            'X-Forwarded-Proto' => 'https',
            'X-Forwarded-For' => '203.0.113.42',
        ])->get('/deployment-proxy-test')
            ->assertOk()
            ->assertJsonPath('secure', false)
            ->assertJsonPath('ip', '127.0.0.1');
    }
}
