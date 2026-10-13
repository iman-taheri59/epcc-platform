<?php

declare(strict_types=1);

namespace Tests\Feature;

use Database\Seeders\AuthSeeder;
use Database\Seeders\EpccDemoSeeder;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Http\Request;
use Illuminate\Session\TokenMismatchException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

/**
 * A CSRF failure on the login form must SAY something.
 *
 * Reported symptom: "I type my username and password, press the button, and
 * nothing happens - the fields just go blank." That is exactly what a 419
 * looks like to a user: Laravel returns "Page Expired", the browser re-renders
 * the login form, and every field is empty. Nothing is written to
 * laravel.log, because a 419 is an ordinary HTTP response, not an error.
 *
 * The trigger here was the session cookie being dropped by the browser (see
 * PreviewProxyTest for the cross-site cookie rules), but the trigger is almost
 * beside the point: ANY lost session produces the same silent, unexplainable
 * failure. So the failure itself is made legible.
 *
 * WHY THESE TESTS CALL THE HANDLER DIRECTLY
 * Laravel's CSRF middleware short-circuits on `runningUnitTests()` (see
 * PreventRequestForgery::handle), so a normal $this->post() can NEVER produce
 * a token mismatch - not even with withMiddleware(). A green HTTP suite is
 * therefore no evidence at all here. The exception handler is exercised
 * directly instead, which is the code that actually had to change.
 */
final class LoginCsrfFailureTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->artisan('migrate:fresh', ['--force' => true]);
        $this->seed(EpccDemoSeeder::class);
        $this->seed(AuthSeeder::class);
    }

    /** Render a 419 the way the framework does, through the app's handler. */
    private function render(string $uri, array $input = [])
    {
        $request = Request::create($uri, 'POST', $input);
        $request->setLaravelSession($this->app['session.store']);

        // Laravel maps TokenMismatchException onto HttpException(419) in
        // prepareException() before user render callbacks run. Reproduce that
        // mapping so the test exercises the same object the handler sees.
        $e = new HttpException(419, 'CSRF token mismatch.', new TokenMismatchException());

        return $this->app->make(ExceptionHandler::class)->render($request, $e);
    }

    public function test_a_bad_csrf_token_redirects_back_instead_of_a_bare_419(): void
    {
        $response = $this->render('https://8000-abc.e2b.app/login', [
            'email' => 'karimi@nioc.example',
            'password' => 'password',
        ]);

        $this->assertSame(302, $response->getStatusCode());
        $this->assertStringEndsWith('/login', $response->headers->get('Location'));
    }

    public function test_the_message_is_readable_and_persian_by_default(): void
    {
        $this->render('https://8000-abc.e2b.app/login', ['email' => 'karimi@nioc.example']);

        $message = (string) session('errors')->first('email');

        $this->assertStringContainsString('نشست', $message);
        $this->assertStringContainsString('تازه', $message);
    }

    public function test_the_message_is_bilingual(): void
    {
        $response = $this->render('https://8000-abc.e2b.app/login?lang=en', [
            'email' => 'karimi@nioc.example',
        ]);

        $this->assertStringEndsWith('/login?lang=en', $response->headers->get('Location'));
        $this->assertStringContainsString(
            'session expired',
            strtolower((string) session('errors')->first('email')),
        );
    }

    public function test_the_email_is_preserved_so_the_form_is_not_blank(): void
    {
        // "the fields just go blank" is half the complaint. Retyping an email
        // after every expiry is the part users actually hate.
        $this->render('https://8000-abc.e2b.app/login', [
            'email' => 'karimi@nioc.example',
            'password' => 'password',
        ]);

        $this->assertSame('karimi@nioc.example', session('_old_input')['email'] ?? null);
    }

    public function test_the_password_is_never_flashed_back(): void
    {
        $this->render('https://8000-abc.e2b.app/login', [
            'email' => 'karimi@nioc.example',
            'password' => 'hunter2',
        ]);

        $this->assertArrayNotHasKey('password', session('_old_input') ?? []);
    }

    public function test_csrf_failures_outside_login_stay_a_419(): void
    {
        // Scoped to /login on purpose: elsewhere a 419 should remain a 419
        // rather than silently redirecting somewhere.
        $response = $this->render('https://8000-abc.e2b.app/logout');

        $this->assertSame(419, $response->getStatusCode());
    }

    public function test_the_login_view_actually_renders_the_error(): void
    {
        // The handler flashing an error is worthless if the Blade template
        // never prints it.
        $this->withSession(['errors' => new \Illuminate\Support\MessageBag([
            'email' => ['نشست شما منقضی شد. لطفاً صفحه را تازه کنید.'],
        ])])->get('/login')->assertSee('نشست شما منقضی شد', false);
    }
}
