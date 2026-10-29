<?php

use App\Http\Middleware\HandleAppearance;
use App\Http\Middleware\HandleInertiaRequests;
use App\Http\Middleware\UseFlyClientIp;
use Bepsvpt\SecureHeaders\SecureHeadersMiddleware;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Middleware\AddLinkHeadersForPreloadedAssets;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Fly's proxy is the only way in, so trust whichever address connected
        // ('*' would trust every address in X-Forwarded-For, which clients can
        // fill in). Fly passes the browser's Host header through, so
        // X-Forwarded-Host and X-Forwarded-Port aren't trusted: they'd let a
        // client rewrite URLs.
        $middleware->prepend(UseFlyClientIp::class);
        $middleware->trustProxies(
            at: 'REMOTE_ADDR',
            headers: Request::HEADER_X_FORWARDED_FOR | Request::HEADER_X_FORWARDED_PROTO,
        );

        // Only APP_URL's host and its subdomains. Laravel skips this locally and in tests.
        $middleware->trustHosts();

        // Security headers, set in config/secure-headers.php.
        $middleware->append(SecureHeadersMiddleware::class);

        $middleware->encryptCookies(except: ['appearance', 'sidebar_state']);

        $middleware->web(append: [
            HandleAppearance::class,
            HandleInertiaRequests::class,
            AddLinkHeadersForPreloadedAssets::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
