<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Component;
use App\Models\Joint;
use App\Models\ModelImport;
use App\Models\ModelImportDiff;
use App\Models\PipingLine;
use App\Models\Port;
use App\Models\Project;
use App\Models\Subsystem;
use App\Models\User;
use App\Support\LineReleaseState;
use App\Support\Piping\Pcf\PcfComponent;
use App\Support\Piping\Pcf\PcfFile;
use App\Support\Piping\Pcf\PcfKeypoint;
use App\Support\Piping\Pcf\PcfParser;
use App\Support\Piping\Pcf\PcfVocabulary;
use App\Support\Reference\PipeReference;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Build the piping model from a 3D model extract (ADR-005 phase 2).
 *
 * WHAT THIS REPLACES. Until now a line was modelled by hand, component by
 * component, through `ComponentService::attach()`. That is correct for a
 * correction and unusable for a plant: a single 3D model holds tens of
 * thousands of components, and nobody is going to retype them. This service is
 * the bulk path - and it is a translation, not a shortcut. Every component it
 * creates goes through the same templates, the same size rules and the same
 * compatibility engine as a hand-placed one, because an imported joint that
 * skipped the checks would be a progress claim with no evidence behind it.
 *
 * WHY PCF AND NOT NAVISWORKS. NWD, NWF and NWC are proprietary, undocumented
 * and Windows-only (ADR-005 §1.1). PCF is plain text, is emitted by Plant 3D,
 * CADWorx, AVEVA, SmartPlant and Creo alike, and unlike an MTO it carries the
 * CONNECTIVITY - explicit typed keypoints - which is what lets us build joints
 * rather than a parts list.
 *
 * THE THREE RULINGS (user, 2026-09-18) and how each is honoured:
 *
 *   1. RE-IMPORT DIFFS, NEVER DESTROYS. A component whose joint is FITTED, or
 *      which a person has edited by hand, is left exactly as it is and written
 *      to `model_import_diffs` as PROTECTED. The model does not get to delete
 *      a weld the field has already made.
 *   2. AN UNKNOWN LINE IS QUARANTINED. Not refused - refusing would mean the
 *      model cannot be loaded until the line list is perfect, which is never.
 *      Not accepted either. It is created, flagged, and kept out of the
 *      register until somebody accepts it.
 *   3. IMPORTED THINGS STAY EDITABLE, BUT GET FLAGGED. A hand edit stamps
 *      `manually_edited_at`, and from then on the importer will not overwrite
 *      that component; it reports the collision instead.
 */
final class ModelImportService
{
    /**
     * How close two keypoints must be, in millimetres, to be one joint.
     *
     * 1 mm. Not a guess: coordinates are written by the modeller's geometry
     * engine, so components that meet agree to floating-point noise, while
     * genuinely different weld positions on real pipework are never 1 mm
     * apart. Too loose and two welds merge into one - which would silently
     * REDUCE the weld count the contractor is paid for.
     */
    public const TOUCH_TOLERANCE_MM = 1.0;

    public function __construct(
        private readonly PcfParser $parser,
        private readonly PipeReference $reference,
        private readonly ComponentService $components,
        private readonly JointService $joints,
        private readonly PipingLineService $lines,
        private readonly ProjectArchiveService $archives,
    ) {}

    /**
     * Import one PCF file into a project.
     *
     * @param  bool  $dryRun  parse, diff and report, but write no model changes
     */
    public function importPcf(
        Project $project,
        User $actor,
        string $contents,
        string $filename,
        bool $dryRun = false,
        ?Subsystem $quarantineSubsystem = null,
    ): ModelImport {
        $this->archives->assertWritable($project);

        $file = $this->parser->parse($contents);

        // UNITS-BORE is refused when absent rather than defaulted. A file in
        // INCH-SIXTEENTH read as INCH turns a 6" line into 96" and every
        // number downstream stays plausible - the worst class of defect,
        // because nothing ever looks wrong.
        if ($file->unitsBore() === null) {
            throw ValidationException::withMessages([
                'file' => __('epcc.model_import.units_bore_missing', ['file' => $filename]),
            ]);
        }

        $lineNumber = $file->lineNumber();

        if ($lineNumber === null) {
            throw ValidationException::withMessages([
                'file' => __('epcc.model_import.line_reference_missing', ['file' => $filename]),
            ]);
        }

        return DB::transaction(function () use (
            $project, $actor, $file, $filename, $contents, $lineNumber, $dryRun, $quarantineSubsystem
        ): ModelImport {
            $line = $this->resolveLine($project, $actor, $lineNumber, $quarantineSubsystem, $filename);

            $import = ModelImport::create([
                'project_id' => $project->id,
                'company_id' => $actor->company_id,
                'piping_line_id' => $line?->id,
                'filename' => $filename,
                'source_kind' => 'PCF',
                'pipeline_reference' => $lineNumber,
                'revision' => $file->header('REVISION'),
                'piping_spec' => $file->header('PIPING-SPEC'),
                'units_bore' => $file->unitsBore(),
                'units_coords' => $file->unitsCoords(),
                'content_hash' => hash('sha256', $contents),
                'state' => $dryRun ? ModelImport::STATE_DRY_RUN : ModelImport::STATE_PARSED,
                'imported_by' => $actor->id,
                'imported_at' => now(),
                'warnings' => array_map(
                    fn ($w): array => ['line' => $w->sourceLine, 'code' => $w->code, 'detail' => $w->detail],
                    $file->warnings,
                ),
            ]);

            if ($line === null) {
                // resolveLine() only returns null when the caller forbade
                // quarantine and the line is unknown. Nothing to apply.
                $import->update(['state' => ModelImport::STATE_FAILED]);

                return $import->fresh(['diffs']);
            }

            $this->applyComponents($import, $line, $actor, $file, $dryRun);

            if (! $dryRun) {
                $this->buildJoints($import, $line, $actor, $file);
                $import->update(['state' => ModelImport::STATE_APPLIED]);
            }

            return $import->fresh(['diffs']);
        });
    }

    // ------------------------------------------------------------- lines --

    /**
     * Find the line this file belongs to, quarantining it when unknown.
     *
     * Ruling 2. The number is taken from the file and validated; it is never
     * invented, which keeps this consistent with the withdrawal of automatic
     * numbering. What quarantine adds is that an unrecognised number does not
     * stop the import - it lands somewhere visible and clearly marked instead
     * of being either silently accepted or angrily rejected.
     */
    private function resolveLine(
        Project $project,
        User $actor,
        string $lineNumber,
        ?Subsystem $quarantineSubsystem,
        string $filename,
    ): ?PipingLine {
        $existing = PipingLine::query()
            ->where('project_id', $project->id)
            ->where('line_number', $lineNumber)
            ->first();

        if ($existing !== null) {
            if ($existing->release_state === LineReleaseState::DELETED) {
                throw ValidationException::withMessages([
                    'file' => __('epcc.model_import.line_deleted', ['number' => $lineNumber]),
                ]);
            }

            return $existing;
        }

        if ($quarantineSubsystem === null) {
            throw ValidationException::withMessages([
                'file' => __('epcc.model_import.line_unknown_no_quarantine', [
                    'number' => $lineNumber,
                    'file' => $filename,
                ]),
            ]);
        }

        $line = $this->lines->register(
            $quarantineSubsystem,
            $actor,
            $lineNumber,
            ['source' => 'PCF'],
        );

        $line->update([
            'is_quarantined' => true,

            // A MACHINE key, not a sentence. Calling __() here would freeze the
            // importer's language into the row, so a Farsi user would later
            // read an English explanation of why their line is held - which is
            // exactly what the first screenshot showed. System-generated text
            // is translated when it is DISPLAYED, never when it is stored.
            // (User-typed reasons are different: those are stored verbatim,
            // because translating a person's own words would be a forgery.)
            'quarantine_reason' => 'AUTO:FROM_FILE:'.$filename,
        ]);

        return $line->fresh();
    }

    /**
     * Accept a quarantined line into the register.
     *
     * Deliberately a separate, explicit act by a person. The whole value of
     * quarantine is that admission is a decision somebody made and signed,
     * not a side effect of uploading a file.
     */
    public function acceptQuarantinedLine(PipingLine $line, User $actor): PipingLine
    {
        $project = Project::query()->findOrFail($line->project_id);
        $this->archives->assertWritable($project);

        if (! $line->is_quarantined) {
            throw ValidationException::withMessages([
                'is_quarantined' => __('epcc.model_import.not_quarantined', ['number' => $line->line_number]),
            ]);
        }

        $line->update([
            'is_quarantined' => false,
            'released_from_quarantine_at' => now(),
            'released_from_quarantine_by' => $actor->id,
        ]);

        return $line->fresh();
    }

    // -------------------------------------------------------- components --

    private function applyComponents(
        ModelImport $import,
        PipingLine $line,
        User $actor,
        PcfFile $file,
        bool $dryRun,
    ): void {
        $existing = Component::query()
            ->where('piping_line_id', $line->id)
            ->get()
            ->keyBy(fn (Component $c): string => (string) ($c->pcf_identifier ?? 'no-id:'.$c->id));

        $seen = 0;
        $created = 0;
        $updated = 0;
        $unchanged = 0;
        $protectedCount = 0;
        $unmapped = 0;
        $touched = [];

        foreach ($file->materialComponents() as $pcf) {
            if (PcfVocabulary::isNotModelled($pcf->type)) {
                // Recognised, deliberately not built. Supports are phase 5 and
                // inventing a component for them now would put objects in the
                // model that the joint engine would have to special-case.
                continue;
            }

            $seen++;
            $template = PcfVocabulary::templateFor($pcf->type);

            if ($template === null) {
                $unmapped++;
                $this->diff($import, ModelImportDiff::UNMAPPED, $pcf, null, null, [
                    'detail' => __('epcc.model_import.unknown_type', ['type' => $pcf->type]),
                ]);

                continue;
            }

            $identifier = $this->identifierFor($pcf);
            $match = $identifier === null ? null : $existing->get($identifier);

            if ($match === null) {
                if (! $dryRun) {
                    $component = $this->createComponent($import, $line, $actor, $pcf, $template, $file);
                    $touched[] = $component->id;
                    $this->diff($import, ModelImportDiff::CREATED, $pcf, $template, $component);
                } else {
                    $this->diff($import, ModelImportDiff::CREATED, $pcf, $template, null);
                }

                $created++;

                continue;
            }

            $touched[] = $match->id;

            // Ruling 1 and 3, the heart of the whole design. Two independent
            // reasons to withhold a change, checked in this order because the
            // field one is the one nobody may override.
            $reason = $this->protectionReason($match);

            if ($reason !== null) {
                $changes = $this->plannedChanges($match, $pcf, $template, $file);

                if ($changes !== []) {
                    $protectedCount++;
                    $this->diff($import, ModelImportDiff::PROTECTED, $pcf, $template, $match, [
                        'protected_reason' => $reason,
                        'before' => $this->snapshot($match),
                        'after' => $changes,
                    ]);
                } else {
                    $unchanged++;
                }

                continue;
            }

            $changes = $this->plannedChanges($match, $pcf, $template, $file);

            if ($changes === []) {
                $unchanged++;
                continue;
            }

            if (! $dryRun) {
                $before = $this->snapshot($match);
                $match->update($changes + ['model_import_id' => $import->id]);
                $this->diff($import, ModelImportDiff::UPDATED, $pcf, $template, $match, [
                    'before' => $before,
                    'after' => $changes,
                ]);
            } else {
                $this->diff($import, ModelImportDiff::UPDATED, $pcf, $template, $match, [
                    'before' => $this->snapshot($match),
                    'after' => $changes,
                ]);
            }

            $updated++;
        }

        // Components the system holds that this revision of the model no
        // longer contains. Reported, NEVER deleted - deletion is a decision
        // with consequences for the take-off and possibly for material already
        // bought, and it is not one an upload gets to make.
        foreach ($existing as $component) {
            if (in_array($component->id, $touched, true)) {
                continue;
            }

            if ($component->pcf_identifier === null) {
                continue; // hand-modelled, never claimed by the model
            }

            $this->diff($import, ModelImportDiff::REMOVED_IN_MODEL, null, $component->template_code, $component, [
                'detail' => __('epcc.model_import.absent_from_revision'),
            ]);
        }

        $import->update([
            'components_seen' => $seen,
            'components_created' => $created,
            'components_updated' => $updated,
            'components_unchanged' => $unchanged,
            'components_protected' => $protectedCount,
            'unmapped_count' => $unmapped,
        ]);
    }

    /**
     * Why this component must not be overwritten - or null if it may be.
     *
     * Order matters. A fitted joint is a physical fact and outranks everything;
     * a hand edit is a human claim that still deserves to survive a re-import,
     * but it is the softer of the two and is reported differently.
     */
    private function protectionReason(Component $component): ?string
    {
        $fitted = Joint::query()
            ->where('state', 'FITTED')
            ->where(function ($q) use ($component): void {
                $ids = Port::query()->where('component_id', $component->id)->pluck('id');
                $q->whereIn('port_a_id', $ids)->orWhereIn('port_b_id', $ids);
            })
            ->exists();

        if ($fitted) {
            return 'FITTED_JOINT';
        }

        if ($component->manually_edited_at !== null) {
            return 'MANUALLY_EDITED';
        }

        return null;
    }

    /**
     * What the model wants to change on an existing component.
     *
     * Returns only genuine differences. Reporting "updated" for a component
     * that is byte-identical would make the diff report useless on the second
     * import, which is precisely when people start relying on it.
     *
     * @return array<string,mixed>
     */
    private function plannedChanges(Component $component, PcfComponent $pcf, string $template, PcfFile $file): array
    {
        $changes = [];

        $size = $this->sizeFor($pcf, $file);

        if ($size !== null && $component->nominal_size !== $size->nps) {
            $changes['nominal_size'] = $size->nps;
            $changes['nominal_size_decimal'] = $size->npsDecimal;
        }

        $material = $pcf->attribute('ITEM-CODE');

        if ($material !== null && $component->material_code !== $material) {
            $changes['material_code'] = $material;
        }

        $spool = $pcf->attribute('SPOOL-IDENTIFIER');

        if ($spool !== null && $component->spool_identifier !== $spool) {
            $changes['spool_identifier'] = $spool;
        }

        if ($component->template_code !== $template) {
            // The model changed WHAT the component is - an elbow became a tee.
            // Recorded as a change like any other; it is not our business to
            // decide that engineering may not do that.
            $changes['template_code'] = $template;
        }

        return $changes;
    }

    private function createComponent(
        ModelImport $import,
        PipingLine $line,
        User $actor,
        PcfComponent $pcf,
        string $template,
        PcfFile $file,
    ): Component {
        $size = $this->sizeFor($pcf, $file);
        $keypoints = $pcf->connectingKeypoints();
        $known = array_map(fn (array $e): string => (string) $e['code'], $this->reference->endTypes());
        $skey = $pcf->attribute('SKEY');

        // Per-port sizes, so a reducer imports as 6x4 rather than 6x6. The
        // template's size rule is then checked by ComponentService exactly as
        // it would be for a hand-placed component - an eccentric reducer whose
        // ends are equal is refused here as it is there.
        $portSizes = [];
        $portEnds = [];

        foreach (array_values($keypoints) as $i => $kp) {
            $seq = $i + 1;

            if ($kp->bore !== null) {
                $portSize = $this->reference->resolveBore($kp->bore, (string) $file->unitsBore());

                if ($portSize !== null) {
                    $portSizes[$seq] = $portSize->nps;
                }
            }

            $end = PcfVocabulary::endTypeFrom($kp->endType, $skey, $known);

            if ($end !== null) {
                $portEnds[$seq] = $end;
            }
        }

        $component = $this->components->place($line, $actor, $template, array_filter([
            'nominal_size' => $size?->nps,
            'material_code' => $pcf->attribute('ITEM-CODE'),
            'source' => 'PCF',
            'external_ref' => $pcf->attribute('COMPONENT-IDENTIFIER'),
            'port_sizes' => $portSizes,
            'length_mm' => $this->lengthMm($pcf, $file),
        ], fn ($v): bool => $v !== null && $v !== []));

        $component->update([
            'model_import_id' => $import->id,
            'pcf_identifier' => $this->identifierFor($pcf),
            'pcf_type' => $pcf->type,
            'spool_identifier' => $pcf->attribute('SPOOL-IDENTIFIER'),
        ]);

        $this->stampPorts($component, $keypoints, $portEnds, $file);

        return $component->fresh(['ports']);
    }

    /**
     * Write the file's coordinates and end types onto the created ports.
     *
     * Coordinates are converted to MILLIMETRES here, once, at the boundary.
     * Storing them in the file's own units would make two files with different
     * UNITS-CO-ORDS incomparable - and comparing them is the entire mechanism
     * by which joints are found.
     *
     * @param  list<PcfKeypoint>  $keypoints
     * @param  array<int,string>  $portEnds
     */
    private function stampPorts(Component $component, array $keypoints, array $portEnds, PcfFile $file): void
    {
        $scale = $file->coordinateScaleMm();
        $ports = $component->ports()->orderBy('seq')->get();

        foreach ($ports as $i => $port) {
            $kp = $keypoints[$i] ?? null;

            if ($kp === null) {
                continue;
            }

            $update = [];

            if ($kp->hasCoordinates()) {
                $update['x_mm'] = round((float) $kp->x * $scale, 3);
                $update['y_mm'] = round((float) $kp->y * $scale, 3);
                $update['z_mm'] = round((float) $kp->z * $scale, 3);
            }

            if (isset($portEnds[$port->seq])) {
                $update['end_type'] = $portEnds[$port->seq];
            }

            if ($update !== []) {
                $port->update($update);
            }
        }
    }

    // ------------------------------------------------------------ joints --

    /**
     * Create joints wherever two ports occupy the same point in space.
     *
     * This is what makes PCF worth having over a spreadsheet: the model states
     * where things meet, so connectivity is read rather than inferred.
     *
     * Every pair still goes through `JointService::join()`, which means the
     * compatibility engine judges each one. An import that bypassed it could
     * book dia-inch for a butt weld onto a flange - a weld that cannot exist,
     * on an invoice that would be paid.
     */
    private function buildJoints(ModelImport $import, PipingLine $line, User $actor, PcfFile $file): void
    {
        $ports = Port::query()
            ->whereIn('component_id', Component::query()->where('piping_line_id', $line->id)->select('id'))
            ->whereNotNull('x_mm')
            ->get();

        /** @var array<string, list<Port>> $buckets */
        $buckets = [];

        foreach ($ports as $port) {
            $key = $this->coordinateKey($port);

            if ($key !== null) {
                $buckets[$key][] = $port;
            }
        }

        $created = 0;

        foreach ($buckets as $group) {
            // Exactly two. One is an open end; three or more at one point is
            // geometrically impossible in real pipework and means the model has
            // stacked components - reported, not guessed at, because picking
            // two of the three would fabricate a weld.
            if (count($group) !== 2) {
                if (count($group) > 2) {
                    $this->diff($import, ModelImportDiff::UNMAPPED, null, null, null, [
                        'detail' => __('epcc.model_import.coincident_ports', ['count' => count($group)]),
                    ]);
                }

                continue;
            }

            [$a, $b] = $group;

            if ($a->is_connected || $b->is_connected) {
                continue; // already joined, e.g. by an earlier import
            }

            try {
                $this->joints->join($a, $b, $actor);
                $created++;
            } catch (ValidationException $e) {
                // The compatibility engine refused. That is a finding about the
                // pipework, not a failure of the import: it means the model has
                // a butt weld onto a flange or a size mismatch, and somebody
                // needs to look at it. The rest of the file still imports.
                $this->diff($import, ModelImportDiff::UNMAPPED, null, null, null, [
                    'detail' => __('epcc.model_import.joint_refused', [
                        'a' => $a->label(),
                        'b' => $b->label(),
                        'why' => implode('; ', array_merge(...array_values($e->errors()))),
                    ]),
                ]);
            }
        }

        $import->update(['joints_created' => $created]);
    }

    private function coordinateKey(Port $port): ?string
    {
        if ($port->x_mm === null || $port->y_mm === null || $port->z_mm === null) {
            return null;
        }

        $snap = fn ($v): int => (int) round(((float) $v) / self::TOUCH_TOLERANCE_MM);

        return $snap($port->x_mm).':'.$snap($port->y_mm).':'.$snap($port->z_mm);
    }

    // ----------------------------------------------------------- helpers --

    /**
     * A stable identity for a component across revisions.
     *
     * COMPONENT-IDENTIFIER when the exporter provides one. Otherwise the type
     * plus its first coordinate, which survives a re-export that reorders the
     * file - position in the list does not, and matching on position is how
     * an import silently rewrites every component on the line.
     */
    private function identifierFor(PcfComponent $pcf): ?string
    {
        $explicit = $pcf->attribute('COMPONENT-IDENTIFIER');

        if ($explicit !== null) {
            return $explicit;
        }

        $first = $pcf->connectingKeypoints()[0] ?? null;

        if ($first === null || ! $first->hasCoordinates()) {
            return null;
        }

        return $pcf->type.'@'.$first->coordinateKey(1.0);
    }

    private function sizeFor(PcfComponent $pcf, PcfFile $file): ?\App\Support\Reference\PipeSize
    {
        foreach ($pcf->connectingKeypoints() as $kp) {
            if ($kp->bore !== null) {
                $size = $this->reference->resolveBore($kp->bore, (string) $file->unitsBore());

                if ($size !== null) {
                    return $size;
                }
            }
        }

        return null;
    }

    private function lengthMm(PcfComponent $pcf, PcfFile $file): ?float
    {
        $kps = array_values(array_filter(
            $pcf->connectingKeypoints(),
            fn (PcfKeypoint $k): bool => $k->hasCoordinates(),
        ));

        if (count($kps) < 2) {
            return null;
        }

        $scale = $file->coordinateScaleMm();

        $d = sqrt(
            (($kps[0]->x - $kps[1]->x) ** 2)
            + (($kps[0]->y - $kps[1]->y) ** 2)
            + (($kps[0]->z - $kps[1]->z) ** 2)
        ) * $scale;

        return $d > 0.0 ? round($d, 3) : null;
    }

    /** @return array<string,mixed> */
    private function snapshot(Component $component): array
    {
        return [
            'template_code' => $component->template_code,
            'nominal_size' => $component->nominal_size,
            'material_code' => $component->material_code,
            'spool_identifier' => $component->spool_identifier,
        ];
    }

    /** @param array<string,mixed> $extra */
    private function diff(
        ModelImport $import,
        string $change,
        ?PcfComponent $pcf,
        ?string $template,
        ?Component $component,
        array $extra = [],
    ): void {
        ModelImportDiff::create(array_merge([
            'project_id' => $import->project_id,
            'company_id' => $import->company_id,
            'model_import_id' => $import->id,
            'component_id' => $component?->id,
            'change' => $change,
            'pcf_type' => $pcf?->type,
            'template_code' => $template,
            'source_line' => $pcf?->sourceLine,
        ], $extra));
    }
}
