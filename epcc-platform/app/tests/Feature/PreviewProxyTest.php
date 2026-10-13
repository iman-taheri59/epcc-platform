<?php

declare(strict_types=1);

namespace Tests\Feature;

use Database\Seeders\AuthSeeder;
use Database\Seeders\EpccDemoSeeder;
use Tests\TestCase;

/**
 * Serving the app inside the sandbox preview iframe.
 *
 * The preview puts this app at https://{port}-{sandboxId}.e2b.app inside an
 * iframe on a different site. Three defaults break it, each producing a blank
 * page with nothing in the Laravel log - the failure is entirely browser-side,
 * which is why it needs pinning here rather than being "obvious in testing".
 *
 * The relaxations are deliberately scoped to the sandbox host. A production
 * deployment must keep SameSite=Lax, so every assertion below has a
 * counterpart asserting a normal host is untouched.
 */
final class PreviewProxyTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->artisan('migrate:fresh', ['--force' => true]);
        $this->seed(EpccDemoSeeder::class);
        $this->seed(AuthSeeder::class);
    }

    private const SANDBOX = '8001-i0ut3xutf3lbke49740qu.e2b.app';

    /**
     * The platform proxies the preview under its OWN domain too, and that is
     * the host the browser actually uses. Matching only *.e2b.app left this
     * middleware inert exactly where it was needed: cookies stayed
     * SameSite=Lax and were rejected as foreign, redirects stayed http:// and
     * were blocked as mixed content, and login silently failed with 419.
     */
    private const ARENA = 'sbx-ccplhuznqk67y4ym.arena.site';

    // ---- 1. mixed content -------------------------------------------

    public function test_redirects_on_the_preview_host_are_https(): void
    {
        // The proxy terminates TLS and forwards over plain HTTP WITHOUT
        // sending X-Forwarded-Proto. If Laravel emits http:// here, the
        // browser refuses to follow it from an https:// page:
        // "Blocked loading mixed active content".
        $r = $this->get('http://'.self::SANDBOX.'/');

        // A _sid may be appended when the cookie is not returned; the scheme
        // and host are what this test is about.
        $this->assertStringStartsWith(
            'https://'.self::SANDBOX.'/login',
            (string) $r->headers->get('Location'),
        );
    }

    public function test_the_preview_host_is_not_hardcoded(): void
    {
        // The sandbox id changes on every reset. Anything pinned in .env is
        // stale by the next restart - that is exactly how this broke twice.
        $other = '3000-someothersandboxid.e2b.app';

        $this->assertStringStartsWith(
            'https://'.$other.'/login',
            (string) $this->get('http://'.$other.'/')->headers->get('Location'),
        );
    }

    public function test_the_platform_proxy_domain_is_also_treated_as_preview(): void
    {
        $this->assertStringStartsWith(
            'https://'.self::ARENA.'/login',
            (string) $this->get('http://'.self::ARENA.'/')->headers->get('Location'),
        );
    }

    public function test_cookies_on_the_platform_proxy_domain_survive_the_iframe(): void
    {
        $cookie = collect($this->get('http://'.self::ARENA.'/login')->headers->getCookies())
            ->firstWhere(fn ($c) => $c->getName() === 'laravel_session');

        $this->assertNotNull($cookie);
        $this->assertSame('none', strtolower((string) $cookie->getSameSite()));
        $this->assertTrue($cookie->isSecure());
    }

    // ---- 2. cross-site cookies ---------------------------------------

    public function test_session_cookies_survive_a_third_party_iframe(): void
    {
        // An iframe from another registrable domain is a third-party context.
        // SameSite=Lax cookies are dropped there, so there is no session and
        // no CSRF token, and login can never succeed.
        $r = $this->get('http://'.self::SANDBOX.'/login');

        $cookie = collect($r->headers->getCookies())
            ->firstWhere(fn ($c) => $c->getName() === 'laravel_session');

        $this->assertNotNull($cookie, 'no session cookie was issued');
        $this->assertSame('none', strtolower((string) $cookie->getSameSite()));
        // SameSite=None is only honoured when the cookie is also Secure.
        $this->assertTrue($cookie->isSecure(), 'SameSite=None requires Secure');
        // Browsers phasing out unpartitioned third-party cookies reject the
        // cookie without this ("will soon be rejected because it is foreign
        // and does not have the Partitioned attribute").
        $this->assertTrue($cookie->isPartitioned(), 'a third-party cookie needs Partitioned');
    }

    // ---- the relaxations must NOT leak to real deployments ------------

    public function test_a_normal_host_keeps_samesite_lax(): void
    {
        $r = $this->get('http://epcc.example.com/login');

        $cookie = collect($r->headers->getCookies())
            ->firstWhere(fn ($c) => $c->getName() === 'laravel_session');

        $this->assertNotNull($cookie);
        $this->assertSame('lax', strtolower((string) $cookie->getSameSite()),
            'SameSite=None must never apply outside the sandbox preview');
        $this->assertFalse($cookie->isSecure(),
            'a plain-HTTP deployment must not get Secure cookies');
        $this->assertFalse($cookie->isPartitioned(),
            'Partitioned must never apply outside the sandbox preview');
    }

    public function test_a_normal_host_does_not_get_its_scheme_forced(): void
    {
        $this->get('http://epcc.example.com/')
            ->assertRedirect('http://epcc.example.com/login');
    }

    // ---- the preview is always https to the browser --------------------

    /**
     * REGRESSION (white page, 2026-09-10).
     *
     * The proxy forwards over plain HTTP and sends no X-Forwarded-Proto, so
     * $request->isSecure() is false even though the BROWSER is on https. A
     * previous version inferred the transport from that and stopped forcing
     * the scheme, emitting http:// links into an https page. The browser
     * blocked them as mixed active content and rendered nothing.
     *
     * Mixed-content blocking only occurs on an https page, so the error the
     * browser reported is itself the proof that https must be forced.
     */
    public function test_https_is_forced_even_without_forwarded_headers(): void
    {
        // No X-Forwarded-Proto, exactly like the real proxy.
        $this->assertStringStartsWith(
            'https://'.self::ARENA.'/login',
            (string) $this->get('http://'.self::ARENA.'/')->headers->get('Location'),
        );
    }

    public function test_cookies_are_third_party_ready_without_forwarded_headers(): void
    {
        $cookie = collect($this->get('http://'.self::ARENA.'/login')->headers->getCookies())
            ->firstWhere(fn ($c) => $c->getName() === 'laravel_session');

        $this->assertNotNull($cookie);
        // Lax/Strict is refused outright in a cross-site context.
        $this->assertSame('none', strtolower((string) $cookie->getSameSite()));
        $this->assertTrue($cookie->isSecure(), 'SameSite=None requires Secure');
        $this->assertTrue($cookie->isPartitioned(), 'CHIPS: third-party cookies need Partitioned');
    }

    // ---- the app still works through the preview ----------------------

    public function test_login_works_over_the_preview_host(): void
    {
        $r = $this->post('http://'.self::SANDBOX.'/login', [
            'email' => 'karimi@nioc.example',
            'password' => 'password',
        ]);

        // Laravel emits the bare root without a trailing slash; what matters
        // is the scheme and that the host came from the request.
        $this->assertStringStartsWith(
            'https://'.self::SANDBOX,
            (string) $r->headers->get('Location'),
        );
        $this->assertAuthenticated();
    }
}
