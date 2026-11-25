<?php

use App\Http\Middleware\RequireWifi;
use App\Http\Middleware\RoleAuth;
use App\Http\Middleware\SanitizeBody;
use App\Http\Middleware\SecurityHeaders;
use App\Http\Middleware\ThrottleApi;
use App\Http\Middleware\WebhookRateLimit;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->trustProxies(at: 1, headers: Request::HEADER_X_FORWARDED_FOR
            | Request::HEADER_X_FORWARDED_HOST
            | Request::HEADER_X_FORWARDED_PORT
            | Request::HEADER_X_FORWARDED_PROTO);

        $middleware->web(append: [
            SecurityHeaders::class,
            SanitizeBody::class,
            ThrottleApi::class,
        ]);

        $middleware->validateCsrfTokens(except: [
            'api/csrf',
            'api/login', 'api/logout', 'api/me', 'api/packages',
            'api/admin/login', 'api/admin/logout', 'api/admin/withdrawals',
            'api/reseller/login', 'api/reseller/logout', 'api/reseller/register',
            'api/reseller/withdraw', 'api/reseller-applications',
            'api/hotspot/login', 'api/hotspot/register',
            'api/clickpesa/webhook', 'api/webhooks/clickpesa', 'api/clickpesa/ussd-webhook',
            'api/partners/register', 'api/public/partner-application', 'api/public/contact-message',
            'api/zero-touch/*', 'api/collector/ws',
            'debug-register', 'debug-simple-login',
        ]);

        $middleware->alias([
            'role' => RoleAuth::class,
            'wifi' => RequireWifi::class,
            'webhook.throttle' => WebhookRateLimit::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->render(function (\Symfony\Component\HttpKernel\Exception\NotFoundHttpException $e, Request $request) {
            if ($request->is('api/*')) {
                return response()->json(['success' => false, 'error' => 'Not found'], 404);
            }

            return app(\App\Http\Controllers\SpaController::class)->index();
        });
        $exceptions->render(function (\Illuminate\Validation\ValidationException $e, Request $request) {
            if ($request->is('api/*') || $request->expectsJson()) {
                return response()->json([
                    'success' => false,
                    'message' => collect($e->errors())->flatten()->first() ?: 'Validation failed',
                    'errors' => $e->errors(),
                ], 422);
            }
        });
    })->create();
