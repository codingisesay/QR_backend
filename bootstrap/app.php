<?php
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware) {
        $middleware->alias([
            'tenant' => \App\Http\Middleware\ResolveTenant::class,
            'perm'   => \App\Http\Middleware\RequirePermission::class,
            'resolve.tenant'  => \App\Http\Middleware\ResolveTenant::class, // alias
        ]);
    })
    ->withProviders([
        \App\Providers\AppServiceProvider::class,   // 👈 ensure both are loaded
        \App\Providers\AuthServiceProvider::class,  // 👈 Gate('perm') lives here
    ])
    ->withExceptions(function (Exceptions $exceptions) {
        //
    })
    ->create();
