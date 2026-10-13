<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\BaselineBatch;
use App\Models\BaselineDiscrepancy;
use App\Models\Component;
use App\Models\EngineeringItem;
use App\Models\PipingLine;
use App\Models\Project;
use App\Models\User;
use App\Support\Reference\PipeReference;
use App\Support\Tabular\SpreadsheetReader;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * The engineering baseline, and reconciling the MTO against the 3D model
 * (ADR-005 §2.1, §2.2).
 *
 * WHAT THIS IS FOR. Until now the platform recorded what happened. The
 * baseline is what makes it know what is SUPPOSED to exist - and once it knows
 * that, progress stops being a typed claim and becomes arithmetic: installed
 * divided by baseline, shortage as baseline minus received.
 *
 * THE USER'S RULING (2026-09-19): NOBODY WINS AUTOMATICALLY. When the MTO says
 * 120 m and the model says 134 m, this service does NOT pick one. It records
 * both and raises a discrepancy, and the baseline simply has no agreed figure
 * for that item until a person chooses. That is deliberate and slightly
 * inconvenient by design: a quiet automatic choice is how the wrong quantity
 * reaches a purchase order with nobody's name against it.
 *
 * Tolerance is ONE flat percentage per project (also the user's ruling), not a
 * table of per-item rules. Pipe is cut from stock lengths and never matches to
 * the metre; below the tolerance the two sources are treated as agreeing.
 */
final class BaselineService
{
    public function __construct(
        private readonly SpreadsheetReader $reader,
        private readonly PipeReference $reference,
        private readonly ProjectArchiveService $archives,
        private readonly PipingLineService $lines,
    ) {}

    // ------------------------------------------------------- MTO import --

    /**
     * Import an MTO spreadsheet as a DRAFT batch.
     *
     * Draft, not accepted: an import is a claim until somebody with authority
     * says it is the claim to work to. `accept()` is the separate act.
     */
    public function importMto(
        Project $project,
        User $actor,
        string $path,
        string $filename,
        ?PipingLine $line = null,
        ?string $revisionLabel = null,
    ): BaselineBatch {
        $this->archives->assertWritable($project);

        $rows = $this->reader->read($path, $filename);

        if (count($rows) < 2) {
            throw ValidationException::withMessages([
                'file' => __('epcc.baseline.file_has_no_rows', ['file' => $filename]),
            ]);
        }

        $map = $this->mapHeader(array_shift($rows));

        return DB::transaction(function () use ($project, $actor, $rows, $map, $filename, $path, $line, $revisionLabel): BaselineBatch {
            $batch = BaselineBatch::create([
                'project_id' => $project->id,
                'company_id' => $actor->company_id,
                'source_kind' => BaselineBatch::SOURCE_MTO,
                'filename' => $filename,
                'content_hash' => hash_file('sha256', $path),
                'revision_label' => $revisionLabel,
                'piping_line_id' => $line?->id,
                'subsystem_id' => $line?->subsystem_id,
                'state' => BaselineBatch::DRAFT,
                'imported_by' => $actor->id,
            ]);

            $kept = 0;
            $rejected = 0;
            $warnings = [];

            foreach ($rows as $i => $row) {
                $rowNo = $i + 2; // 1-based, plus the header

                $item = $this->rowToItem($row, $map);

                if ($item === null) {
                    continue; // entirely blank row, not worth reporting
                }

                if ($item['item_code'] === '' || $item['quantity'] === null) {
                    // Reported rather than dropped. A silently skipped row is
                    // how an MTO ends up 40 m short with nobody aware.
                    $rejected++;
                    $warnings[] = [
                        'row' => $rowNo,
                        'code' => 'incomplete_row',
                        'detail' => __('epcc.baseline.row_incomplete'),
                    ];

                    continue;
                }

                if ($item['quantity'] <= 0.0) {
                    $rejected++;
                    $warnings[] = [
                        'row' => $rowNo,
                        'code' => 'non_positive_quantity',
                        'detail' => __('epcc.baseline.row_quantity_not_positive', [
                            'qty' => (string) $item['quantity'],
                        ]),
                    ];

                    continue;
                }

                $size = $item['nominal_size'] !== null
                    ? $this->reference->resolveLabel($item['nominal_size'])
                    : null;

                EngineeringItem::create([
                    'project_id' => $project->id,
                    'company_id' => $actor->company_id,
                    'baseline_batch_id' => $batch->id,
                    'piping_line_id' => $line?->id,
                    'subsystem_id' => $line?->subsystem_id,
                    'item_code' => $item['item_code'],
                    'component_type' => $item['component_type'],
                    'nominal_size' => $size?->nps ?? $item['nominal_size'],
                    'nominal_size_decimal' => $size?->npsDecimal,
                    'schedule' => $item['schedule'],
                    'material_code' => $item['material_code'],
                    'description' => $item['description'],
                    'quantity' => $item['quantity'],
                    'unit' => $this->normaliseUnit($item['unit']),
                    'source_kind' => BaselineBatch::SOURCE_MTO,
                    'confidence' => BaselineBatch::confidenceFor(BaselineBatch::SOURCE_MTO),
                    'source_row' => $rowNo,
                ]);

                $kept++;
            }

            $batch->update([
                'row_count' => $kept,
                'rejected_count' => $rejected,
                'warnings' => $warnings,
            ]);

            return $batch->fresh(['items']);
        });
    }

    /**
     * Accept a batch as the claim to work to, superseding the previous one
     * from the same source and scope.
     *
     * The old batch is marked SUPERSEDED, never deleted: "what changed between
     * rev B and rev C" has to remain answerable, and so does "which revision
     * did we raise PO-0002 against".
     */
    public function accept(BaselineBatch $batch, User $actor): BaselineBatch
    {
        $project = Project::query()->findOrFail($batch->project_id);
        $this->archives->assertWritable($project);

        if ($batch->isAccepted()) {
            throw ValidationException::withMessages([
                'state' => __('epcc.baseline.already_accepted'),
            ]);
        }

        return DB::transaction(function () use ($batch, $actor): BaselineBatch {
            BaselineBatch::query()
                ->where('project_id', $batch->project_id)
                ->where('source_kind', $batch->source_kind)
                ->where('state', BaselineBatch::ACCEPTED)
                ->when(
                    $batch->piping_line_id !== null,
                    fn ($q) => $q->where('piping_line_id', $batch->piping_line_id),
                    fn ($q) => $q->whereNull('piping_line_id'),
                )
                ->whereKeyNot($batch->id)
                ->update(['state' => BaselineBatch::SUPERSEDED]);

            $batch->update([
                'state' => BaselineBatch::ACCEPTED,
                'accepted_at' => now(),
                'accepted_by' => $actor->id,
            ]);

            return $batch->fresh();
        });
    }

    // ------------------------------------------------- take-off from 3D --

    /**
     * Derive a take-off from the modelled components and store it as a batch.
     *
     * This is what makes reconciliation possible at all: the model's claim has
     * to be expressed in the same vocabulary as the MTO's before the two can
     * be compared. Pipe is summed as LENGTH; everything else is counted.
     */
    public function takeOffFromModel(PipingLine $line, User $actor): BaselineBatch
    {
        $project = Project::query()->findOrFail($line->project_id);
        $this->archives->assertWritable($project);

        $components = Component::query()
            ->where('piping_line_id', $line->id)
            ->get();

        if ($components->isEmpty()) {
            throw ValidationException::withMessages([
                'piping_line_id' => __('epcc.baseline.line_not_modelled', [
                    'number' => $line->line_number,
                ]),
            ]);
        }

        return DB::transaction(function () use ($project, $line, $actor, $components): BaselineBatch {
            $batch = BaselineBatch::create([
                'project_id' => $project->id,
                'company_id' => $actor->company_id,
                'source_kind' => BaselineBatch::SOURCE_PCF,
                'filename' => null,
                'piping_line_id' => $line->id,
                'subsystem_id' => $line->subsystem_id,
                'state' => BaselineBatch::DRAFT,
                'imported_by' => $actor->id,
            ]);

            /** @var array<string, array<string,mixed>> $totals */
            $totals = [];

            foreach ($components as $c) {
                $isPipe = $c->template_code === 'PIPE';

                // Pipe is bought by the metre, fittings by the piece. Counting
                // pipe as "3 pipes" would make the comparison meaningless, and
                // it is the single most common way a take-off goes wrong.
                $unit = $isPipe ? 'M' : 'EA';

                $quantity = $isPipe
                    ? ((float) ($c->length_mm ?? 0.0)) / 1000.0
                    : 1.0;

                if ($quantity <= 0.0) {
                    continue;
                }

                // Group by what actually distinguishes a purchasable item. Two
                // 6" elbows of the same material are one line of 2, but a 6"
                // and an 8" elbow are not.
                $key = implode('|', [
                    $c->material_code ?? $c->template_code,
                    $c->template_code,
                    (string) $c->nominal_size,
                    (string) $c->schedule,
                    $unit,
                ]);

                if (! isset($totals[$key])) {
                    $totals[$key] = [
                        'item_code' => $c->material_code ?? $c->template_code,
                        'component_type' => $c->template_code,
                        'nominal_size' => $c->nominal_size,
                        'nominal_size_decimal' => $c->nominal_size_decimal,
                        'schedule' => $c->schedule,
                        'material_code' => $c->material_code,
                        'unit' => $unit,
                        'quantity' => 0.0,
                    ];
                }

                $totals[$key]['quantity'] += $quantity;
            }

            foreach ($totals as $row) {
                EngineeringItem::create([
                    'project_id' => $project->id,
                    'company_id' => $actor->company_id,
                    'baseline_batch_id' => $batch->id,
                    'piping_line_id' => $line->id,
                    'subsystem_id' => $line->subsystem_id,
                    'item_code' => $row['item_code'],
                    'component_type' => $row['component_type'],
                    'nominal_size' => $row['nominal_size'],
                    'nominal_size_decimal' => $row['nominal_size_decimal'],
                    'schedule' => $row['schedule'],
                    'material_code' => $row['material_code'],
                    'quantity' => round($row['quantity'], 4),
                    'unit' => $row['unit'],
                    'source_kind' => BaselineBatch::SOURCE_PCF,
                    'confidence' => BaselineBatch::confidenceFor(BaselineBatch::SOURCE_PCF),
                ]);
            }

            $batch->update(['row_count' => count($totals)]);

            return $batch->fresh(['items']);
        });
    }

    // ----------------------------------------------------- reconciliation --

    /**
     * Compare two accepted batches and record every disagreement.
     *
     * Existing OPEN discrepancies for this scope are replaced, because they
     * describe a comparison that has just been superseded. RESOLVED ones are
     * left alone: somebody made a decision and it must not evaporate because a
     * new revision arrived.
     *
     * @return list<BaselineDiscrepancy>
     */
    public function reconcile(PipingLine $line, User $actor, BaselineBatch $a, BaselineBatch $b): array
    {
        $project = Project::query()->findOrFail($line->project_id);
        $this->archives->assertWritable($project);

        foreach ([$a, $b] as $batch) {
            if (! $batch->isAccepted()) {
                throw ValidationException::withMessages([
                    'baseline_batch_id' => __('epcc.baseline.cannot_reconcile_draft', [
                        'file' => $batch->filename ?? $batch->source_kind,
                    ]),
                ]);
            }
        }

        $tolerance = $this->tolerancePct($project);

        return DB::transaction(function () use ($project, $line, $actor, $a, $b, $tolerance): array {
            BaselineDiscrepancy::query()
                ->where('piping_line_id', $line->id)
                ->where('state', BaselineDiscrepancy::OPEN)
                ->delete();

            $left = $this->indexItems($a);
            $right = $this->indexItems($b);

            $keys = array_unique([...array_keys($left), ...array_keys($right)]);
            sort($keys);

            $out = [];

            foreach ($keys as $key) {
                $l = $left[$key] ?? null;
                $r = $right[$key] ?? null;

                $qtyA = $l['quantity'] ?? null;
                $qtyB = $r['quantity'] ?? null;
                $unit = $l['unit'] ?? $r['unit'] ?? 'EA';

                if ($qtyA !== null && $qtyB !== null) {
                    $diff = $qtyB - $qtyA;
                    $base = max(abs($qtyA), abs($qtyB));
                    $pct = $base > 0.0 ? abs($diff) / $base * 100.0 : 0.0;

                    // Within tolerance the two sources are treated as agreeing.
                    // Pipe is cut from stock lengths and will never match to
                    // the metre; flagging that would bury the real findings.
                    if ($pct <= $tolerance) {
                        continue;
                    }

                    $out[] = $this->raise($project, $line, $actor, [
                        'item_code' => $l['item_code'],
                        'unit' => $unit,
                        'kind' => BaselineDiscrepancy::QUANTITY,
                        'source_a' => $a->source_kind,
                        'quantity_a' => $qtyA,
                        'batch_a_id' => $a->id,
                        'source_b' => $b->source_kind,
                        'quantity_b' => $qtyB,
                        'batch_b_id' => $b->id,
                        'difference' => round($diff, 4),
                        'difference_pct' => round($pct, 4),
                    ]);

                    continue;
                }

                // Present in one source and absent from the other. Named from
                // the MODEL's point of view, because that is the question
                // people ask: "is this in the model or not?"
                $kind = $qtyB === null
                    ? BaselineDiscrepancy::MISSING_IN_MODEL
                    : BaselineDiscrepancy::MISSING_IN_MTO;

                $out[] = $this->raise($project, $line, $actor, [
                    'item_code' => $l['item_code'] ?? $r['item_code'],
                    'unit' => $unit,
                    'kind' => $kind,
                    'source_a' => $a->source_kind,
                    'quantity_a' => $qtyA,
                    'batch_a_id' => $a->id,
                    'source_b' => $b->source_kind,
                    'quantity_b' => $qtyB,
                    'batch_b_id' => $b->id,
                    'difference' => null,
                    'difference_pct' => null,
                ]);
            }

            return $out;
        });
    }

    /**
     * Resolve a discrepancy by choosing a number and saying why.
     *
     * The note is mandatory. "Somebody picked 134" is not a decision anybody
     * can defend at a claims meeting eighteen months later; "the iso was
     * revised to C and the MTO was not re-issued" is.
     */
    public function resolve(
        BaselineDiscrepancy $discrepancy,
        User $actor,
        string $state,
        string $note,
        ?float $otherQuantity = null,
    ): BaselineDiscrepancy {
        $project = Project::query()->findOrFail($discrepancy->project_id);
        $this->archives->assertWritable($project);

        if (! $discrepancy->isOpen()) {
            throw ValidationException::withMessages([
                'state' => __('epcc.baseline.already_resolved'),
            ]);
        }

        $note = trim($note);

        if ($note === '') {
            throw ValidationException::withMessages([
                'resolution_note' => __('epcc.baseline.resolution_note_required'),
            ]);
        }

        $quantity = match ($state) {
            BaselineDiscrepancy::ACCEPTED_A => $discrepancy->quantity_a,
            BaselineDiscrepancy::ACCEPTED_B => $discrepancy->quantity_b,
            BaselineDiscrepancy::ACCEPTED_OTHER => $otherQuantity,
            BaselineDiscrepancy::DISMISSED => null,
            default => throw ValidationException::withMessages([
                'state' => __('epcc.baseline.unknown_resolution', ['state' => $state]),
            ]),
        };

        if ($state !== BaselineDiscrepancy::DISMISSED && $quantity === null) {
            // Accepting a side that has no number - i.e. the side that was
            // MISSING - is meaningless, and the CHECK would refuse it anyway.
            throw ValidationException::withMessages([
                'resolved_quantity' => __('epcc.baseline.resolution_needs_a_quantity'),
            ]);
        }

        $discrepancy->update([
            'state' => $state,
            'resolved_quantity' => $quantity,
            'resolution_note' => $note,
            'resolved_by' => $actor->id,
            'resolved_at' => now(),
        ]);

        return $discrepancy->fresh();
    }

    // ------------------------------------------------------- the answer --

    /**
     * The agreed baseline for a line: what to plan, buy and measure against.
     *
     * An item under an OPEN discrepancy appears with a null quantity and
     * `disputed = true`. That is the honest answer, and it is why this returns
     * a structure rather than a number: a caller that wants a total has to
     * decide, explicitly, what to do about the disputed rows instead of
     * silently summing a lie.
     *
     * @return list<array<string,mixed>>
     */
    public function agreedBaseline(PipingLine $line): array
    {
        $items = EngineeringItem::query()
            ->accepted()
            ->where('piping_line_id', $line->id)
            ->get();

        $disputes = BaselineDiscrepancy::query()
            ->where('piping_line_id', $line->id)
            ->get()
            ->keyBy(fn (BaselineDiscrepancy $d): string => $d->item_code.'|'.$d->unit);

        /** @var array<string, array<string,mixed>> $out */
        $out = [];

        foreach ($items as $item) {
            $key = $item->item_code.'|'.$item->unit;

            if (! isset($out[$key])) {
                $out[$key] = [
                    'item_code' => $item->item_code,
                    'unit' => $item->unit,
                    'nominal_size' => $item->nominal_size,
                    'component_type' => $item->component_type,
                    'quantity' => null,
                    'disputed' => false,
                    'sources' => [],
                ];
            }

            $out[$key]['sources'][$item->source_kind] = round($item->quantity, 4);
        }

        foreach ($out as $key => $row) {
            $dispute = $disputes->get($key);

            if ($dispute !== null) {
                $agreed = $dispute->agreedQuantity();

                $out[$key]['disputed'] = $dispute->isOpen();
                $out[$key]['quantity'] = $agreed;

                continue;
            }

            // No dispute: either one source claimed it, or they agreed within
            // tolerance. Take the highest-confidence claim - not as a
            // tie-break between conflicting numbers (there is none here), but
            // because it is the most authoritative statement of a figure
            // everybody already agrees on.
            $best = null;
            $bestConfidence = -1;

            foreach ($row['sources'] as $source => $quantity) {
                $confidence = BaselineBatch::confidenceFor((string) $source);

                if ($confidence > $bestConfidence) {
                    $bestConfidence = $confidence;
                    $best = $quantity;
                }
            }

            $out[$key]['quantity'] = $best;
        }

        return array_values($out);
    }

    /**
     * Baseline minus what has been ordered and received (the user's ruling:
     * build the shortage report in this phase).
     *
     * Deliberately NOT reusing `WarehouseService::subsystemMaterialStatus()`,
     * which answers a different question: ordered-versus-delivered. That
     * report cannot see a shortfall in the ORDER itself - if 120 m was ordered
     * against a baseline of 134 m, it reports 100% delivered while the line is
     * 14 m short. This is the number that catches it.
     *
     * @return list<array<string,mixed>>
     */
    public function shortageReport(PipingLine $line): array
    {
        $baseline = $this->agreedBaseline($line);
        $out = [];

        foreach ($baseline as $row) {
            $ordered = (float) \App\Models\PurchaseOrderItem::query()
                ->where('project_id', $line->project_id)
                ->where('subsystem_id', $line->subsystem_id)
                ->where('item_code', $row['item_code'])
                ->sum('quantity');

            $received = (float) \App\Models\MaterialMovement::query()
                ->where('project_id', $line->project_id)
                ->where('subsystem_id', $line->subsystem_id)
                ->where('type', \App\Support\MovementType::SITE)
                ->whereIn('lot_id', \App\Models\MaterialLot::query()
                    ->where('project_id', $line->project_id)
                    ->where('item_code', $row['item_code'])
                    ->select('id'))
                ->sum('quantity');

            $required = $row['quantity'];

            $out[] = [
                'item_code' => $row['item_code'],
                'unit' => $row['unit'],
                'required' => $required === null ? null : round((float) $required, 4),
                'ordered' => round($ordered, 4),
                'received' => round($received, 4),

                // Null, not zero, while the requirement is disputed. A
                // shortage of "0" against an unknown requirement is a
                // statement the data does not support, and it is precisely the
                // number somebody would put in front of a client.
                'to_order' => $required === null ? null : round(max(0.0, (float) $required - $ordered), 4),
                'to_receive' => $required === null ? null : round(max(0.0, (float) $required - $received), 4),
                'disputed' => $row['disputed'],
            ];
        }

        return $out;
    }

    // ----------------------------------------------------------- helpers --

    private function tolerancePct(Project $project): float
    {
        return (float) ($this->lines->configFor($project)->baseline_tolerance_pct ?? 2.0);
    }

    /** @return array<string, array<string,mixed>> */
    private function indexItems(BaselineBatch $batch): array
    {
        $out = [];

        foreach ($batch->items as $item) {
            // Keyed by item code AND unit: "10 EA" and "10 M" of the same code
            // are not the same claim, and comparing them would be arithmetic
            // on two different physical things.
            $key = $item->item_code.'|'.$item->unit;

            if (! isset($out[$key])) {
                $out[$key] = [
                    'item_code' => $item->item_code,
                    'unit' => $item->unit,
                    'quantity' => 0.0,
                ];
            }

            $out[$key]['quantity'] += (float) $item->quantity;
        }

        return $out;
    }

    /** @param array<string,mixed> $attributes */
    private function raise(Project $project, PipingLine $line, User $actor, array $attributes): BaselineDiscrepancy
    {
        return BaselineDiscrepancy::create(array_merge([
            'project_id' => $project->id,
            'company_id' => $actor->company_id,
            'piping_line_id' => $line->id,
            'subsystem_id' => $line->subsystem_id,
            'state' => BaselineDiscrepancy::OPEN,
        ], $attributes));
    }

    /**
     * Match spreadsheet headers to fields, in both languages.
     *
     * Deliberately forgiving about wording and case, and deliberately NOT
     * forgiving about position: an MTO written by a contractor will not have
     * the column order we imagined, and demanding one would guarantee the
     * importer is never used.
     *
     * @param  list<string>  $header
     * @return array<string,int>
     */
    private function mapHeader(array $header): array
    {
        $aliases = [
            'item_code' => ['item code', 'itemcode', 'code', 'item', 'material code', 'کد کالا', 'کد', 'کد متریال'],
            'description' => ['description', 'desc', 'shrh', 'شرح', 'توضیحات'],
            'nominal_size' => ['size', 'nps', 'nominal size', 'dia', 'diameter', 'سایز', 'قطر'],
            'schedule' => ['schedule', 'sch', 'رده', 'اسکجول'],
            'material_code' => ['material', 'material code', 'matl', 'جنس', 'متریال'],
            'component_type' => ['type', 'component', 'component type', 'نوع'],
            'quantity' => ['quantity', 'qty', 'amount', 'مقدار', 'تعداد', 'مقدار کل'],
            'unit' => ['unit', 'uom', 'units', 'واحد'],
        ];

        $map = [];

        foreach ($header as $i => $raw) {
            $name = mb_strtolower(trim($raw));

            if ($name === '') {
                continue;
            }

            foreach ($aliases as $field => $options) {
                if (isset($map[$field])) {
                    continue;
                }

                if (in_array($name, $options, true)) {
                    $map[$field] = $i;

                    break;
                }
            }
        }

        if (! isset($map['item_code']) || ! isset($map['quantity'])) {
            throw ValidationException::withMessages([
                'file' => __('epcc.baseline.header_not_understood'),
            ]);
        }

        return $map;
    }

    /**
     * @param  list<string>  $row
     * @param  array<string,int>  $map
     * @return array<string,mixed>|null
     */
    private function rowToItem(array $row, array $map): ?array
    {
        $get = static function (string $field) use ($row, $map): ?string {
            $i = $map[$field] ?? null;

            if ($i === null) {
                return null;
            }

            $value = trim((string) ($row[$i] ?? ''));

            return $value === '' ? null : $value;
        };

        if (implode('', array_map('trim', $row)) === '') {
            return null;
        }

        return [
            'item_code' => (string) ($get('item_code') ?? ''),
            'description' => $get('description'),
            'nominal_size' => $get('nominal_size'),
            'schedule' => $get('schedule'),
            'material_code' => $get('material_code'),
            'component_type' => $get('component_type'),
            'quantity' => $this->parseNumber($get('quantity')),
            'unit' => $get('unit'),
        ];
    }

    /**
     * Parse a quantity written by a human in a spreadsheet.
     *
     * Handles Persian and Arabic-Indic digits, thousands separators and a
     * Persian decimal separator. An MTO produced in Iran will contain all of
     * these, and `(float) "۱٬۲۰۰٫۵"` is 0.0 - silently, with no error, which
     * would make the line look like it needs nothing at all.
     */
    private function parseNumber(?string $value): ?float
    {
        if ($value === null) {
            return null;
        }

        $digits = ['۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4',
            '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9',
            '٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4',
            '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9',
            '٫' => '.', '٬' => '', '،' => '', ',' => '', ' ' => '', "\u{00A0}" => ''];

        $clean = strtr(trim($value), $digits);

        return is_numeric($clean) ? (float) $clean : null;
    }

    /**
     * Normalise the unit, because "m", "M", "MTR" and "متر" are one unit.
     *
     * Left as-is when unrecognised rather than forced into a default: a wrong
     * unit is worse than an unknown one, since it makes two incomparable
     * quantities look comparable.
     */
    private function normaliseUnit(?string $unit): string
    {
        $u = mb_strtolower(trim((string) $unit));

        return match ($u) {
            '', 'ea', 'each', 'pc', 'pcs', 'no', 'nos', 'عدد' => 'EA',
            'm', 'mtr', 'mtrs', 'meter', 'metre', 'meters', 'متر' => 'M',
            'kg', 'kgs', 'kilogram', 'کیلوگرم', 'کیلو' => 'KG',
            'in', 'inch', 'اینچ' => 'IN',
            default => mb_strtoupper($u),
        };
    }
}
