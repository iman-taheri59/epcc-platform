<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

/**
 * AG-06: the pre-flight air-gap check, run at customer acceptance.
 *
 * The customer's datacentre has no route to the internet. A component that
 * reaches for a CDN there does not degrade gracefully - it blocks on DNS and
 * then on connect, and the page hangs for the length of the browser timeout.
 * That is the failure this command exists to catch BEFORE the acceptance test,
 * because on site it looks like "your software is broken", not "your software
 * wanted a font".
 *
 * The scan is deliberately static. It reads what is on disk rather than
 * watching traffic, because the dangerous case is a code path that is never
 * exercised in our network-connected sandbox but is the first thing a user
 * touches on site (see the ->font() defect: no test looked at the network).
 *
 * ---------------------------------------------------------------------------
 * SEVERITY - the whole value of this command is in the distinction.
 * ---------------------------------------------------------------------------
 * A grep for "https://" over a vendor tree returns dozens of hits and every
 * one of them is noise until it is classified. A licence header, a docblock
 * @see, an XML namespace and a <script src> all match the same pattern and
 * only the last one hangs a browser in Asaluyeh.
 *
 *   BLOCKER  the asset is fetched as part of rendering a page - a src=, href=
 *            on a stylesheet, an @import, or a CSS url(). This WILL hang.
 *   LATENT   the URL sits in a shipped file on a code path we do not enable
 *            (Pusher's CDN inside echo.js, which is only emitted when
 *            broadcasting is on). Harmless today, a BLOCKER the moment
 *            somebody flips the feature on, so it is recorded, not ignored.
 *   BENIGN   a comment, licence text, XML namespace, or documentation link.
 *            Never fetched. Not reported except under -v.
 *
 * Exit code is 1 only for BLOCKER. Latent findings are printed and pass,
 * because a command that fails on things the customer cannot act on is a
 * command the customer learns to ignore.
 */
final class VerifyAirgap extends Command
{
    protected $signature = 'verify:airgap
                            {--json : Machine-readable output for the acceptance report}
                            {--strict : Treat LATENT findings as failures too}';

    protected $description = 'AG-06 pre-flight: scan shipped assets for anything that would be fetched from the internet';

    /**
     * Hosts that are ours or are not real fetches. Loopback is fine - the
     * install talks to itself.
     */
    private const ALLOWED_HOSTS = [
        '127.0.0.1', 'localhost', '0.0.0.0', '::1',
        'www.w3.org',          // XML/SVG namespaces, never dereferenced
        'schemas.openxmlformats.org',
        'schema.org',
    ];

    /**
     * Contexts that cause a real fetch when the page renders. Anything that
     * matches one of these is a BLOCKER.
     */
    private const FETCHING_CONTEXTS = [
        '/<script[^>]+src\s*=\s*["\']?(https?:\/\/[^"\'\s>]+)/i',
        '/<link[^>]+href\s*=\s*["\']?(https?:\/\/[^"\'\s>]+)/i',
        '/<img[^>]+src\s*=\s*["\']?(https?:\/\/[^"\'\s>]+)/i',
        '/@import\s+(?:url\()?["\']?(https?:\/\/[^"\'\s)]+)/i',
        '/url\(\s*["\']?(https?:\/\/[^"\'\s)]+)/i',
        // JS that builds a <link>/<script> at runtime, which is how the
        // markdown editor pulls Font Awesome - invisible to a markup grep.
        '/\.href\s*=\s*["\'](https?:\/\/[^"\']+)/i',
        '/\.src\s*=\s*["\'](https?:\/\/[^"\']+)/i',
        '/fetch\(\s*["\'](https?:\/\/[^"\']+)/i',

        /*
         * A CDN base held in a config property and concatenated later. This
         * pattern exists because the first version of this command MISSED
         * Pusher: echo.js carries cdn_https:"https://js.pusher.com" and builds
         * the script URL at call time, so there is no literal src="https://..."
         * anywhere in the file. A scanner that only understands complete URLs
         * in fetching position gives a clean bill of health to every dynamic
         * script loader - which is precisely the code most likely to hang an
         * air-gapped install, because it fetches lazily and only in
         * production.
         */
        '/\b(?:cdn|cdn_http|cdn_https|cdnUrl|baseUrl|base_url|host|endpoint|origin)\b\s*[:=]\s*["\'](https?:\/\/[^"\']+)["\']/i',
    ];

    /** Where a shipped asset can hide. */
    private const SCAN_ROOTS = ['public', 'resources'];

    public function handle(): int
    {
        $base = base_path();
        $findings = [];

        foreach (self::SCAN_ROOTS as $root) {
            $dir = $base.DIRECTORY_SEPARATOR.$root;
            if (! is_dir($dir)) {
                continue;
            }

            foreach (File::allFiles($dir) as $file) {
                if (! $this->isTextAsset($file->getExtension())) {
                    continue;
                }

                $contents = @file_get_contents($file->getPathname());
                if ($contents === false || $contents === '') {
                    continue;
                }

                $relative = str_replace($base.DIRECTORY_SEPARATOR, '', $file->getPathname());
                foreach ($this->scan($contents, $relative) as $finding) {
                    $findings[] = $finding;
                }
            }
        }

        $findings = $this->deduplicate($findings);
        $blockers = array_values(array_filter($findings, fn ($f) => $f['severity'] === 'BLOCKER'));
        $latent = array_values(array_filter($findings, fn ($f) => $f['severity'] === 'LATENT'));

        if ($this->option('json')) {
            $this->line((string) json_encode([
                'passed' => $blockers === [] && (! $this->option('strict') || $latent === []),
                'blockers' => $blockers,
                'latent' => $latent,
                'scanned_roots' => self::SCAN_ROOTS,
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return $this->verdict($blockers, $latent);
        }

        $this->newLine();
        $this->line('  <options=bold>AG-06 air-gap pre-flight</>');
        $this->line('  <fg=gray>Scanning '.implode(', ', self::SCAN_ROOTS).' for assets fetched from the internet.</>');
        $this->newLine();

        if ($blockers !== []) {
            $this->line('  <fg=red;options=bold>BLOCKER</> <fg=gray>- fetched while rendering; will hang with no internet</>');
            foreach ($blockers as $f) {
                $this->line(sprintf('    <fg=red>%s</>', $f['host']));
                $this->line(sprintf('      <fg=gray>%s</>', $f['file']));
                $this->line(sprintf('      <fg=gray>%s</>', $this->truncate($f['url'])));
            }
            $this->newLine();
        }

        if ($latent !== []) {
            $this->line('  <fg=yellow;options=bold>LATENT</> <fg=gray>- shipped but not on an enabled code path</>');
            foreach ($latent as $f) {
                $this->line(sprintf('    <fg=yellow>%s</>', $f['host']));
                $this->line(sprintf('      <fg=gray>%s</>', $f['file']));
                $this->line(sprintf('      <fg=gray>reason: %s</>', self::LATENT_ASSETS[str_replace('\\', '/', $f['file'])] ?? 'unknown'));
            }
            $this->newLine();
        }

        if ($blockers === [] && $latent === []) {
            $this->line('  <fg=green;options=bold>PASS</> <fg=gray>- no external asset reference found.</>');
            $this->newLine();

            return self::SUCCESS;
        }

        if ($blockers === []) {
            $this->line(sprintf(
                '  <fg=green;options=bold>PASS</> <fg=gray>- 0 blockers, %d latent. Safe to install air-gapped.</>',
                count($latent)
            ));
            $this->line('  <fg=gray>Latent findings are recorded in the acceptance report. Re-check them</>');
            $this->line('  <fg=gray>before enabling any feature listed above.</>');
        } else {
            $this->line(sprintf(
                '  <fg=red;options=bold>FAIL</> <fg=gray>- %d blocker(s). Do NOT ship this build to an air-gapped site.</>',
                count($blockers)
            ));
        }
        $this->newLine();

        return $this->verdict($blockers, $latent);
    }

    /**
     * @return list<array{host:string,url:string,file:string,severity:string}>
     */
    private function scan(string $contents, string $relative): array
    {
        $out = [];

        foreach (self::FETCHING_CONTEXTS as $pattern) {
            if (preg_match_all($pattern, $contents, $matches, PREG_SET_ORDER) === false) {
                continue;
            }

            foreach ($matches as $match) {
                $url = $match[1] ?? '';
                $host = $this->host($url);
                if ($host === null || $this->isAllowed($host)) {
                    continue;
                }

                $out[] = [
                    'host' => $host,
                    'url' => $url,
                    'file' => $relative,
                    'severity' => $this->severity($relative),
                ];
            }
        }

        return $out;
    }

    /**
     * Assets that Filament publishes but this product never emits, with the
     * reason each one stays unreachable. A path may only sit here if no page
     * loads it - AirgapVerificationTest fetches every panel screen and fails
     * if one of these filenames appears, so the list cannot quietly rot into
     * a set of excuses.
     *
     * @var array<string,string>
     */
    private const LATENT_ASSETS = [
        // Pusher's CDN, hardcoded in Filament's Echo bundle. BROADCAST_CONNECTION
        // is 'log' and no panel enables Echo, so the file is never served.
        // Registered ->core() by Filament, so it IS served on every panel
        // page - it is latent because Echo is never initialised without a
        // broadcaster, so the Pusher CDN string is never reached. Verified by
        // a browser net-log capture against /admin, not by assumption: an
        // earlier version of this comment claimed the file was not served at
        // all, and AirgapVerificationTest proved that wrong.
        'public/js/filament/filament/echo.js' => 'served but inert: Echo is never initialised (BROADCAST_CONNECTION=log)',

        // EasyMDE self-injects a Font Awesome <link> unless
        // autoDownloadFontAwesome is false. We register no MarkdownEditor or
        // RichEditor field anywhere, so the bundle is never loaded.
        // IF YOU ADD ONE: pass autoDownloadFontAwesome => false, or this
        // becomes a BLOCKER and the site install will hang on first render.
        'public/js/filament/forms/components/markdown-editor.js' => 'no MarkdownEditor/RichEditor field is registered',
    ];

    /**
     * A reference is LATENT when it lives in an asset we never emit, BLOCKER
     * when it is on a live path. Everything defaults to BLOCKER: an unknown
     * external reference is guilty until someone documents why it is not.
     */
    private function severity(string $relative): string
    {
        $normalised = str_replace('\\', '/', $relative);

        return isset(self::LATENT_ASSETS[$normalised]) ? 'LATENT' : 'BLOCKER';
    }

    /** @return array<string,string> */
    public static function latentAssets(): array
    {
        return self::LATENT_ASSETS;
    }

    private function host(string $url): ?string
    {
        $host = parse_url(trim($url), PHP_URL_HOST);

        return is_string($host) && $host !== '' ? strtolower($host) : null;
    }

    private function isAllowed(string $host): bool
    {
        return in_array($host, self::ALLOWED_HOSTS, true);
    }

    private function isTextAsset(string $extension): bool
    {
        return in_array(strtolower($extension), ['js', 'css', 'html', 'php', 'vue', 'blade', 'mjs'], true);
    }

    /**
     * One line per host+file. A minified bundle can name the same CDN thirty
     * times and thirty identical lines teach the reader nothing.
     *
     * @param  list<array{host:string,url:string,file:string,severity:string}>  $findings
     * @return list<array{host:string,url:string,file:string,severity:string}>
     */
    private function deduplicate(array $findings): array
    {
        $seen = [];
        foreach ($findings as $f) {
            $seen[$f['host'].'|'.$f['file']] ??= $f;
        }

        return array_values($seen);
    }

    /**
     * @param  list<array<string,string>>  $blockers
     * @param  list<array<string,string>>  $latent
     */
    private function verdict(array $blockers, array $latent): int
    {
        if ($blockers !== []) {
            return self::FAILURE;
        }

        return $this->option('strict') && $latent !== [] ? self::FAILURE : self::SUCCESS;
    }

    private function truncate(string $url, int $max = 96): string
    {
        return mb_strlen($url) > $max ? mb_substr($url, 0, $max).'...' : $url;
    }
}
