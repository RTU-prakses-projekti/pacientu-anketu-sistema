<?php

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
    ->withMiddleware(function (Middleware $middleware) {
    $middleware->appendToGroup('web', [\App\Http\Middleware\SetLocale::class]);

    $configuredTrustedProxies = preg_split(
        '/\s*,\s*/',
        trim((string) env('TRUSTED_PROXIES', '')),
        -1,
        PREG_SPLIT_NO_EMPTY,
    );
    $configuredTrustedProxies = array_values(array_filter(
        $configuredTrustedProxies,
        static fn (string $proxy): bool => ! in_array($proxy, ['*', '**'], true),
    ));
    $trustedProxies = array_values(array_unique(array_merge(
        ['172.30.0.0/24', '172.31.0.0/24'],
        $configuredTrustedProxies,
    )));

    $middleware->trustProxies($trustedProxies, Request::HEADER_X_FORWARDED_FOR | Request::HEADER_X_FORWARDED_HOST | Request::HEADER_X_FORWARDED_PORT | Request::HEADER_X_FORWARDED_PROTO);
})
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*'),
        );
    })->create();
