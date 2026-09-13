<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\PaymentCertificate;
use Epcc\StandardsEngine\ReleaseGateEvaluator;
use App\Support\TenantContext;
use Epcc\StandardsEngine\Verdict;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Evaluates a payment certificate against its PROJECT'S standards pack and
 * records the decision.
 *
 * The pack is chosen per project (projects.standards_pack), not hardcoded -
 * that is the user's "standards framework must be selectable" requirement
 * expressed in running code rather than in a config constant.
 */
final class GateService
{
    public function __construct(
        private readonly StandardsPackRepository $packs,
        private readonly ReleaseGateEvaluator $evaluator,
        private readonly TenantContext $tenant,
    ) {
    }

    public function evaluateCertificate(PaymentCertificate $cert, int|float $authorityLimit): Verdict
    {
        // Read the actor from PostgreSQL, not from PHP memory: the GUC is what
        // RLS will actually enforce against, so it is the only trustworthy
        // answer to "who am I acting as right now?".
        $actorCompanyId = $this->tenant->currentGuc() ?? $cert->company_id;

        $project = $cert->project;
        $pack = $this->packs->get($project->standards_pack);

        $facts = $cert->toGateFacts($authorityLimit);
        $verdict = $this->evaluator->evaluate($pack, 'interimPaymentCertificate', $facts);

        // Append-only audit: a release decision must be reconstructable later,
        // including WHICH pack version produced it (packs are versioned and
        // installs drift - risk IR-R4).
        DB::table('gate_evaluations')->insert([
            'id'           => (string) Str::uuid(),
            'project_id'   => $cert->project_id,
            // The tenant column is the ACTOR (who ran the check), not the
            // object's owner - see migration 2026_09_09_100300.
            'actor_company_id'   => $actorCompanyId,
            'subject_company_id' => $cert->company_id,
            'object_type'  => 'interimPaymentCertificate',
            'object_id'    => $cert->id,
            'pack_id'      => $pack['packId'],
            'pack_version' => $pack['version'],
            'passed'       => $verdict->passed,
            'blocked'      => $verdict->isBlocked(),
            'failures'     => json_encode($verdict->failures, JSON_UNESCAPED_UNICODE),
            'facts'        => json_encode($facts, JSON_UNESCAPED_UNICODE),
            'created_at'   => now(),
        ]);

        return $verdict;
    }
}
