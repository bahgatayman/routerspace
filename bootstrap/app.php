<?php

use App\Http\Middleware\CheckFeature;
use App\Http\Middleware\CheckPermission;
use App\Http\Middleware\CheckStaffActive;
use App\Http\Middleware\CheckSubscription;
use App\Http\Middleware\HandleInertiaRequests;
use App\Http\Middleware\SetLocale;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->web([
            SetLocale::class,
            // Last: shares props after locale + session are ready.
            HandleInertiaRequests::class,
        ]);

        $middleware->alias([
            'subscription.active' => CheckSubscription::class,
            'feature' => CheckFeature::class,
            'staff.active' => CheckStaffActive::class,
            'permission' => CheckPermission::class,
        ]);

        // Single sign-in page for every guard.
        $middleware->redirectGuestsTo(fn () => route('login'));
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*'),
        );

        // Client-side (Inertia) visits get the React error page instead of a
        // Blade document inside a modal; full page loads keep the Blade
        // errors/* pages. An expired CSRF token sends the user back with a
        // message rather than a dead end.
        $exceptions->respond(function (Response $response, Throwable $e, Request $request) {
            if (! $request->header('X-Inertia')) {
                return $response;
            }
            $status = $response->getStatusCode();
            if ($status === 419) {
                return back()->with('error', __('app.error.419_message'));
            }
            if (in_array($status, [403, 404, 429, 500, 503], true) && (! config('app.debug') || $status !== 500)) {
                return Inertia::render('Error', ['status' => $status])->toResponse($request)->setStatusCode($status);
            }

            return $response;
        });
    })->create();
