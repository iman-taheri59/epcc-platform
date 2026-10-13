<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Http\Middleware\PreviewSessionFallback;
use App\Models\User;
use Database\Seeders\AuthSeeder;
use Database\Seeders\EpccDemoSeeder;
use Tests\TestCase;

/**
 * The preview must work even when the browser refuses the session cookie.
 *
 * The app is previewed in an iframe whose top-level document is a different
 * site, which makes its cookies third-party. A third-party cookie can be
 * refused for reasons the server cannot observe or satisfy: SameSite, Secure
 * vs transport, CHIPS/Partitioned, or simply the user's "block third-party
 * cookies" setting. Four rounds of cookie-attribute tuning failed against a
 * real browser precisely because the policy is not visible from here.
 *
 * So the dependency is removed rather than negotiated: a signed `sid` token
 * travels in the URL and is converted back into a real session cookie before
 * EncryptCookies runs. These tests exercise that path exclusively - no
 * browser cookies at all.
 */
final class CookielessPreviewTest extends TestCase
{
    /**
     * The proxy forwards to this app over PLAIN HTTP while the browser is on
     * https, and sends no X-Forwarded-Proto. Testing against an https:// URL
     * makes the test request secure by construction, which silently disables
     * the very condition these tests exist to cover - a mixed-content
     * regression then passes. Drive it over http, like the proxy does.
     */
    private const HOST = 'http://sbx-abc123.arena.site';

    private const PARAM = PreviewSessionFallback::PARAM;

    protected function setUp(): void
    {
        parent::setUp();
        $this->artisan('migrate:fresh', ['--force' => true]);
        $this->seed(EpccDemoSeeder::class);
        $this->seed(AuthSeeder::class);
    }

    public function test_the_login_form_carries_a_session_token_field(): void
    {
        // Without this the very first POST has no way to identify its session,
        // so the CSRF token it was issued belongs to a session nobody can find.
        $this->get(self::HOST.'/login')
            ->assertOk()
            ->assertSee('name="'.self::PARAM.'"', false);
    }

    public function test_login_succeeds_with_the_token_in_the_body_and_no_cookie(): void
    {
        $token = PreviewSessionFallback::token($this->app['session']->getId());

        $r = $this->post(self::HOST.'/login', [
            'email' => 'karimi@nioc.example',
            'password' => 'password',
            self::PARAM => $token,
        ]);

        // The redirect must go to the portfolio, NOT back to /login.
        $this->assertStringNotContainsString('/login', (string) $r->headers->get('Location'));
        $this->assertAuthenticated();
    }

    public function test_a_redirect_propagates_the_token_when_no_cookie_returned(): void
    {
        $r = $this->get(self::HOST.'/');

        // Unauthenticated: bounced to /login, and the token must ride along or
        // the next request starts a brand-new session.
        $this->assertStringContainsString(
            self::PARAM.'=',
            (string) $r->headers->get('Location'),
        );
    }

    public function test_rendered_links_and_forms_carry_the_token(): void
    {
        // Navigation has to keep the session alive too, not just login. The
        // login page has no relative links worth rewriting, so assert against
        // a real screen behind auth.
        $user = $this->userByEmail('karimi@nioc.example');

        $html = (string) $this->actingAs($user)->get(self::HOST.'/?lang=fa')->getContent();

        $this->assertMatchesRegularExpression(
            '/href="\/[^"]*'.self::PARAM.'=/',
            $html,
            'internal links must carry the session token when the cookie is refused',
        );

        $this->assertStringContainsString('name="'.self::PARAM.'"', $html);
    }

    public function test_dom_injected_links_are_patched_client_side(): void
    {
        // Livewire and Filament add links to the DOM after render; no
        // server-side rewrite can see those. The inline observer covers them -
        // and it must watch childList ONLY, or rewriting an href retriggers
        // the observer forever and the page goes white.
        $html = (string) $this->get(self::HOST.'/login')->getContent();

        $this->assertStringContainsString('MutationObserver', $html);
        $this->assertStringContainsString('childList:true', $html);
        $this->assertStringNotContainsString('attributes:true', $html);
    }

    public function test_a_forged_token_is_refused(): void
    {
        // A bare, unsigned session id in a query parameter would be a session
        // fixation hole: anyone could pin a victim's session to a known id.
        $id = str_repeat('a', 40);

        $this->assertNull(PreviewSessionFallback::verify($id));
        $this->assertNull(PreviewSessionFallback::verify($id.'.deadbeef'));
        $this->assertSame($id, PreviewSessionFallback::verify(PreviewSessionFallback::token($id)));
    }

    public function test_the_token_is_never_appended_to_a_foreign_host(): void
    {
        $html = (string) $this->get(self::HOST.'/login')->getContent();

        preg_match_all('/(?:href|src|action)="(https?:\/\/[^"]+)"/i', $html, $m);

        foreach ($m[1] ?? [] as $url) {
            $host = parse_url($url, PHP_URL_HOST) ?: '';

            if (! str_ends_with($host, '.arena.site') && ! str_ends_with($host, '.e2b.app')) {
                $this->assertStringNotContainsString(
                    self::PARAM.'=',
                    $url,
                    "session token leaked to foreign host: {$url}",
                );
            }
        }

        $this->addToAssertionCount(1);
    }

    public function test_a_returned_cookie_takes_precedence_over_the_url(): void
    {
        // The cookie is still the primary mechanism when it works.
        $real = $this->app['session']->getId();

        $this->withCookie(config('session.cookie'), $real)
            ->get(self::HOST.'/login?'.self::PARAM.'='.PreviewSessionFallback::token(str_repeat('b', 40)))
            ->assertOk();

        $this->assertSame($real, $this->app['session']->getId());
    }

    public function test_a_failed_login_redirects_over_https_and_keeps_the_input(): void
    {
        // redirect()->back() resolves from the session's _previous.url, which
        // StartSession writes from $request->fullUrl(). fullUrl() comes from
        // the CONNECTION, which the proxy leaves as http - URL::forceScheme()
        // does not touch it. The browser then blocks that http:// redirect
        // from an https:// page as mixed active content: blank page, form
        // apparently self-wiped. Exactly the reported symptom.
        // Visit the form FIRST. Without this the session has no _previous.url
        // and back() falls through to the URL generator, which forceScheme()
        // already fixes - so the test would pass with the fix mutated away.
        $this->get(self::HOST.'/login');

        $token = PreviewSessionFallback::token($this->app['session']->getId());

        $r = $this->post(self::HOST.'/login', [
            'email' => 'karimi@nioc.example',
            'password' => 'wrong-password',
            self::PARAM => $token,
        ]);

        $location = (string) $r->headers->get('Location');

        $this->assertStringStartsWith('https://', $location, 'mixed content blanks the preview');
        $this->assertStringContainsString(self::PARAM.'=', $location);
        $this->assertGuest();
    }

    public function test_the_intended_url_stashed_for_a_guest_is_https(): void
    {
        // The auth middleware stores the intended URL from fullUrl() too, so
        // the post-login redirect would be http:// and blocked the same way.
        $this->get(self::HOST.'/projects/PH-14');

        $intended = (string) session()->get('url.intended');

        if ($intended !== '') {
            $this->assertStringStartsWith('https://', $intended);
        }

        $this->addToAssertionCount(1);
    }

    public function test_the_fallback_is_off_for_a_normal_deployment(): void
    {
        // A session token in a URL is weaker than an HttpOnly cookie (Referer,
        // history, shared links). It must never be on outside the preview.
        $html = (string) $this->get('http://epcc.example.com/login')->getContent();

        $this->assertFalse(config('epcc.session_url_fallback'));
        $this->assertStringNotContainsString('name="'.self::PARAM.'"', $html);
        $this->assertStringNotContainsString('MutationObserver', $html);
    }
}
