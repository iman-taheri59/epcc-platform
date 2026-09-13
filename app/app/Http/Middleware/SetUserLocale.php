<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Apply the signed-in user's own language to the request.
 *
 * The hand-written screens take their language from a `?lang=` query
 * parameter, which suits a demo where one person switches back and forth to
 * show both directions. It does not suit an admin panel: Filament resolves its
 * labels from the translator at render time, far from any view, so the locale
 * has to be correct before the panel boots.
 *
 * `users.locale` already exists and is already populated (the fixture seeds
 * Persian users), it was simply never read. A document controller in Asaluyeh
 * should not have to append `?lang=fa` to every URL.
 *
 * `?lang=` still wins when present, so a bilingual pair sharing a screen can
 * still flip it, and so the panel behaves like the rest of the product.
 */
final class SetUserLocale
{
    /** @var list<string> */
    private const SUPPORTED = ['fa', 'en'];

    public function handle(Request $request, Closure $next): Response
    {
        $requested = $request->query('lang');
        $user = $request->user();

        $locale = match (true) {
            is_string($requested) && in_array($requested, self::SUPPORTED, true) => $requested,
            $user !== null && in_array($user->locale, self::SUPPORTED, true) => $user->locale,
            // Launch market is Iran: Persian is the default, not the fallback.
            default => 'fa',
        };

        app()->setLocale($locale);

        return $next($request);
    }
}
