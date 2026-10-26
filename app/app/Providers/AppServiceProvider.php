<?php

namespace App\Providers;

use App\Support\TenantAwareUserProvider;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use App\Support\LocalisedNumberTranslator;
use Illuminate\Translation\Translator;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        /*
         * Swap the translator for one that localises numeric parameters.
         *
         * Filament's own chrome ("showing :first to :last of :total results",
         * the active-filter count) was rendering Latin digits inside Persian
         * sentences while our columns rendered Persian ones. Extending the
         * translator fixes every resource at once, including strings that
         * live inside the package. Registered here, not in boot(), because
         * the container resolves 'translator' during boot.
         */
        $this->app->extend('translator', function (Translator $translator, $app): Translator {
            $replacement = new LocalisedNumberTranslator($translator->getLoader(), $translator->getLocale());
            $replacement->setFallback($translator->getFallback());

            return $replacement;
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        /*
         * Resolve authenticated identities through the identity hatch.
         *
         * `users` carries RLS (migration 2026_09_12_100000), so reading the
         * session's user needs a bound tenant - but the tenant is only known
         * once that user has been read. Without this override the guard
         * resolves NULL and every signed-in request is treated as anonymous:
         * concretely, every /admin route redirected to the login screen while
         * the session was valid, because Filament resolves the user before
         * BindTenant runs.
         *
         * Overriding the PROVIDER rather than a middleware covers every entry
         * point at once - session guard, panel, Auth::attempt, remember-me -
         * and keeps the bypass in one greppable place. See
         * TenantAwareUserProvider and TenantContext::forIdentityLookup().
         *
         * Laravel 13 ships no config/auth.php here, so the default 'users'
         * provider name is what the guard asks for; registering under that
         * name replaces it.
         */
        Auth::provider('eloquent', fn ($app, array $config) => new TenantAwareUserProvider(
            $app['hash'],
            $config['model'],
        ));

        /*
         * Gate 1 credential stuffing guard: bound POST /login per IP+email.
         * The attempt handler clears the limiter on success, so honest users
         * are never throttled by their own login. See config('epcc.auth').
         */
        RateLimiter::for('login', function (Request $request) {
            $perMinute = max(1, (int) config('epcc.auth.login_throttle_per_minute', 10));

            return Limit::perMinute($perMinute)->by($request->ip().'|'.$request->input('email'));
        });
    }
}
