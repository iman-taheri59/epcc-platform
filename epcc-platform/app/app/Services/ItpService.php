<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Itp;
use App\Models\ItpRecord;
use App\Models\ItpStage;
use App\Models\ItpAnnex;
use App\Models\ItpStageDocument;
use App\Models\ItpStageParty;
use App\Models\ItpTemplate;
use App\Models\Document;
use App\Models\MaterialLot;
use App\Models\Project;
use App\Models\StandardCitation;
use App\Models\User;
use App\Support\Itp\InterventionCode;
use App\Support\TenantType;
use App\Support\Itp\StageResult;
use App\Services\DocumentRegister;
use App\Services\StandardsLibrary;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Inspection and Test Plans: build them, apply them, and gate on them.
 *
 * THE GATE, AND WHERE IT SITS
 * ---------------------------
 * By the user's ruling a HOLD point blocks RELEASE - issuing material out of
 * the store for installation - and never the goods receipt. That is the only
 * placement that survives contact with a site: the truck arrives at 2am and
 * the TPI signs on Sunday, so a system that refuses the receipt produces a
 * pile of material that physically exists and officially does not. Blocking
 * the issue instead is both enforceable and honest: the material is in the
 * warehouse, it is counted, and it may not be welded into the plant yet.
 */
final class ItpService
{
    public function __construct(
        private readonly DocumentRegister $register,
        private readonly StandardsLibrary $standards,
    ) {
    }

    // -----------------------------------------------------------------------
    // Building an ITP
    // -----------------------------------------------------------------------

    /**
     * Copy a library template into a project.
     *
     * A COPY, not a reference. The library is maintained centrally and will be
     * edited; a running project's inspection plan is a contractual document
     * that must not change because somebody improved the standard template.
     * Provenance is kept in `source_template_id` so "where did this come from"
     * still has an answer.
     *
     * @throws ValidationException
     */
    public function createFromTemplate(
        Project $project,
        User $user,
        ItpTemplate $template,
        ?string $number = null,
        string $lang = 'fa',
    ): Itp {
        if (! $template->is_active) {
            throw ValidationException::withMessages([
                'template' => __('epcc.itp.template_inactive'),
            ]);
        }

        // A template carries a full party grid, so copying one IS assigning
        // everybody their hold and witness points.
        $this->assertMayAssignPartyRoles($user);

        return DB::transaction(function () use ($project, $user, $template, $number, $lang): Itp {
            $itp = $this->createBlank(
                $project,
                $user,
                $template->discipline,
                $number ?? $this->nextNumber($project, $template->discipline),
                $template->title_en,
                $template->title_fa,
                $template->scope_en,
                $template->scope_fa,
                $lang,
            );

            $itp->update(['source_template_id' => $template->id]);

            foreach ($template->stages ?? [] as $index => $row) {
                $this->addStage($itp, $user, [
                    'clause' => (string) ($row['clause'] ?? (string) ($index + 1)),
                    'sequence' => (int) ($row['sequence'] ?? (($index + 1) * 10)),
                    'title_en' => (string) ($row['title_en'] ?? ''),
                    'title_fa' => (string) ($row['title_fa'] ?? ''),
                    'acceptance_en' => $row['acceptance_en'] ?? null,
                    'acceptance_fa' => $row['acceptance_fa'] ?? null,
                    'method' => $row['method'] ?? null,
                    'frequency_en' => $row['frequency_en'] ?? null,
                    'frequency_fa' => $row['frequency_fa'] ?? null,
                    'reference' => $row['reference'] ?? null,
                    'record_en' => $row['record_en'] ?? null,
                    'record_fa' => $row['record_fa'] ?? null,
                    'form_number' => $row['form_number'] ?? null,
                    'notice_hours' => isset($row['notice_hours']) ? (int) $row['notice_hours'] : null,
                    'parties' => $row['parties'] ?? [],
                    /*
                     * A library template cites project documents by NUMBER,
                     * because the library predates every project and cannot
                     * hold a foreign key into one. addStage() resolves those
                     * numbers against this project's register and silently
                     * skips any that do not exist here — a standard template
                     * legitimately mentions a spec a given project never
                     * issued, and that must not block the copy.
                     */
                    'documents' => $row['documents'] ?? [],
                ], $lang);
            }

            return $itp->fresh(['stages.parties']);
        });
    }

    /** @throws ValidationException */
    public function createBlank(
        Project $project,
        User $user,
        string $discipline,
        string $number,
        string $titleEn,
        string $titleFa,
        ?string $scopeEn = null,
        ?string $scopeFa = null,
        string $lang = 'fa',
    ): Itp {
        $number = trim($number);

        if ($number === '') {
            throw ValidationException::withMessages(['number' => __('epcc.itp.number_required')]);
        }

        if (Itp::where('project_id', $project->id)->where('number', $number)->exists()) {
            throw ValidationException::withMessages([
                'number' => __('epcc.itp.number_taken', ['number' => $number]),
            ]);
        }

        return Itp::create([
            'project_id' => $project->id,
            'company_id' => $user->company_id,
            'discipline' => $discipline,
            'number' => $number,
            'title_en' => $titleEn,
            'title_fa' => $titleFa,
            'scope_en' => $scopeEn,
            'scope_fa' => $scopeFa,
            'state' => Itp::DRAFT,
            'created_by' => $user->id,
        ]);
    }

    /**
     * Refuse a party-grid change from a company that has no business making one.
     *
     * The user's ruling: only the employer, the management contractor and the
     * consultant set the roles of the project parties. The grid is an
     * allocation of authority — a TPI able to edit it can write itself out of
     * a hold point, and a contractor able to edit it can remove the client's
     * witness. Before this rule the system allowed exactly that.
     *
     * Checked against the acting company's tenant_type rather than a role
     * code, because the rule is about WHICH FIRM this is, not which permission
     * an administrator happened to grant a user inside it.
     *
     * @throws ValidationException
     */
    private function assertMayAssignPartyRoles(User $user): void
    {
        $tenantType = $user->company?->tenant_type;

        if (TenantType::mayAssignPartyRoles($tenantType)) {
            return;
        }

        /*
         * The party name is read in the APPLICATION's locale, not a $lang
         * argument: __() already resolves that way, and mixing the two
         * produced an English sentence with a Persian noun inside it.
         */
        $locale = app()->getLocale() === 'fa' ? 'fa' : 'en';

        throw ValidationException::withMessages([
            'parties' => __('epcc.itp.party_roles_not_yours', [
                'party' => $tenantType === null ? '—' : TenantType::label($tenantType)[$locale],
            ]),
        ]);
    }

    /**
     * Add one row, with its party grid.
     *
     * @param  array<string,mixed>  $data
     *
     * @throws ValidationException
     */
    public function addStage(Itp $itp, User $user, array $data, string $lang = 'fa'): ItpStage
    {
        if ($itp->state === Itp::SUPERSEDED) {
            throw ValidationException::withMessages(['itp' => __('epcc.itp.superseded_is_read_only')]);
        }

        // Only when a party grid is actually supplied: adding a bare row is a
        // drafting act, assigning interventions is an allocation of authority.
        if (($data['parties'] ?? []) !== []) {
            $this->assertMayAssignPartyRoles($user);
        }

        $clause = trim((string) ($data['clause'] ?? ''));

        if ($clause === '') {
            throw ValidationException::withMessages(['clause' => __('epcc.itp.clause_required')]);
        }

        if (ItpStage::where('itp_id', $itp->id)->where('clause', $clause)->exists()) {
            throw ValidationException::withMessages([
                'clause' => __('epcc.itp.clause_taken', ['clause' => $clause]),
            ]);
        }

        $parties = $data['parties'] ?? [];

        // Validate the whole grid BEFORE writing anything: a half-written
        // stage with a silently dropped party is worse than a refusal,
        // because the grid then reads as "no involvement" - which is a
        // meaningful and wrong statement, not an obvious gap.
        foreach ($parties as $tenantType => $code) {
            if (! InterventionCode::isValid((string) $code)) {
                throw ValidationException::withMessages([
                    'parties' => __('epcc.itp.unknown_intervention', [
                        'code' => (string) $code,
                        'party' => (string) $tenantType,
                    ]),
                ]);
            }
        }

        return DB::transaction(function () use ($itp, $data, $clause, $parties, $lang): ItpStage {
            $stage = ItpStage::create([
                'project_id' => $itp->project_id,
                'company_id' => $itp->company_id,
                'itp_id' => $itp->id,
                'clause' => $clause,
                'sequence' => (int) ($data['sequence'] ?? ((ItpStage::where('itp_id', $itp->id)->max('sequence') ?? 0) + 10)),
                'title_en' => (string) ($data['title_en'] ?? ''),
                'title_fa' => (string) ($data['title_fa'] ?? ''),
                'acceptance_en' => $data['acceptance_en'] ?? null,
                'acceptance_fa' => $data['acceptance_fa'] ?? null,
                'method' => $data['method'] ?? null,
                'frequency_en' => $data['frequency_en'] ?? null,
                'frequency_fa' => $data['frequency_fa'] ?? null,
                'reference' => $data['reference'] ?? null,
                'record_en' => $data['record_en'] ?? null,
                'record_fa' => $data['record_fa'] ?? null,
                'form_number' => $data['form_number'] ?? null,
                'notice_hours' => isset($data['notice_hours']) ? (int) $data['notice_hours'] : null,
            ]);

            foreach ($parties as $tenantType => $code) {
                ItpStageParty::create([
                    'project_id' => $itp->project_id,
                    'company_id' => $itp->company_id,
                    'itp_stage_id' => $stage->id,
                    'tenant_type' => (string) $tenantType,
                    'intervention' => (string) $code,
                ]);
            }

            foreach ((array) ($data['documents'] ?? []) as $citation) {
                $this->citeDocument($stage, (array) $citation, $lang);
            }

            $this->structureStandards($stage);

            return $stage->load(['parties', 'documents.document', 'standardCitations.standard']);
        });
    }

    /**
     * Turn the row's free-text standards into library citations.
     *
     * Runs on every stage as it is created, so a plan copied from the library
     * arrives already structured and "which rows cite B31.3?" is answerable
     * without parsing prose.
     *
     * What does NOT resolve stays in `reference`. That is the user's ruling
     * and it is the right one: on a live project there is always a code the
     * library has not caught up with, and refusing the row until an
     * administrator adds it would stop inspection work to satisfy a database.
     */
    private function structureStandards(ItpStage $stage): void
    {
        if (! $stage->reference) {
            return;
        }

        $parsed = $this->standards->parseReference($stage->reference);

        if ($parsed['matched'] === []) {
            return;
        }

        foreach ($parsed['matched'] as $hit) {
            $this->standards->cite(
                $stage->itp->project,
                $hit['standard'],
                StandardCitation::ITP_STAGE,
                $stage->id,
                $hit['clause'],
            );
        }

        // Keep only the leftovers, so the column is not printed twice.
        $stage->update([
            'reference' => $parsed['unmatched'] === []
                ? null
                : implode(' · ', $parsed['unmatched']),
        ]);
    }

    /**
     * Cite a project document from one ITP row: a spec, drawing, procedure, or
     * the inspection report form.
     *
     * Accepts either `document_id` (the UI, which picked from the register) or
     * `document_number` (a library template, which cannot hold a foreign key
     * into a project that did not exist when the template was written).
     *
     * An unresolvable NUMBER is skipped, not fatal: a standard template
     * legitimately mentions a spec that a given project never issued, and
     * refusing the whole copy over it would make the library unusable. An
     * unresolvable ID is fatal, because that means the caller had a concrete
     * document in hand and it was wrong.
     *
     * @param  array<string, mixed>  $citation
     *
     * @throws ValidationException
     */
    public function citeDocument(ItpStage $stage, array $citation, string $lang = 'fa'): ?ItpStageDocument
    {
        if ($stage->itp?->state === 'SUPERSEDED') {
            throw ValidationException::withMessages(['itp' => __('epcc.itp.superseded_is_read_only')]);
        }

        $usage = strtoupper((string) ($citation['usage'] ?? ItpStageDocument::SPEC));

        if (! ItpStageDocument::usageIsValid($usage)) {
            throw ValidationException::withMessages([
                'usage' => __('epcc.itp.document_usage_unknown', ['usage' => $usage]),
            ]);
        }

        $document = null;

        if (! empty($citation['document_id'])) {
            $document = Document::where('project_id', $stage->project_id)
                ->find($citation['document_id']);

            // Hard failure: the caller named a specific row and missed.
            if ($document === null) {
                throw ValidationException::withMessages([
                    'document_id' => __('epcc.itp.document_not_in_project'),
                ]);
            }
        } elseif (! empty($citation['document_number'])) {
            $document = Document::where('project_id', $stage->project_id)
                ->where('number', (string) $citation['document_number'])
                ->first();

            // Soft failure: a template citing a document this project has not
            // issued. Skip the citation, keep the stage.
            if ($document === null) {
                return null;
            }
        } else {
            throw ValidationException::withMessages([
                'document' => __('epcc.itp.document_required'),
            ]);
        }

        /*
         * A pinned revision must belong to the document being cited, or the
         * ITP would resolve to a revision of something else entirely. Checked
         * rather than trusted, because this arrives from a form.
         */
        $pinned = null;
        if (! empty($citation['pinned_revision_id'])) {
            $pinned = $document->revisions()->find($citation['pinned_revision_id']);

            if ($pinned === null) {
                throw ValidationException::withMessages([
                    'pinned_revision_id' => __('epcc.itp.revision_not_of_document'),
                ]);
            }
        }

        $existing = ItpStageDocument::where('itp_stage_id', $stage->id)
            ->where('document_id', $document->id)
            ->where('usage', $usage)
            ->first();

        if ($existing !== null) {
            return $existing;
        }

        return ItpStageDocument::create([
            'project_id' => $stage->project_id,
            'company_id' => $stage->company_id,
            'itp_stage_id' => $stage->id,
            'document_id' => $document->id,
            'usage' => $usage,
            'pinned_revision_id' => $pinned?->id,
            'locator' => $citation['locator'] ?? null,
        ]);
    }

    /**
     * Enter this plan in the project document register.
     *
     * An ITP is a deliverable, not a private artefact of the QA module: it is
     * issued for review, commented on, revised and published like a datasheet,
     * and it belongs on the transmittal and in the client's MDR. Before this
     * step it is a plan the QA team is drafting; after it, it is a controlled
     * document and the REGISTER owns its number.
     *
     * Idempotent: registering twice returns the existing entry rather than
     * minting a second number for the same plan.
     *
     * @throws ValidationException
     */
    public function registerAsDocument(Itp $itp, User $user, string $lang = 'fa'): Document
    {
        if ($itp->document_id !== null) {
            return $itp->document;
        }

        if ($itp->state === 'SUPERSEDED') {
            throw ValidationException::withMessages(['itp' => __('epcc.itp.superseded_is_read_only')]);
        }

        return DB::transaction(function () use ($itp, $user): Document {
            /*
             * doc_type ITP, discipline from the plan. The register mints the
             * number, so the ITP cannot invent one that collides with the
             * client's scheme - that drift is the reason this method exists.
             */
            $document = $this->register->register($itp->project, $user, [
                'discipline' => $itp->discipline,
                'doc_type' => 'ITP',
                'title_en' => $itp->title_en,
                'title_fa' => $itp->title_fa,
                'revision' => $itp->revision_label ?: 'A',
            ]);

            $itp->update(['document_id' => $document->id]);

            return $document;
        });
    }

    /**
     * Bind a document in as an annex of this plan - normally a blank
     * inspection report form.
     *
     * This is the annex list on the cover sheet. It is not the same as
     * citing a form from a row: `itp_stage_documents` says which form clause
     * 1.4 uses, this says what is bound into the issued document. A forty-row
     * ITP has two or three annexes, not forty.
     *
     * @throws ValidationException
     */
    public function attachAnnex(
        Itp $itp,
        Document $document,
        ?string $label = null,
        string $kind = ItpAnnex::FORM,
        ?int $sequence = null,
    ): ItpAnnex {
        if ($itp->state === 'SUPERSEDED') {
            throw ValidationException::withMessages(['itp' => __('epcc.itp.superseded_is_read_only')]);
        }

        if (! ItpAnnex::kindIsValid($kind)) {
            throw ValidationException::withMessages([
                'kind' => __('epcc.itp.annex_kind_unknown', ['kind' => $kind]),
            ]);
        }

        // An annex must belong to the same project. A form from another
        // project would be invisible under RLS to half the people who need it.
        if ($document->project_id !== $itp->project_id) {
            throw ValidationException::withMessages([
                'document_id' => __('epcc.itp.document_not_in_project'),
            ]);
        }

        // The plan cannot be its own annex.
        if ($itp->document_id !== null && $document->id === $itp->document_id) {
            throw ValidationException::withMessages([
                'document_id' => __('epcc.itp.annex_is_the_plan_itself'),
            ]);
        }

        $existing = ItpAnnex::where('itp_id', $itp->id)
            ->where('document_id', $document->id)
            ->first();

        if ($existing !== null) {
            return $existing;
        }

        return ItpAnnex::create([
            'project_id' => $itp->project_id,
            'company_id' => $itp->company_id,
            'itp_id' => $itp->id,
            'document_id' => $document->id,
            'label' => $label,
            'kind' => $kind,
            'sequence' => $sequence ?? ((ItpAnnex::where('itp_id', $itp->id)->max('sequence') ?? 0) + 10),
        ]);
    }

    /**
     * Bind in every form the rows of this plan cite, in clause order.
     *
     * The rows already know which form each activity uses; the annex list is
     * derivable from them, and typing it twice is how the two drift apart.
     * Labelled A, B, C in first-use order, which is how a cover sheet reads.
     *
     * @return list<ItpAnnex>
     */
    public function syncAnnexesFromStages(Itp $itp): array
    {
        $itp->loadMissing('stages.documents.document');

        $seen = [];
        $made = [];
        $letter = 0;

        foreach ($itp->stages as $stage) {
            foreach ($stage->documentsFor(ItpStageDocument::FORM) as $citation) {
                $doc = $citation->document;

                if ($doc === null || isset($seen[$doc->id])) {
                    continue;
                }

                $seen[$doc->id] = true;
                $made[] = $this->attachAnnex(
                    $itp,
                    $doc,
                    chr(ord('A') + $letter),
                    ItpAnnex::FORM,
                    ($letter + 1) * 10,
                );
                $letter++;
            }
        }

        return $made;
    }

    /** Remove a citation. Refused once the plan is read-only. */
    public function removeCitation(ItpStageDocument $citation, string $lang = 'fa'): void
    {
        if ($citation->stage?->itp?->state === 'SUPERSEDED') {
            throw ValidationException::withMessages(['itp' => __('epcc.itp.superseded_is_read_only')]);
        }

        $citation->delete();
    }

    /**
     * Put an ITP in force, retiring the previous one for that discipline.
     *
     * Supersede rather than delete: records already signed against the old
     * revision must keep pointing at the text they were signed against. An ITP
     * whose stages can be edited after signature is not evidence.
     *
     * @throws ValidationException
     */
    public function activate(Itp $itp, User $user, string $lang = 'fa'): Itp
    {
        if ($itp->state === Itp::SUPERSEDED) {
            throw ValidationException::withMessages(['itp' => __('epcc.itp.superseded_is_read_only')]);
        }

        if ($itp->stages()->count() === 0) {
            throw ValidationException::withMessages(['itp' => __('epcc.itp.needs_a_stage')]);
        }

        return DB::transaction(function () use ($itp): Itp {
            Itp::where('project_id', $itp->project_id)
                ->where('discipline', $itp->discipline)
                ->where('state', Itp::ACTIVE)
                ->whereKeyNot($itp->id)
                ->update(['state' => Itp::SUPERSEDED]);

            $itp->update(['state' => Itp::ACTIVE]);

            return $itp->fresh();
        });
    }

    /** The ITP in force for a discipline, or null. */
    public function activeFor(Project $project, string $discipline): ?Itp
    {
        return Itp::with(['stages.parties'])
            ->where('project_id', $project->id)
            ->where('discipline', $discipline)
            ->active()
            ->first();
    }

    // -----------------------------------------------------------------------
    // Applying an ITP to material
    // -----------------------------------------------------------------------

    /**
     * Open the inspection record set for a lot.
     *
     * Creates one PENDING row per stage of the active ITP, so the stages a lot
     * must pass are visible from the moment it exists rather than appearing
     * only once somebody inspects something. An empty checklist that is
     * present is a question; an absent checklist is a silence, and silence is
     * what lets uninspected material reach a weld.
     *
     * Idempotent: re-running adds only stages that are missing, which is what
     * happens when a revised ITP adds a row to a discipline mid-project.
     *
     * @return list<ItpRecord>
     *
     * @throws ValidationException
     */
    public function openForLot(MaterialLot $lot, string $discipline, string $lang = 'fa'): array
    {
        $project = Project::findOrFail($lot->project_id);
        $itp = $this->activeFor($project, $discipline);

        if ($itp === null) {
            throw ValidationException::withMessages([
                'discipline' => __('epcc.itp.no_active_itp', ['discipline' => $discipline]),
            ]);
        }

        return DB::transaction(function () use ($itp, $lot): array {
            $existing = ItpRecord::where('material_lot_id', $lot->id)
                ->pluck('itp_stage_id')
                ->all();

            $created = [];

            foreach ($itp->stages as $stage) {
                if (in_array($stage->id, $existing, true)) {
                    continue;
                }

                $created[] = ItpRecord::create([
                    'project_id' => $lot->project_id,
                    'company_id' => $lot->company_id,
                    'itp_stage_id' => $stage->id,
                    'subject_kind' => ItpRecord::SUBJECT_MATERIAL_LOT,
                    'material_lot_id' => $lot->id,
                    'result' => StageResult::PENDING,
                ]);
            }

            return $created;
        });
    }

    /**
     * Record that the parties owed notice have been invited.
     *
     * @throws ValidationException
     */
    public function notify(ItpRecord $record, User $user, string $reference, string $lang = 'fa'): ItpRecord
    {
        $reference = trim($reference);

        if ($reference === '') {
            throw ValidationException::withMessages(['notice_reference' => __('epcc.itp.notice_reference_required')]);
        }

        if (! $record->isOpen()) {
            throw ValidationException::withMessages(['result' => __('epcc.itp.already_settled')]);
        }

        $record->update([
            'result' => StageResult::NOTIFIED,
            'notified_at' => now(),
            'notified_by' => $user->id,
            'notice_reference' => $reference,
        ]);

        return $record->fresh();
    }

    /**
     * Sign a stage off.
     *
     * The signer's company must actually hold an intervention at this stage.
     * Without that check the grid is decoration: anybody could sign the TPI's
     * hold point and the document would look complete.
     *
     * @throws ValidationException
     */
    public function sign(
        ItpRecord $record,
        User $user,
        string $result,
        ?string $findingsEn = null,
        ?string $findingsFa = null,
        string $lang = 'fa',
    ): ItpRecord {
        if (! in_array($result, StageResult::ALL, true)) {
            throw ValidationException::withMessages(['result' => __('epcc.itp.unknown_result', ['result' => $result])]);
        }

        if (StageResult::isOpen($result)) {
            throw ValidationException::withMessages(['result' => __('epcc.itp.cannot_sign_to_open_state')]);
        }

        if (! $record->isOpen()) {
            throw ValidationException::withMessages(['result' => __('epcc.itp.already_settled')]);
        }

        $company = $user->company;
        $tenantType = $company?->tenant_type;

        $party = ItpStageParty::where('itp_stage_id', $record->itp_stage_id)
            ->where('tenant_type', $tenantType)
            ->first();

        if ($party === null) {
            throw ValidationException::withMessages([
                'result' => __('epcc.itp.party_has_no_role_here', ['party' => (string) $tenantType]),
            ]);
        }

        // A reason is not optional for anything except a clean pass. "Failed"
        // with no text is a dead end for whoever has to fix it, and
        // "conditional" with no text is a reservation nobody can honour.
        if (StageResult::requiresReason($result) && trim((string) $findingsEn) === '' && trim((string) $findingsFa) === '') {
            throw ValidationException::withMessages(['findings' => __('epcc.itp.findings_required')]);
        }

        /*
         * WAIVED is only available at a WITNESS point, and only once the
         * invitation is evidenced. A HOLD that nobody attended stays PENDING
         * for ever - that is precisely what distinguishes the two codes, and
         * the place where a permissive system would quietly destroy the
         * distinction the whole document exists to express.
         */
        if ($result === StageResult::WAIVED) {
            if ($party->blocks()) {
                throw ValidationException::withMessages(['result' => __('epcc.itp.hold_cannot_be_waived')]);
            }

            if ($record->notified_at === null || $record->notice_reference === null) {
                throw ValidationException::withMessages(['result' => __('epcc.itp.waiver_needs_notice')]);
            }
        }

        $record->update([
            'result' => $result,
            'findings_en' => $findingsEn,
            'findings_fa' => $findingsFa,
            'signed_by' => $user->id,
            'signed_company_id' => $company?->id,
            'signed_tenant_type' => $tenantType,
            'signed_at' => now(),
        ]);

        return $record->fresh();
    }

    // -----------------------------------------------------------------------
    // The gate
    // -----------------------------------------------------------------------

    /**
     * Hold points that are not yet released for this lot.
     *
     * Only HOLD stages count. A pending witness point does not stop material -
     * if it did, W and H would mean the same thing and the ITP would be
     * unusable in practice.
     *
     * @return list<array{stage:ItpStage,record:?ItpRecord,parties:list<string>}>
     */
    public function openHoldsFor(MaterialLot $lot): array
    {
        $records = ItpRecord::with(['stage.parties'])
            ->where('material_lot_id', $lot->id)
            ->get();

        $open = [];

        foreach ($records as $record) {
            $stage = $record->stage;

            if ($stage === null || ! $stage->isHoldPoint()) {
                continue;
            }

            if ($record->satisfied()) {
                continue;
            }

            $open[] = [
                'stage' => $stage,
                'record' => $record,
                'parties' => $stage->holdingParties(),
            ];
        }

        return $open;
    }

    /**
     * May this lot leave the store for installation?
     *
     * Read by WarehouseService::issue(). Receipt is deliberately NOT gated.
     */
    public function releaseAllowed(MaterialLot $lot): bool
    {
        return $this->openHoldsFor($lot) === [];
    }

    /**
     * Progress of a lot through its plan, for the screen.
     *
     * @return array{total:int,settled:int,open_holds:int,failed:int,percent:float}
     */
    public function progressFor(MaterialLot $lot): array
    {
        $records = ItpRecord::with('stage.parties')->where('material_lot_id', $lot->id)->get();

        $total = $records->count();
        $settled = $records->filter(fn (ItpRecord $r): bool => $r->satisfied())->count();
        $failed = $records->where('result', StageResult::FAILED)->count();

        return [
            'total' => $total,
            'settled' => $settled,
            'open_holds' => count($this->openHoldsFor($lot)),
            'failed' => $failed,
            'percent' => $total === 0 ? 0.0 : round($settled / $total * 100, 1),
        ];
    }

    private function nextNumber(Project $project, string $discipline): string
    {
        $count = Itp::where('project_id', $project->id)
            ->where('discipline', $discipline)
            ->count();

        return sprintf('%s-ITP-%s-%02d', $project->code, $discipline, $count + 1);
    }
}
