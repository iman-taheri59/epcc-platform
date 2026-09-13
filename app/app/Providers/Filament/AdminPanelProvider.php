<?php

namespace App\Providers\Filament;

use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Pages\Dashboard;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Support\Colors\Color;
use App\Http\Middleware\BindTenant;
use App\Http\Middleware\SetUserLocale;
use Filament\Widgets\AccountWidget;
use Filament\Widgets\FilamentInfoWidget;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\View\Middleware\ShareErrorsFromSession;

/**
 * The Filament admin panel.
 *
 * TENANCY IS THE LOAD-BEARING PART OF THIS FILE.
 *
 * Filament builds its own middleware stack; the generated one knows nothing
 * about our tenancy. Standing rule 1 (docs/Spike-RLS-Results.md) is that
 * `SET LOCAL` outside a transaction is silently discarded, so a panel without
 * BindTenant does not merely lose isolation - it never establishes it.
 *
 * What that looked like in practice, measured rather than assumed:
 * the first run of FilamentPanelTest showed the panel rendering an EMPTY
 * document list for a signed-in contractor. RLS was failing CLOSED (no GUC
 * set => no rows match), so the "does not leak across tenants" test passed
 * while the panel was simply broken. Only the mirror test - "still shows the
 * user's own rows" - could tell the two apart. Never assert isolation without
 * also asserting visibility.
 */
class AdminPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->default()
            ->id('admin')
            ->path('admin')
            ->login()
            ->brandName(__('epcc.panel.brand'))
            ->colors([
                'primary' => Color::Amber,
            ])
            /*
             * The launch market is Iran, so the panel is Persian and RTL by
             * default - not as a translation layer over an English original.
             * Filament reads the app locale for direction, so this must be set
             * before the panel renders; see EpccServiceProvider.
             *
             * AIR-GAP: Filament's ->font() helper fetches from Google Fonts
             * (bunny.net) by default, which is a hard violation of AG-01..AG-06
             * and would simply render nothing in a customer datacentre with no
             * outbound route. The hand-written screens already rely on the
             * OS-installed Vazirmatn (fonts-vazirmatn, also what the PDF
             * renderer uses), so the panel does the same via a local provider.
             */
            ->font('Vazirmatn', provider: \Filament\FontProviders\LocalFontProvider::class)
            /*
             * AIR-GAP, same class of defect as ->font() above and found the
             * same way - by watching a real browser instead of reading code.
             * Filament's default avatar provider returns a ui-avatars.com URL
             * built from the user's name, rendered into the user menu on every
             * admin page. Two problems: it cannot be fetched in an air-gapped
             * datacentre (AG-01/AG-02), and it puts a named employee into a
             * request to a third party. Ours draws initials as an inline SVG.
             */
            ->defaultAvatarProvider(\App\Support\LocalAvatarProvider::class)
            ->discoverResources(in: app_path('Filament/Resources'), for: 'App\Filament\Resources')
            ->discoverPages(in: app_path('Filament/Pages'), for: 'App\Filament\Pages')
            ->pages([
                Dashboard::class,
            ])
            ->discoverWidgets(in: app_path('Filament/Widgets'), for: 'App\Filament\Widgets')
            ->widgets([
                AccountWidget::class,
                /*
                 * FilamentInfoWidget is deliberately NOT registered. It puts
                 * Filament's own branding, version number and outbound links
                 * on the dashboard of a system we license to a NOC affiliate.
                 * Two reasons to drop it: the customer's admin screen should
                 * carry the customer's product, not our framework's; and
                 * publishing the exact framework version to every operator
                 * hands an attacker the CVE list for free.
                 */
            ])
            ->middleware([
                EncryptCookies::class,
                AddQueuedCookiesToResponse::class,
                StartSession::class,
                AuthenticateSession::class,
                ShareErrorsFromSession::class,
                PreventRequestForgery::class,
                SubstituteBindings::class,
                DisableBladeIconComponents::class,
                DispatchServingFilamentEvent::class,
                // Persian by default, from users.locale. Must run before the
                // panel renders, since Filament resolves labels through the
                // translator at render time.
                SetUserLocale::class,
                // Wraps the request in a transaction and binds the signed-in
                // user's company as a PostgreSQL GUC so RLS applies. Last in
                // the stack: it needs the authenticated user, which only
                // exists after the session middleware above has run.
                BindTenant::class,
            ])
            ->authMiddleware([
                Authenticate::class,
            ]);
    }
}
