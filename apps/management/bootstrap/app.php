<?php

use App\Http\Middleware\HandleInertiaRequests;
use App\Http\Middleware\AuthenticateApiToken;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Caddy terminates public TLS before forwarding to Nginx/PHP-FPM.
        // Trust its forwarded scheme so generated Vite asset URLs remain HTTPS.
        $middleware->trustProxies(at: '*');

        // Inertia shares auth/flash state with every web response.
        $middleware->web(append: [
            HandleInertiaRequests::class,
        ]);

        // Revocable hashed bearer token guard for the read-only knowledge API.
        $middleware->alias([
            'api.token' => AuthenticateApiToken::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->dontFlash(['token', 'access_token', 'refresh_token', 'code', 'code_verifier', 'client_secret', 'auth_token']);
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*', 'mcp', 'oauth/token', 'oauth/register') || $request->expectsJson(),
        );
    })->create();
