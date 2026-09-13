<?php

declare(strict_types=1);

namespace App\Support;

use Filament\AvatarProviders\Contracts\AvatarProvider;
use Filament\Facades\Filament;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;

/**
 * AG-01 / AG-02: initials avatars drawn locally, as an inline SVG data URI.
 *
 * Filament's default is UiAvatarsProvider, which returns a URL to
 * ui-avatars.com built from the user's name. That URL is rendered into the
 * user menu of EVERY admin page. In our sandbox it resolves and nobody
 * notices; inside the customer's network it is a request that cannot be
 * routed, and the browser sits on it until the connect timeout expires.
 *
 * It also leaks: the employee's real name is URL-encoded into a request to a
 * third party. For a NIOC affiliate's project staff list that is a
 * confidentiality problem on its own, quite apart from the air gap.
 *
 * Found by capturing a real browser's network log against /admin, not by
 * reading code - the static scanner was clean because the URL is assembled
 * in PHP at request time, so no shipped file contains the literal string.
 *
 * The output is a data URI, so there is no second request of any kind, no
 * disk cache to warm and nothing to pre-seed in the installer bundle.
 */
final class LocalAvatarProvider implements AvatarProvider
{
    /**
     * Deterministic palette. The same person keeps the same colour across
     * sessions and across installs, which is what makes an avatar readable
     * as identity rather than decoration. Chosen to stay legible against
     * white text and to sit with the panel's amber.
     *
     * @var list<string>
     */
    private const PALETTE = [
        '#b45309', '#0f766e', '#1d4ed8', '#7e22ce',
        '#be123c', '#4d7c0f', '#0369a1', '#c2410c',
    ];

    public function get(Model|Authenticatable $record): string
    {
        $name = trim((string) Filament::getNameForDefaultAvatar($record));
        $initials = $this->initials($name);
        $background = self::PALETTE[$this->bucket($name)];

        // Persian initials need a font stack that actually has the glyphs;
        // Vazirmatn is installed on the host for the PDF pipeline anyway.
        $svg = <<<SVG
            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 64 64" width="64" height="64">
            <rect width="64" height="64" fill="{$background}"/>
            <text x="32" y="33" fill="#ffffff" font-size="26" font-weight="600"
                  font-family="Vazirmatn, Tahoma, DejaVu Sans, sans-serif"
                  text-anchor="middle" dominant-baseline="central">{$initials}</text>
            </svg>
            SVG;

        $svg = preg_replace('/\s+/', ' ', trim($svg)) ?? $svg;

        return 'data:image/svg+xml;base64,'.base64_encode($svg);
    }

    /**
     * First letter of the first two words. Persian names are frequently a
     * given name plus a single family name, so two is the useful maximum.
     */
    private function initials(string $name): string
    {
        if ($name === '') {
            return '?';
        }

        $letters = [];

        foreach (preg_split('/\s+/u', $name) ?: [] as $segment) {
            // Drop leading punctuation so a "[SYSTEM] Admin" style service
            // account does not get "[" as its initial.
            $clean = preg_replace('/^[^\p{L}\p{N}]+/u', '', $segment) ?? '';

            if ($clean !== '') {
                $letters[] = mb_substr($clean, 0, 1);
            }

            if (count($letters) === 2) {
                break;
            }
        }

        $initials = implode('', $letters);

        return $initials === '' ? '?' : htmlspecialchars($initials, ENT_QUOTES | ENT_XML1, 'UTF-8');
    }

    private function bucket(string $name): int
    {
        return (int) hexdec(substr(md5($name), 0, 8)) % count(self::PALETTE);
    }
}
