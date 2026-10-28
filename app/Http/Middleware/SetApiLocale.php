<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Language for API responses (room type labels, formatted prices, error
 * messages). `?lang=en|ar` wins, else the Accept-Language header, else the app
 * default. Sessions are not used — the API is stateless.
 */
class SetApiLocale
{
    private const LOCALES = ['en', 'ar'];

    public function handle(Request $request, Closure $next): Response
    {
        $locale = self::localeFor($request);

        app()->setLocale($locale);

        $response = $next($request);
        $response->headers->set('Content-Language', $locale);
        $response->setVary(array_unique(array_merge($response->getVary(), ['Accept-Language'])));

        return $response;
    }

    /**
     * Also used by the api/* exception renderer (bootstrap/app.php): an error can
     * be thrown before this middleware runs (auth middleware is prioritised ahead
     * of it; an unknown path matches no route at all), and must still be localized.
     */
    public static function localeFor(Request $request): string
    {
        $lang = $request->query('lang');

        return is_string($lang) && in_array($lang, self::LOCALES, true)
            ? $lang
            : ($request->getPreferredLanguage(self::LOCALES) ?? config('app.locale', 'en'));
    }
}
