<?php

use App\Http\Middleware\FreezeDemoClock;
use App\Http\Middleware\HandleInertiaRequests;
use App\Http\Middleware\HeaderKeamanan;
use App\Http\Middleware\TerapkanKeluarOtomatis;
use App\Http\Middleware\TolakAkunNonaktif;
use App\Http\Middleware\WajibGantiSandi;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Middleware\AddLinkHeadersForPreloadedAssets;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->trustProxies(at: '*');
        $middleware->validateCsrfTokens(except: ['cron/accurate-sinkron', 'cron/chat-sinkron', 'webhook/waha', 'api/*']);
        // every response, error pages included
        $middleware->append(HeaderKeamanan::class);
        $middleware->web(prepend: [
            TerapkanKeluarOtomatis::class,
        ], append: [
            TolakAkunNonaktif::class,
            WajibGantiSandi::class,
            FreezeDemoClock::class,
            HandleInertiaRequests::class,
            AddLinkHeadersForPreloadedAssets::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );

        // a missing page, a refused one, or a server error is shown inside the panel with a
        // way back, not as Laravel's bare page (Audit: old order links left people stranded)
        $exceptions->respond(function (Response $response, Throwable $e, Request $request) {
            $status = $response->getStatusCode();

            if ($request->is('api/*') || $request->expectsJson() || ! in_array($status, [403, 404, 500, 503], true)) {
                return $response;
            }

            // while developing, the error page with the trace is what helps
            if ($status >= 500 && config('app.debug')) {
                return $response;
            }

            return Inertia::render('galat', ['status' => $status])
                ->toResponse($request)
                ->setStatusCode($status);
        });
    })->create();
