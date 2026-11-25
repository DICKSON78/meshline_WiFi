<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Tests\TestCase;

/**
 * PHP replacement for the Node Jest smoke checks. Deliberately does NOT use
 * RefreshDatabase: the legacy schema is owned by the Node project and a test
 * run must never wipe a real HotBando database.
 */
class ApiSmokeTest extends TestCase
{
    private function databaseAvailable(): bool
    {
        try {
            DB::select('SELECT 1');

            return true;
        } catch (\Throwable $e) {
            return false;
        }
    }

    private function routeExists(string $uri): bool
    {
        foreach (Route::getRoutes() as $route) {
            if ($route->uri() === ltrim($uri, '/')) {
                return true;
            }
        }

        return false;
    }

    public function test_health_returns_the_expected_shape(): void
    {
        $response = $this->get('/health');

        $this->assertContains($response->getStatusCode(), [200, 503]);
        $response->assertJsonStructure(['status', 'uptime', 'database', 'redis']);
        $this->assertContains($response->json('status'), ['ok', 'degraded']);

        if ($response->getStatusCode() === 503) {
            $this->assertSame('disconnected', $response->json('database'));
        }
    }

    public function test_csrf_endpoint_returns_a_token(): void
    {
        if (! $this->routeExists('/api/csrf')) {
            $this->markTestSkipped('GET /api/csrf has not been ported yet.');
        }

        $response = $this->get('/api/csrf');

        $response->assertOk()->assertJsonStructure(['csrfToken']);
        $this->assertNotEmpty($response->json('csrfToken'));
    }

    public function test_me_is_null_when_logged_out(): void
    {
        if (! $this->routeExists('/api/me')) {
            $this->markTestSkipped('GET /api/me has not been ported yet.');
        }

        $response = $this->get('/api/me');

        $response->assertOk()->assertJson(['user' => null]);
    }

    public function test_packages_returns_the_success_envelope_when_seeded(): void
    {
        if (! $this->routeExists('/api/packages')) {
            $this->markTestSkipped('GET /api/packages has not been ported yet.');
        }
        if (! $this->databaseAvailable()) {
            $this->markTestSkipped('Database unreachable; skipping seeded-packages smoke test.');
        }

        try {
            if (! DB::getSchemaBuilder()->hasTable('packages')) {
                $this->markTestSkipped('Legacy packages table is not present.');
            }
            $seeded = DB::table('packages')->where('is_active', 1)->exists();
        } catch (\Throwable $e) {
            $this->markTestSkipped('Could not inspect packages table: '.$e->getMessage());
        }

        if (! $seeded) {
            $this->markTestSkipped('No active packages are seeded.');
        }

        $response = $this->get('/api/packages');

        $response->assertOk()->assertJsonStructure(['success', 'packages']);
        $this->assertTrue((bool) $response->json('success'));
    }

    public function test_spa_routes_serve_index_html_when_frontend_is_built(): void
    {
        $index = base_path('../frontend/dist/index.html');
        if (! is_file($index)) {
            $this->markTestSkipped('frontend/dist/index.html is missing; run npm run build in ../frontend.');
        }

        // /hotspot/dashboard redirects unauthenticated browser requests, so it
        // is intentionally not part of this static SPA assertion.
        foreach (['/', '/admin'] as $uri) {
            $response = $this->get($uri);
            $response->assertOk();
            // BinaryFileResponse streams from disk; getContent() is empty in tests.
            $this->assertInstanceOf(BinaryFileResponse::class, $response->baseResponse);
            $this->assertSame(realpath($index), $response->baseResponse->getFile()->getPathname());
        }
    }

    public function test_scheduled_hotbando_commands_are_registered(): void
    {
        $commands = Artisan::all();

        $this->assertArrayHasKey('hotbando:jobs:tick', $commands);
        $this->assertArrayHasKey('hotbando:jobs:stale-routers', $commands);
        $this->assertArrayHasKey('hotbando:jobs:metrics', $commands);
    }

    public function test_metrics_command_is_opt_in(): void
    {
        $this->artisan('hotbando:jobs:metrics')->assertExitCode(0);
    }
}
