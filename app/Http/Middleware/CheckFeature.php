<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class CheckFeature
{
    public function handle(Request $request, Closure $next, string $featureKey): Response
    {
        $owner = auth('owner')->user();

        if (!$owner) {
            return redirect()->route('login');
        }

        if (!$owner->hasFeature($featureKey)) {
            return redirect()->route('dashboard')->with(
                'error',
                'You do not have access to this feature. Please contact your administrator.'
            );
        }

        return $next($request);
    }
}
