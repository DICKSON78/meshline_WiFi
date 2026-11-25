<?php

namespace Tests\Feature;

use App\Services\Routers\OmadaClient;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class OmadaClientTest extends TestCase
{
    public function test_on_prem_login_cookie_and_csrf_are_sent(): void
    {
        Http::fake([
            'https://controller.test:8043/api/v2/login' => Http::response([
                'result' => ['token' => 'csrf-token'],
            ], 200, ['Set-Cookie' => 'TPOMADA_SESSIONID=abc123; Path=/; HttpOnly; Expires=Wed, 21 Oct 2026 07:28:00 GMT']),
            'https://controller.test:8043/api/v2/sites' => Http::response([
                'errorCode' => 0,
                'result' => [['id' => 'Default', 'name' => 'Default']],
            ]),
            'https://controller.test:8043/api/v2/sites/Default/devices' => Http::response([
                'errorCode' => 0,
                'result' => [['id' => 'dev1', 'name' => 'Gateway']],
            ]),
        ]);

        $client = new OmadaClient([
            'driver' => 'omada',
            'omada_mode' => 'onprem',
            'omada_controller_url' => 'https://controller.test:8043',
            'omada_site_id' => 'Default',
            'user' => 'admin',
            'password' => 'secret',
        ]);

        $client->authenticate();
        $this->assertSame('https://controller.test:8043/api/v2', $client->baseUrl());
        $this->assertCount(1, $client->sites());
        $this->assertSame('Gateway', $client->devices()[0]['name']);

        Http::assertSent(fn (Request $request) => $request->url() === 'https://controller.test:8043/api/v2/sites/Default/devices'
            && $request->hasHeader('Cookie', 'TPOMADA_SESSIONID=abc123')
            && $request->hasHeader('X-CSRFToken', 'csrf-token'));
    }

    public function test_cloud_uses_bearer_token(): void
    {
        Http::fake([
            'https://omada.tplinknetwork.com/api/v2/login' => Http::response([
                'result' => ['accessToken' => 'bearer-token'],
            ]),
            'https://omada.tplinknetwork.com/api/v2/sites' => Http::response([
                'errorCode' => 0,
                'result' => [['id' => 'SiteA', 'name' => 'Site A']],
            ]),
        ]);

        $client = new OmadaClient([
            'driver' => 'omada',
            'omada_mode' => 'cloud',
            'omada_client_id' => 'client-id',
            'omada_client_secret' => 'client-secret',
        ]);

        $this->assertSame('SiteA', $client->sites()[0]['id']);
        Http::assertSent(fn (Request $request) => $request->url() === 'https://omada.tplinknetwork.com/api/v2/sites'
            && $request->hasHeader('Authorization', 'Bearer bearer-token'));
    }

    public function test_voucher_fallback_path_is_used_and_error_cleared(): void
    {
        Http::fake([
            'https://controller.test/api/v2/login' => Http::response(['result' => ['token' => 't']], 200, ['Set-Cookie' => 'TPOMADA_SESSIONID=x']),
            'https://controller.test/api/v2/sites/S/vouchers' => Http::response(['errorCode' => 404, 'msg' => 'not found']),
            'https://controller.test/api/v2/sites/S/hotspot/vouchers' => Http::response([
                'errorCode' => 0,
                'result' => [['id' => 'v1', 'code' => 'HB-1234']],
            ]),
        ]);

        $client = new OmadaClient([
            'driver' => 'omada',
            'omada_mode' => 'onprem',
            'omada_controller_url' => 'https://controller.test',
            'omada_site_id' => 'S',
            'user' => 'admin',
            'password' => 'secret',
        ]);

        $vouchers = $client->vouchers();
        $this->assertSame('HB-1234', $vouchers[0]['code']);
        $this->assertNull($client->lastError());

        Http::assertSent(fn (Request $request) => str_contains($request->url(), '/sites/S/hotspot/vouchers'));
    }
}
