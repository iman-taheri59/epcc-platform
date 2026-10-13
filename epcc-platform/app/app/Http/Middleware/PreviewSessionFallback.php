<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Contracts\Encryption\Encrypter;
use Illuminate\Cookie\CookieValuePrefix;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Carry the session id in the URL when the browser refuses the cookie.
 *
 * WHY THIS EXISTS
 * The preview serves this app inside an iframe whose top-level document is a
 * DIFFERENT site, so every cookie it sets is third-party. Browsers block
 * those. The symptom is maddening and logs nothing: the login form submits,
 * Auth::attempt() succeeds, the 302 is issued - and the next request arrives
 * with no cookie, so it is a brand-new anonymous session and the user lands
 * back on /login. Forever.
 *
 * Four rounds of cookie-attribute tuning (SameSite=None, Secure, Partitioned,
 * CHIPS) failed against a real browser, because the embedding page's cookie
 * policy is not observable from the server and therefore cannot be reliably
 * satisfied. The dependency has to be removed, not negotiated.
 *
 * HOW
 * A signed `sid` token travels in the URL. This middleware runs BEFORE
 * EncryptCookies and turns that token back into a genuine session cookie on
 * the request. Everything downstream - EncryptCookies, StartSession,
 * VerifyCsrfToken, the auth guard, and any future Livewire/Filament panel -
 * then behaves exactly as it does in a normal browser, because as far as they
 * can tell the cookie arrived.
 *
 * THE CRITICAL DETAIL
 * The forged cookie must be encrypted AND carry the CookieValuePrefix HMAC.
 * EncryptCookies::validateValue() drops any cookie whose plaintext does not
 * start with hash_hmac('sha1', $name.'v2', $key).'|', so a cookie holding a
 * bare session id is silently discarded and the whole mechanism looks broken
 * in exactly the same way as the bug it fixes.
 *
 * SECURITY
 * A session id in a URL leaks via Referer, history and shared links, so:
 *   - the token is HMAC-signed, not a raw id (a bare id in a query parameter
 *     is a session-fixation invitation);
 *   - the mechanism is gated on a preview host AND on the
 *     epcc.session_url_fallback flag, which defaults to OFF;
 *   - the real cookie always wins when the browser sends one.
 * On a customer deployment this middleware is inert.
 */
final class PreviewSessionFallback
{
    /** Query/body parameter carrying the signed session token. */
    public const PARAM = 'sid';

    /** Request attribute: the browser sent no session cookie of its own. */
    public const COOKIELESS = 'preview.cookieless';

    public function __construct(private readonly Encrypter $encrypter) {}

    public function handle(Request $request, Closure $next): Response
    {
        $cookieName = config('session.cookie');

        // Decided here, before anything can forge one: did the BROWSER send a
        // session cookie? The response rewriter needs the original answer, not
        // the one this middleware is about to fabricate.
        $request->attributes->set(self::COOKIELESS, ! $request->cookies->has($cookieName));

        if (! self::enabled() || ! $request->attributes->get(self::COOKIELESS)) {
            return $next($request);
        }

        $id = self::verify($this->tokenFrom($request));

        if ($id !== null) {
            $request->cookies->set($cookieName, $this->forgeCookie($cookieName, $id));
        }

        return $next($request);
    }

    /** Only on preview hosts, and only when explicitly switched on. */
    public static function enabled(): bool
    {
        return (bool) config('epcc.session_url_fallback', false);
    }

    /**
     * Sign a session id for transport in a URL.
     *
     * Format: {id}.{truncated HMAC}. URL-safe by construction, so it survives
     * query strings, form fields and Location headers without escaping.
     */
    public static function token(string $id): string
    {
        return $id.'.'.self::signature($id);
    }

    /** Recover the session id from a token, or null if it is not authentic. */
    public static function verify(?string $token): ?string
    {
        if (! is_string($token) || ! str_contains($token, '.')) {
            return null;
        }

        [$id, $mac] = explode('.', $token, 2);

        // Laravel session ids are 40 alphanumeric characters.
        if (strlen($id) !== 40 || ! ctype_alnum($id)) {
            return null;
        }

        return hash_equals(self::signature($id), $mac) ? $id : null;
    }

    private static function signature(string $id): string
    {
        return substr(hash_hmac('sha256', $id, (string) config('app.key')), 0, 32);
    }

    /** Query string first, then POST body (a form action can drop the query). */
    private function tokenFrom(Request $request): ?string
    {
        $fromQuery = $request->query(self::PARAM);

        if (is_string($fromQuery) && $fromQuery !== '') {
            return $fromQuery;
        }

        $fromBody = $request->post(self::PARAM);

        return is_string($fromBody) && $fromBody !== '' ? $fromBody : null;
    }

    /**
     * Build a cookie value that EncryptCookies will accept and decrypt.
     *
     * Without the prefix the cookie decrypts fine and is then thrown away by
     * validateValue(). See the class docblock.
     */
    private function forgeCookie(string $name, string $id): string
    {
        return $this->encrypter->encrypt(
            CookieValuePrefix::create($name, $this->encrypter->getKey()).$id,
            false, // EncryptCookies::$serialize is false by default.
        );
    }
}
