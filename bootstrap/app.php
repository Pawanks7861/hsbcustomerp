<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware) {
        $middleware->web(append: [
            \Illuminate\Http\Middleware\AddLinkHeadersForPreloadedAssets::class,
        ]);

        $middleware->web(append: [
            \App\Http\Middleware\HandleInertiaRequests::class,
        ]);

        $middleware->alias([
            'active' => \App\Http\Middleware\EnsureUserIsActive::class,
            'admin' => \App\Http\Middleware\EnsureUserIsAdmin::class,
            'permission' => \App\Http\Middleware\EnsurePermission::class,
            'project.access' => \App\Http\Middleware\EnsureProjectAccess::class,
        ]);

        $middleware->redirectGuestsTo('/login');
        $middleware->redirectUsersTo('/');
    })
    ->withExceptions(function (Exceptions $exceptions) {
        /*
         * Inertia XHR responses that are plain HTML get shown as a modal over the
         * current page. Force a hard visit to the standalone error routes instead.
         */
        $exceptions->render(function (NotFoundHttpException $e, Request $request) {
            if ($request->expectsJson() && ! $request->header('X-Inertia')) {
                return null;
            }

            if ($request->header('X-Inertia')) {
                return Inertia::location(url('/error/404'));
            }

            return response()->view('errors.404', [], 404);
        });

        $exceptions->render(function (HttpException $e, Request $request) {
            if ($e->getStatusCode() !== 403) {
                return null;
            }

            if ($request->expectsJson() && ! $request->header('X-Inertia')) {
                return null;
            }

            if ($request->header('X-Inertia')) {
                return Inertia::location(url('/error/403'));
            }

            return response()->view('errors.403', [], 403);
        });
    })->create();
