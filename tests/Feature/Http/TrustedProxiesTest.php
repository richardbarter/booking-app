<?php

namespace Tests\Feature\Http;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class TrustedProxiesTest extends TestCase
{
    /**
     * The address Fly's proxy connects from, on its private network.
     */
    private const string PROXY_IP = '172.16.0.2';

    protected function setUp(): void
    {
        parent::setUp();

        Route::get('/_test/request', fn (Request $request) => [
            'ip' => $request->ip(),
            'url' => url('/login'),
        ]);
    }

    public function test_client_ip_comes_from_fly_client_ip_rather_than_x_forwarded_for(): void
    {
        $response = $this->withServerVariables(['REMOTE_ADDR' => self::PROXY_IP])
            ->withHeaders([
                'Fly-Client-IP' => '192.0.2.44',
                'X-Forwarded-For' => '198.51.100.7, 192.0.2.44, 203.0.113.9',
            ])
            ->getJson('/_test/request');

        $response->assertJsonPath('ip', '192.0.2.44');
    }

    public function test_client_ip_ignores_x_forwarded_for_entries_before_the_proxy(): void
    {
        $response = $this->withServerVariables(['REMOTE_ADDR' => self::PROXY_IP])
            ->withHeaders(['X-Forwarded-For' => '198.51.100.7, 192.0.2.44'])
            ->getJson('/_test/request');

        $response->assertJsonPath('ip', '192.0.2.44');
    }

    public function test_forwarded_https_makes_generated_urls_https(): void
    {
        $response = $this->withServerVariables(['REMOTE_ADDR' => self::PROXY_IP])
            ->withHeaders(['X-Forwarded-Proto' => 'https'])
            ->getJson('http://localhost/_test/request');

        $response->assertJsonPath('url', 'https://localhost/login');
    }

    public function test_generated_urls_ignore_the_forwarded_host_and_port(): void
    {
        $response = $this->withServerVariables(['REMOTE_ADDR' => self::PROXY_IP])
            ->withHeaders([
                'X-Forwarded-Host' => 'evil.example',
                'X-Forwarded-Port' => '8080',
            ])
            ->getJson('http://localhost/_test/request');

        $response->assertJsonPath('url', 'http://localhost/login');
    }
}
