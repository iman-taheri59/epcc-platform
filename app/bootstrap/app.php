<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Registered as an alias, NOT globally. Global registration would wrap
        // every request in a transaction - including ones that touch no
        // database at all (/gate) - and hold a connection open for the whole
        // request. It becomes the global default once authentication lands and
        // the acting company comes from the session instead of a query string.
        // Must run before StartSession, which reads the session cookie config
        // when it issues the cookie. Prepending puts it at the very front of
        // the global stack.
        $middleware->prepend(App\Http\Middleware\ConfigureForPreviewProxy::class);

        // MR quantity is a source declaration, not display text. Preserve its
        // exact spelling so the strict validator can reject leading spaces
        // instead of accepting a silently trimmed quantity.
        \Illuminate\Foundation\Http\Middleware\TrimStrings::except('mr.lines.*.quantity');

        // Cookie-less preview support. Two halves, and BOTH the classes and
        // their POSITIONS are load-bearing:
        //
        //   PreviewSessionFallback must run BEFORE EncryptCookies. It turns a
        //   signed `sid` token from the URL into a genuine (encrypted,
        //   prefixed) session cookie on the request, so EncryptCookies,
        //   StartSession, CSRF and the auth guard all see a normal cookie and
        //   need no special-casing. Placing it after EncryptCookies would mean
        //   forging a cookie nothing ever decrypts.
        //
        //   PreviewUrlRewriter must run LAST in the group, so on the way back
        //   out it is the first to touch the finished response and can attach
        //   the token to links, forms, redirects and JSON.
        //
        // NOTE: $middleware->replace() only touches the GLOBAL stack. Anything
        // aimed at the `web` group needs the *InGroup variants - replace()
        // fails silently, which is indistinguishable from "the fix did not
        // work".
        $middleware->prependToGroup('web', App\Http\Middleware\PreviewSessionFallback::class);
        // Locale for the WHOLE web side, not just the admin panel.
        //
        // SetUserLocale was originally registered on the Filament panel alone,
        // because that is where the translator is consulted far from any view.
        // But `__()` is now used by the hand-written screens and, more
        // importantly, by service-layer exception messages - so a refusal
        // raised deep in ProjectArchiveService came back in English to a
        // Persian user reading a Persian page. The hand-written views mostly
        // pick language from `?lang=` via their own $L() closure, which hid
        // the gap: the page was Persian and only the translated strings were
        // not.
        //
        // Added to the group AFTER StartSession (it reads $request->user(),
        // which needs the session) but BEFORE PreviewUrlRewriter, which the
        // note above requires to stay last so it is first to see the response.
        $middleware->appendToGroup('web', App\Http\Middleware\SetUserLocale::class);

        $middleware->appendToGroup('web', App\Http\Middleware\PreviewUrlRewriter::class);

        $middleware->alias([
            'tenant' => App\Http\Middleware\BindTenant::class,
        ]);

        // The app runs behind a TLS-terminating reverse proxy in the sandbox
        // preview (and behind nginx/HAProxy in a customer datacentre). Without
        // this, Laravel believes every request is plain HTTP on the bind
        // address, so redirect() and url() emit http://0.0.0.0:8000/... and
        // the browser lands nowhere. `/` redirects to /login, so the very
        // first request breaks and the page renders blank.
        //
        // Trusting all proxies is correct ONLY because nothing reaches this
        // process except through the proxy. On a real deployment, pin this to
        // the load balancer's address.
        $middleware->trustProxies(
            at: '*',
            headers: Request::HEADER_X_FORWARDED_FOR
                | Request::HEADER_X_FORWARDED_HOST
                | Request::HEADER_X_FORWARDED_PORT
                | Request::HEADER_X_FORWARDED_PROTO
                | Request::HEADER_X_FORWARDED_AWS_ELB,
        );
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // An exception unwinds the ENTIRE middleware pipeline, so a response
        // rendered from one never passes back through PreviewUrlRewriter. That
        // silently drops the preview session token from the two redirects that
        // matter most: the auth middleware bouncing a guest to /login, and a
        // ValidationException sending a failed login back to the form. The
        // user would land on a page belonging to a brand-new empty session and
        // see the fields blanked out - the original bug, from a second cause.
        $exceptions->respond(
            fn (Response $response, Throwable $e, Request $request) => App\Http\Middleware\PreviewUrlRewriter::apply($request, $response),
        );

        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );

        // A CSRF failure on the login form is invisible by default: Laravel
        // returns 419 and the browser re-renders an empty form, which looks
        // exactly like "the button does nothing". That wastes an enormous
        // amount of debugging time. Turn it into a message the user can read
        // and act on, in both languages.
        // Laravel maps TokenMismatchException to a generic HttpException(419)
        // in its prepareException() step, BEFORE user render callbacks run -
        // so matching on TokenMismatchException here never fires. Match the
        // status code instead.
        $exceptions->render(function (HttpExceptionInterface $e, Request $request) {
            if ($e->getStatusCode() !== 419 || ! $request->is('login')) {
                return null;
            }

            $lang = $request->query('lang') === 'en' ? 'en' : 'fa';

            return redirect()
                ->to('/login'.($lang === 'en' ? '?lang=en' : ''))
                ->withInput($request->except('password'))
                ->withErrors(['email' => $lang === 'fa'
                    ? 'نشست شما منقضی شد یا مرورگر کوکی را نپذیرفت. لطفاً صفحه را تازه کنید و دوباره تلاش کنید.'
                    : 'Your session expired or the browser refused the cookie. Refresh the page and try again.',
                ]);
        });
    })->create();
