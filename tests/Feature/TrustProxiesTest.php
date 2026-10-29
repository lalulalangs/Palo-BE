<?php

namespace Tests\Feature;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class TrustProxiesTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Route::get('/api/test-client-ip', function (Request $request) {
            return response()->json([
                'ip' => $request->ip(),
                'client_ips' => $request->getClientIps(),
            ]);
        });
    }

    public function test_trusted_proxy_resolves_real_client_ip_from_x_forwarded_for(): void
    {
        // Simulasi request datang dari Nginx lokal (127.0.0.1) dengan header X-Forwarded-For
        $response = $this->withServerVariables([
            'REMOTE_ADDR' => '127.0.0.1',
            'HTTP_X_FORWARDED_FOR' => '203.0.113.195',
            'HTTP_X_FORWARDED_PROTO' => 'https',
            'HTTP_X_FORWARDED_PORT' => '443',
        ])->getJson('/api/test-client-ip');

        $response->assertOk();
        $response->assertJson([
            'ip' => '203.0.113.195',
        ]);
    }

    public function test_ipv6_loopback_trusted_proxy_resolves_real_client_ip(): void
    {
        // Simulasi request datang dari Nginx IPv6 loopback (::1)
        $response = $this->withServerVariables([
            'REMOTE_ADDR' => '::1',
            'HTTP_X_FORWARDED_FOR' => '203.0.113.195',
        ])->getJson('/api/test-client-ip');

        $response->assertOk();
        $response->assertJson([
            'ip' => '203.0.113.195',
        ]);
    }

    public function test_untrusted_client_cannot_spoof_ip_via_x_forwarded_for(): void
    {
        // Simulasi penyerang dari IP luar (198.51.100.20) mencoba spoofing header X-Forwarded-For
        // Karena REMOTE_ADDR bukan trusted proxy, header X-Forwarded-For harus diabaikan
        $response = $this->withServerVariables([
            'REMOTE_ADDR' => '198.51.100.20',
            'HTTP_X_FORWARDED_FOR' => '203.0.113.195',
        ])->getJson('/api/test-client-ip');

        $response->assertOk();
        $response->assertJson([
            'ip' => '198.51.100.20',
        ]);
    }

    public function test_multiple_ips_in_x_forwarded_for_picks_untrusted_client_ip(): void
    {
        // Format X-Forwarded-For: client, untrusted_proxy
        // Reverse proxy lokal (127.0.0.1) menerima header berisi rantai IP
        $response = $this->withServerVariables([
            'REMOTE_ADDR' => '127.0.0.1',
            'HTTP_X_FORWARDED_FOR' => '203.0.113.195, 198.51.100.5',
        ])->getJson('/api/test-client-ip');

        $response->assertOk();
        // 198.51.100.5 adalah hop terakhir yang tidak dipercaya yang menghubungi 127.0.0.1
        $response->assertJson([
            'ip' => '198.51.100.5',
        ]);
    }
}
