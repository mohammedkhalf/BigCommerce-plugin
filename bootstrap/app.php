<?php

use App\Http\Middleware\FrameAncestors;
use App\Http\Middleware\HandleInertiaRequests;
use App\Http\Middleware\PersistAppSessionCookie;
use App\Http\Middleware\ResolveStoreContext;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        then: function () {
            Route::middleware('api')
                ->prefix('webhooks')
                ->group(base_path('routes/webhooks.php'));
        },
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware) {
        $middleware->web(append: [
            HandleInertiaRequests::class,
            PersistAppSessionCookie::class,
            FrameAncestors::class,
        ]);
        $middleware->alias([
            'inertia' => HandleInertiaRequests::class,
            'frame.ancestors' => FrameAncestors::class,
            'store.context' => ResolveStoreContext::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions) {
        $exceptions->render(function (AuthenticationException $exception, Request $request): ?Response {
            if ($request->expectsJson()) {
                return response()->json(['message' => $exception->getMessage()], 401);
            }

            return Inertia::render('Errors/Unauthorized')
                ->toResponse($request)
                ->setStatusCode(401);
        });
    })->create();
