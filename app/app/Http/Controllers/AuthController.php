<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\User;
use App\Support\TenantContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

/** Gate 1 of the ARC §5 pipeline. */
final class AuthController extends Controller
{
    public function showLogin(Request $request)
    {
        $lang = $request->query('lang', 'fa') === 'en' ? 'en' : 'fa';

        return view('auth.login', [
            'lang' => $lang,
            'dir' => $lang === 'fa' ? 'rtl' : 'ltr',
            // Demo affordance: the seeded accounts, so the screen is usable
            // without a password reset flow. Removed before production.
            // Reading users is an identity lookup by definition: this screen
            // is shown to someone who has no session and therefore no tenant.
            'demoUsers' => app(TenantContext::class)->forIdentityLookup(
                fn () => User::with('company')->where('is_active', true)->orderBy('email')->get()
            ),
        ]);
    }

    public function login(Request $request): RedirectResponse
    {
        $credentials = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);

        // Auth::attempt reads the user row to compare the password hash, and
        // the caller is by definition not yet a tenant of anything.
        $attempt = app(TenantContext::class)->forIdentityLookup(
            fn (): bool => Auth::attempt($credentials, $request->boolean('remember'))
        );

        if (! $attempt) {
            throw ValidationException::withMessages([
                'email' => __('auth.failed'),
            ]);
        }

        $user = Auth::user();

        // A disabled account must not hold a session even with a valid
        // password - gate 1 is not only "is the password right".
        if (! $user->is_active) {
            Auth::logout();
            $request->session()->invalidate();

            throw ValidationException::withMessages([
                'email' => 'This account has been disabled. / این حساب کاربری غیرفعال شده است.',
            ]);
        }

        $request->session()->regenerate();

        return redirect()->intended('/');
    }

    public function logout(Request $request): RedirectResponse
    {
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect('/login');
    }
}
