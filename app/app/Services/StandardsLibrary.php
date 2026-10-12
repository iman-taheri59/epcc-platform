<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Project;
use App\Models\ProjectStandard;
use App\Models\Standard;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

/**
 * The library of codes and standards.
 *
 * Three jobs, matching the three tables: maintain the catalogue, record which
 * codes a project works to, and let any project object cite one.
 */
final class StandardsLibrary
{
    /**
     * Add a standard to the global catalogue, or return the existing one.
     *
     * Idempotent on the normalised key, which is the whole defence against the
     * free-text problem this module exists to fix: 'ASME B31.3' and
     * 'asme b 31.3' must not become two rows.
     *
     * @throws ValidationException
     */
    public function define(
        string $body,
        string $designation,
        string $titleEn,
        ?string $titleFa = null,
        ?string $edition = null,
        string $category = Standard::GENERAL,
    ): Standard {
        $body = trim($body);
        $designation = trim($designation);

        if ($body === '' || $designation === '') {
            throw ValidationException::withMessages([
                'designation' => __('epcc.standards.body_and_designation_required'),
            ]);
        }

        if (! Standard::categoryIsValid($category)) {
            throw ValidationException::withMessages([
                'category' => __('epcc.standards.category_unknown', ['category' => $category]),
            ]);
        }

        $normalised = Standard::normalise($body, $designation);

        $existing = Standard::where('normalised', $normalised)->first();
        if ($existing !== null) {
            return $existing;
        }

        return Standard::create([
            'body' => mb_strtoupper($body),
            'designation' => $designation,
            'normalised' => $normalised,
            'title_en' => $titleEn,
            'title_fa' => $titleFa,
            'edition' => $edition,
            'category' => $category,
            'is_active' => true,
        ]);
    }

    /**
     * Find a catalogue entry from a free-text citation such as
     * 'ASME B31.3', 'API 5L' or 'EN 10204'.
     *
     * Used to migrate the existing free-text columns and to resolve what a
     * user types. Returns null rather than throwing: a citation the library
     * does not know is a normal state, not an error — that is exactly why the
     * free-text column survives.
     */
    public function resolve(string $citation): ?Standard
    {
        $citation = trim($citation);
        if ($citation === '') {
            return null;
        }

        // Try the whole string first: 'ASME B31.3' -> ASMEB313.
        $whole = (string) preg_replace('/[^A-Z0-9]/', '', mb_strtoupper($citation));

        $hit = Standard::where('normalised', $whole)->first();
        if ($hit !== null) {
            return $hit;
        }

        /*
         * Then try progressively shorter prefixes, longest first.
         *
         * 'ASME B31.3 §323' must find ASME B31.3 without §323 defeating the
         * match, and longest-first matters: 'ASME B16.5' must not be matched
         * by a hypothetical 'ASME B16' entry while the exact row exists.
         */
        $words = preg_split('/\s+/', $citation) ?: [];
        for ($take = count($words); $take >= 2; $take--) {
            $candidate = (string) preg_replace(
                '/[^A-Z0-9]/', '',
                mb_strtoupper(implode('', array_slice($words, 0, $take)))
            );

            $hit = Standard::where('normalised', $candidate)->first();
            if ($hit !== null) {
                return $hit;
            }
        }

        return null;
    }

    /**
     * Split a free-text reference such as
     * 'EN 10204 · ASME B31.3 §323' into its separate citations.
     *
     * The separators are the ones the existing data actually uses. Anything
     * that does not resolve is returned as unmatched text rather than dropped
     * — losing a reference during a migration would be worse than leaving it
     * in the free-text column.
     *
     * @return array{matched: list<array{standard: Standard, clause: ?string}>, unmatched: list<string>}
     */
    public function parseReference(string $reference): array
    {
        $parts = preg_split('/\s*[·;,]\s*|\s+\/\s+/u', trim($reference)) ?: [];

        $matched = [];
        $unmatched = [];

        /*
         * Engineers write the body once and then let it carry:
         * 'ASME B36.10M · B16.9 · B16.5' means ASME three times. Remember the
         * last body that resolved, and retry a bare designation against it.
         */
        $carriedBody = null;

        foreach ($parts as $part) {
            $part = trim($part);
            if ($part === '') {
                continue;
            }

            $standard = $this->resolve($part);

            if ($standard === null && $carriedBody !== null) {
                $standard = $this->resolve($carriedBody.' '.$part);
            }

            if ($standard === null) {
                $unmatched[] = $part;

                continue;
            }

            $carriedBody = $standard->body;

            // Whatever is left after the code itself is the clause: given
            // 'ASME B31.3 §323', the clause is '§323'. Match against the
            // citation WITH the body restored, or 'B16.9 §5' loses its clause.
            $clause = $this->clauseFrom($part, $standard)
                ?? $this->clauseFrom($standard->body.' '.$part, $standard);

            $matched[] = ['standard' => $standard, 'clause' => $clause];
        }

        return ['matched' => $matched, 'unmatched' => $unmatched];
    }

    /**
     * The trailing clause of a citation, or null.
     *
     * Walks the words, dropping them while they still belong to the code, and
     * returns the remainder. Comparing normalised prefixes rather than doing
     * string surgery keeps it working for 'ASME B 31.3' as well as 'ASMEB31.3'.
     */
    private function clauseFrom(string $citation, Standard $standard): ?string
    {
        $words = preg_split('/\s+/', trim($citation)) ?: [];

        for ($take = 1; $take <= count($words); $take++) {
            $prefix = (string) preg_replace(
                '/[^A-Z0-9]/', '',
                mb_strtoupper(implode('', array_slice($words, 0, $take)))
            );

            if ($prefix === $standard->normalised) {
                $rest = trim(implode(' ', array_slice($words, $take)));

                return $rest === '' ? null : $rest;
            }
        }

        return null;
    }

    /**
     * Record that a project works to a standard.
     *
     * Idempotent per (project, standard): adopting twice updates the edition
     * rather than creating a second row, because "which edition are we bound
     * to" must have exactly one answer.
     *
     * @throws ValidationException
     */
    public function adopt(
        Project $project,
        Standard $standard,
        ?string $edition = null,
        ?string $remarksEn = null,
        ?string $remarksFa = null,
    ): ProjectStandard {
        if (! $standard->is_active) {
            throw ValidationException::withMessages([
                'standard_id' => __('epcc.standards.withdrawn_cannot_be_adopted', [
                    'code' => $standard->code(),
                ]),
            ]);
        }

        $existing = ProjectStandard::where('project_id', $project->id)
            ->where('standard_id', $standard->id)
            ->first();

        if ($existing !== null) {
            $existing->update([
                'edition' => $edition ?? $existing->edition,
                'remarks_en' => $remarksEn ?? $existing->remarks_en,
                'remarks_fa' => $remarksFa ?? $existing->remarks_fa,
                'is_active' => true,
            ]);

            return $existing->fresh();
        }

        return ProjectStandard::create([
            'project_id' => $project->id,
            'standard_id' => $standard->id,
            'edition' => $edition,
            'remarks_en' => $remarksEn,
            'remarks_fa' => $remarksFa,
            'is_active' => true,
        ]);
    }

    /**
     * The standards this project works to, catalogue entry included.
     *
     * @return Collection<int, ProjectStandard>
     */
    public function forProject(Project $project, bool $activeOnly = true): Collection
    {
        $q = ProjectStandard::with('standard')->where('project_id', $project->id);

        if ($activeOnly) {
            $q->where('is_active', true);
        }

        return $q->get()->sortBy(fn (ProjectStandard $ps) => $ps->standard?->code())->values();
    }

    /**
     * Withdraw a standard from the catalogue.
     *
     * Never a delete. A record signed in 1998 cites what it cites, and the
     * FK is restrictOnDelete precisely so nobody can orphan that history. The
     * flag keeps it out of the picker while leaving the past readable.
     */
    public function withdraw(Standard $standard): Standard
    {
        $standard->update(['is_active' => false]);

        return $standard->fresh();
    }

    /**
     * The catalogue, for a picker.
     *
     * @return Collection<int, Standard>
     */
    public function catalogue(?string $category = null, bool $activeOnly = true): Collection
    {
        $q = Standard::query();

        if ($activeOnly) {
            $q->where('is_active', true);
        }

        if ($category !== null) {
            $q->where('category', $category);
        }

        return $q->orderBy('body')->orderBy('designation')->get();
    }
}
