<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class CheckSubscription
{
    public function handle(Request $request, Closure $next): Response
    {
        $owner = auth('owner')->user();

        if (!$owner) return $next($request);

        if (!$owner->isSubscriptionActive()) {
            if ($request->routeIs('owner.logout')) return $next($request);

            return redirect()->route('subscription.expired');
        }

        return $next($request);
    }
}
