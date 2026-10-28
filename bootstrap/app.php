<?php

use App\Http\Middleware\CheckFeature;
use App\Http\Middleware\CheckPermission;
use App\Http\Middleware\CheckStaffActive;
use App\Http\Middleware\CheckSubscription;
use App\Http\Middleware\SetApiLocale;
use App\Http\Middleware\SetLocale;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',   // /api/v1 — public customer API (stateless)
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->web([
            SetLocale::class,
        ]);

        $middleware->alias([
            'subscription.active' => CheckSubscription::class,
            'feature' => CheckFeature::class,
            'staff.active' => CheckStaffActive::class,
            'permission' => CheckPermission::class,
            'api.locale' => SetApiLocale::class,
        ]);

        // Single sign-in page for every guard.
        $middleware->redirectGuestsTo(fn () => route('login'));
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*'),
        );

        // One error shape for the customer API: {"message": "...", "errors"?: {...}}.
        // Never a stack trace or exception class, even with APP_DEBUG on.
        // (Exceptions are still reported/logged as usual.)
        $exceptions->render(function (Throwable $e, Request $request) {
            if (! $request->is('api/*')) {
                return null;
            }

            app()->setLocale(SetApiLocale::localeFor($request));

            if ($e instanceof ValidationException) {
                return response()->json(['message' => __('app.api.invalid'), 'errors' => $e->errors()], 422);
            }

            // Not converted by the framework before this callback runs, so without
            // this arm a missing/invalid credential would fall through to a 500.
            // (Web requests are untouched: this whole callback returns null for them.)
            if ($e instanceof AuthenticationException) {
                return response()->json(['message' => __('app.api.unauthenticated')], 401);
            }

            if ($e instanceof ModelNotFoundException) {
                return response()->json(['message' => __('app.api.not_found')], 404);
            }

            if ($e instanceof HttpExceptionInterface) {
                $status = $e->getStatusCode();
                $message = match ($status) {
                    401 => __('app.api.unauthenticated'),
                    403 => __('app.api.forbidden'), // incl. AuthorizationException (mapped to 403 by the framework)
                    404 => __('app.api.not_found'),
                    405 => __('app.api.method_not_allowed'),
                    429 => __('app.api.too_many'),
                    default => __('app.api.error'),
                };

                return response()->json(['message' => $message], $status, $e->getHeaders());
            }

            return response()->json(['message' => __('app.api.server_error')], 500);
        });
    })->create();
