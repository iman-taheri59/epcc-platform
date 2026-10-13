<?php

declare(strict_types=1);

namespace App\Services;

use RuntimeException;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Process;

/**
 * HTML -> PDF via headless Chrome (ADR: mPDF's own maintainers point users here
 * for CSS fidelity, and Persian shaping is the whole ballgame - see IR-R2).
 *
 * Why a browser rather than a PHP PDF library:
 *
 *   Persian is a joining script. Every letter has up to four contextual forms,
 *   ZWNJ has to be honoured (پیش‌نویس is NOT پیشنویس), and Latin document
 *   numbers sit inside RTL sentences under the bidi algorithm. HarfBuzz gets
 *   this right. PHP PDF libraries mostly do not, and when they fail they fail
 *   SILENTLY - a plausible-looking page with disconnected letters that only a
 *   Persian reader notices, usually after it has been issued to a client.
 *
 * This class is deliberately dumb: it takes finished HTML and returns PDF bytes.
 * It knows nothing about certificates or transmittals, so the documents stay
 * ordinary Blade views that can be previewed in a browser.
 */
final class PdfRenderer
{
    /** Candidate binaries, in preference order. */
    private const BINARIES = [
        'chromium',
        'chromium-browser',
        'google-chrome',
        'google-chrome-stable',
        'headless-shell',
    ];

    /**
     * @param list<string>|null $binaries override the search list; an operator
     *                                    can point at a custom Chrome path, and
     *                                    tests can simulate a host without one
     */
    public function __construct(
        private readonly int $timeoutSeconds = 30,
        private readonly ?array $binaries = null,
    ) {
    }

    /** Is PDF export available on this host? */
    public function isAvailable(): bool
    {
        return $this->binary() !== null;
    }

    public function binary(): ?string
    {
        foreach ($this->binaries ?? self::BINARIES as $candidate) {
            $path = trim((string) shell_exec('command -v '.escapeshellarg($candidate).' 2>/dev/null'));

            if ($path !== '' && is_executable($path)) {
                return $path;
            }
        }

        return null;
    }

    /**
     * Render finished HTML to PDF bytes.
     *
     * @throws RuntimeException when the renderer is missing, times out, or
     *                          produces something that is not a PDF
     */
    public function render(string $html): string
    {
        $binary = $this->binary();

        if ($binary === null) {
            // Loud, specific, and actionable. A silent fallback to a text file
            // would let an unrenderable certificate reach a client.
            throw new RuntimeException(
                'No headless browser found for PDF export. Install one of: '
                .implode(', ', self::BINARIES).'. On Debian/Ubuntu: '
                .'sudo apt-get install -y chromium fonts-vazirmatn'
            );
        }

        $dir = sys_get_temp_dir().'/epcc-pdf-'.bin2hex(random_bytes(8));

        if (! mkdir($dir, 0700) && ! is_dir($dir)) {
            throw new RuntimeException("Could not create a scratch directory at {$dir}.");
        }

        $source = $dir.'/document.html';
        $target = $dir.'/document.pdf';

        try {
            file_put_contents($source, $html);

            $process = new Process([
                $binary,
                '--headless',
                '--disable-gpu',
                // The sandbox cannot be used as root in a container, and this
                // process only ever renders OUR OWN markup from a local file.
                '--no-sandbox',
                '--disable-dev-shm-usage',
                // Air-gap posture (AG-*): the renderer must never reach out.
                // Documents embed their own CSS and fonts by design.
                '--disable-extensions',
                '--disable-background-networking',
                '--no-first-run',
                '--no-pdf-header-footer',
                '--print-to-pdf='.$target,
                'file://'.$source,
            ], null, ['HOME' => $dir], null, (float) $this->timeoutSeconds);

            try {
                $process->run();
            } catch (ProcessTimedOutException) {
                throw new RuntimeException(
                    "PDF rendering timed out after {$this->timeoutSeconds}s. "
                    .'A document that large should be paginated or exported asynchronously.'
                );
            }

            if (! is_file($target)) {
                throw new RuntimeException(
                    'The PDF renderer produced no file. '.trim($process->getErrorOutput())
                );
            }

            $bytes = (string) file_get_contents($target);

            /*
             * Verify it IS a PDF. Chrome can exit 0 having written a truncated
             * or empty file, and shipping that to a client as "the certificate"
             * is worse than an error page.
             */
            if (strncmp($bytes, '%PDF-', 5) !== 0) {
                throw new RuntimeException('The PDF renderer produced a file that is not a PDF.');
            }

            if (strlen($bytes) < 1000) {
                throw new RuntimeException(
                    'The PDF renderer produced a suspiciously small file ('.strlen($bytes).' bytes).'
                );
            }

            return $bytes;
        } finally {
            /*
             * Recursive, because Chrome treats the scratch HOME as a real one
             * and mints .cache/.config/.local inside it. A plain rmdir() fails
             * silently on a non-empty directory, so every export used to leave
             * a stray tree in /tmp - a slow leak that only shows up on a busy
             * server months later.
             */
            $this->deleteTree($dir);
        }
    }

    private function deleteTree(string $dir): void
    {
        if (! is_dir($dir)) {
            return;
        }

        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );

        foreach ($items as $item) {
            $item->isDir() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
        }

        @rmdir($dir);
    }
}
