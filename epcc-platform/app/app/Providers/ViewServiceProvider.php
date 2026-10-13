<?php

declare(strict_types=1);

namespace App\Providers;

use App\Support\Navigation;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\View as ViewFacade;
use Illuminate\Support\ServiceProvider;

/**
 * Feeds the sidebar to every screen that renders the app shell.
 *
 * A view composer rather than six controller edits: the sidebar is chrome, not
 * data. Adding it to each controller's view() call would mean every future
 * screen has to remember to include it, and the one that forgets renders a
 * page with no way out of it - which is precisely the bug being fixed here.
 *
 * Registered per view name, so /login and /gate (which have no shell, no
 * tenant and sometimes no user) never pay for it.
 */
final class ViewServiceProvider extends ServiceProvider
{
    /**
     * Views that render the authenticated app shell.
     *
     * A hand-maintained list is a trap: the sidebar partial needs `$nav`, so a
     * new screen that includes it but is missing here dies with "Undefined
     * variable $nav" - which is exactly what happened when the document
     * register landed. The composer is therefore ALSO registered against the
     * partial itself below, which cannot be forgotten because including it is
     * what creates the need. This list stays only so the top-level views get
     * `$plan` and `$langSwitchUrl` too.
     */
    private const SHELL_VIEWS = [
        'portfolio', 'certificates', 'projects.create', 'projects.team', 'documents.index',
        'transmittals.index', 'punch.index',
        'materials.warehouse', 'materials.procurement',
        // Renders the shell only when signed in; the composer returns early
        // for a guest, which is exactly what the view expects.
        'gate-demo',
    ];

    public function boot(): void
    {
        // Bind to the partial as well: any view that includes the sidebar gets
        // the data the sidebar needs, by construction.
        ViewFacade::composer(
            array_merge(self::SHELL_VIEWS, ['partials.sidebar', 'partials.topbar']),
            function (View $view): void {
            $request = request();
            $user = $request->user();

            if ($user === null) {
                return;
            }

            $data = $view->getData();
            $lang = $data['lang'] ?? 'fa';

            /*
             * The sidebar reads $user and $acting. Every shell controller used
             * to pass them by hand, which is a contract nothing enforced - a
             * new screen that forgot them rendered a 500 from inside the
             * partial. Supplied here as DEFAULTS so the partial is
             * self-sufficient; a controller that passes its own still wins,
             * because withDefault-style merging keeps existing view data.
             */
            $existing = $view->getData();

            $view->with([
                'user' => $existing['user'] ?? $user,
                'acting' => $existing['acting'] ?? $user->company,
                'nav' => $this->app->make(Navigation::class)->build(
                    $user,
                    $lang,
                    '/'.ltrim($request->path(), '/'),
                    $data['project'] ?? null,
                ),
                'plan' => $user->company->plan,
                'langSwitchUrl' => $this->langSwitchUrl($lang),
            ]);
            },
        );
    }

    /**
     * Query parameters that may survive a language switch.
     *
     * An ALLOWLIST, not a passthrough. Echoing the whole query string back
     * into an href reflects arbitrary user input into the page - and it
     * resurrects `?as=`, the tenant-switch parameter that was deliberately
     * killed when auth landed. `resolveActing()` ignores it, but printing
     * "EPC-SAHAND" into a link on a page belonging to EPC-PARS makes a dead
     * control look alive and reads like a cross-tenant leak.
     *
     * `sid` is absent on purpose: PreviewUrlRewriter appends it to every
     * relative href on the way out, so carrying it here would duplicate it.
     *
     * Add new parameters (filters, pagination) explicitly as they land.
     */
    private const CARRIED_QUERY = ['page', 'q'];

    /**
     * The current URL with `lang` flipped, carrying only allowlisted params.
     */
    private function langSwitchUrl(string $lang): string
    {
        $request = request();

        $query = array_intersect_key(
            $request->query(),
            array_flip(self::CARRIED_QUERY),
        );

        $query['lang'] = $lang === 'fa' ? 'en' : 'fa';

        return '/'.ltrim($request->path(), '/').'?'.http_build_query($query);
    }
}
