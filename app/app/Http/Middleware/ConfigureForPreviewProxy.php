<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\URL;
use Symfony\Component\HttpFoundation\Response;

/**
 * Make the app work inside the sandbox preview iframe.
 *
 * The preview serves this app at https://{port}-{sandboxId}.e2b.app, embedded
 * as an iframe in a page on a DIFFERENT site. Three defaults break it, and
 * every one of them produces a blank page with nothing in the Laravel log,
 * because the failure is entirely browser-side:
 *
 *   1. MIXED CONTENT. The proxy terminates TLS and forwards over plain HTTP
 *      *without* sending X-Forwarded-Proto. Laravel therefore builds "http://"
 *      URLs. `/` redirects to `/login`, and the browser refuses to follow an
 *      http:// redirect from an https:// page ("Blocked loading mixed active
 *      content"). TrustProxies cannot help - there is no header to trust.
 *
 *   2. CROSS-SITE COOKIES. A third-party iframe drops SameSite=Lax cookies
 *      ("rejected because it is in a cross-site context"). No session cookie
 *      means no CSRF token, so login can never succeed. SameSite=None is the
 *      only value such a context accepts, and it requires Secure.
 *
 *   3. HARDCODED HOST. The sandbox id changes on every reset, so an APP_URL
 *      written into .env is stale after the next restart. The root URL is
 *      taken from the request instead and never pinned.
 *
 * WHY MIDDLEWARE AND NOT AppServiceProvider::boot():
 * boot() runs ONCE per process. Setting session config there makes the FIRST
 * request's host decide the cookie policy for every later request - so one
 * preview request would hand SameSite=None to a subsequent production-host
 * request, silently relaxing CSRF protection. Under Octane the process is
 * long-lived and that leak is permanent. This must be decided per request.
 */
final class ConfigureForPreviewProxy
{
    /**
     * Hosts that are genuinely a cross-site preview iframe.
     *
     * The preview is reachable under more than one domain: the sandbox's own
     * *.e2b.app host AND the platform's *.arena.site proxy in front of it.
     * Matching only e2b.app left the middleware completely inert on the host
     * the browser actually used, so cookies stayed SameSite=Lax and redirects
     * stayed http:// - the exact two failures this class exists to prevent.
     */
    private const PREVIEW_SUFFIXES = ['.e2b.app', '.arena.site'];

    public function handle(Request $request, Closure $next): Response
    {
        if (! self::isPreviewHost($request->getHost())) {
            return $next($request);
        }

        // The preview is ALWAYS served to the browser over https, even though
        // the proxy forwards to us over plain HTTP and sends no
        // X-Forwarded-Proto. Do not try to infer the transport: an earlier
        // version made this conditional on $request->isSecure(), concluded
        // "http", stopped forcing the scheme, and emitted a redirect to
        // http://…/login. The browser blocked it as mixed active content and
        // rendered a white page.
        //
        // Proof the browser is on https: mixed-content blocking only ever
        // occurs on an https page.
        URL::forceScheme('https');
        URL::forceRootUrl('https://'.$request->getHttpHost());

        // URL::forceScheme() only governs URLs the *generator* builds. It does
        // NOT touch $request->fullUrl(), which is derived from the connection
        // and is therefore still http:// behind this proxy. Anything echoing
        // the request's own URL back to the browser leaks that http:// out:
        //
        //   - redirect()->back(), which is what a failed login uses. With no
        //     Referer (an iframe often sends none) it falls back to the
        //     session's _previous.url - stored by StartSession from
        //     fullUrl(). The browser then blocks that http:// redirect from
        //     an https:// page as mixed active content, and the user sees a
        //     blank page with a form that appears to have wiped itself.
        //   - the intended-URL that auth stashes before bouncing to /login.
        //
        // Marking the connection secure fixes both at the source. This is not
        // *inferring* the transport - it is asserting the one that is known
        // for certain from the host, which an earlier isSecure() check got
        // backwards and turned into a white page.
        $request->server->set('HTTPS', 'on');
        $request->headers->set('X-Forwarded-Proto', 'https');

        // The app runs in an iframe whose top-level document is a DIFFERENT
        // site, so its cookies are third-party. All three attributes are
        // required together and none is optional:
        //
        //   Secure      - mandatory for SameSite=None, and correct here
        //                 because the browser really is on https.
        //   SameSite    - Lax/Strict cookies are refused outright in a
        //                 cross-site context ("has been rejected because it
        //                 is in a cross-site context").
        //   Partitioned - browsers phasing out unpartitioned third-party
        //                 cookies require it (CHIPS); Firefox warns that the
        //                 cookie "will soon be rejected" without it.
        config([
            'session.secure' => true,
            'session.same_site' => 'none',
            'session.partitioned' => true,
            // Third-party cookie policy is not observable from the server and
            // cannot be reliably satisfied, so do not depend on it: allow the
            // session id to travel in the URL as a fallback on preview hosts.
            'epcc.session_url_fallback' => true,
        ]);

        return $next($request);
    }

    /** Public: the response rewriter must apply the same origin test. */
    public static function isPreviewHost(string $host): bool
    {
        foreach (self::PREVIEW_SUFFIXES as $suffix) {
            if (str_ends_with($host, $suffix)) {
                return true;
            }
        }

        return false;
    }
}
