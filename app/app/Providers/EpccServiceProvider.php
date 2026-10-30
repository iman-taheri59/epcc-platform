<?php

declare(strict_types=1);

namespace App\Providers;

use App\Services\PdfRenderer;
use App\Services\StandardsPackRepository;
use App\Support\Reference\FittingDimensions;
use App\Support\Reference\FlangeRatingReference;
use App\Support\Reference\MaterialReference;
use App\Support\Reference\PipeReference;
use App\Support\Units\UnitRegistry;
use App\Support\TenantContext;
use Epcc\StandardsEngine\ReleaseGateEvaluator;
use Illuminate\Support\ServiceProvider;

final class EpccServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // The render budget is configuration, not a constant: 30s is right for
        // a warm host and short for a cold browser on a shared CI runner, and a
        // test that fails because the host was busy is a flake rather than a
        // finding. Default unchanged, so production behaviour is untouched.
        $this->app->bind(
            PdfRenderer::class,
            fn (): PdfRenderer => new PdfRenderer(
                max(1, (int) config('epcc.pdf.timeout_seconds', 30)),
            ),
        );

        $this->app->singleton(
            StandardsPackRepository::class,
            fn (): StandardsPackRepository => new StandardsPackRepository(
                (string) config('epcc.pack_dir', base_path('../standards-packs')),
            ),
        );

        // Singleton so the ~500 rows of ASME data are decoded once per process,
        // not once per port. Read-only by construction: the packs are files on
        // disk with no writer anywhere in the codebase (ADR-007 section 4).
        $this->app->singleton(
            PipeReference::class,
            fn (): PipeReference => new PipeReference(
                (string) config('epcc.reference_dir', base_path('../standards-packs/reference')),
            ),
        );

        // ASME B31.3-2024 materials (ADR-008 Step B, requirement P-24):
        // Table A-1 stresses, Y, W, Ej. Same reasoning as PipeReference —
        // code-edition facts on disk, decoded once per process.
        $this->app->singleton(
            MaterialReference::class,
            fn (): MaterialReference => new MaterialReference(
                (string) config('epcc.reference_dir', base_path('../standards-packs/reference')),
            ),
        );

        // B16.9 dimensions: one read-only pack and the shared NPS resolver.
        $this->app->singleton(
            FittingDimensions::class,
            fn (): FittingDimensions => new FittingDimensions(
                (string) config('epcc.reference_dir', base_path('../standards-packs/reference')),
                $this->app->make(PipeReference::class),
            ),
        );

        // ASME B16.5-2025 flange pressure-temperature ratings (P-24
        // continuation): the FLANGE_TYPE publish gate resolves every flange
        // class against its material group's Table 2 ladder. Code-edition
        // facts on disk, decoded once per process.
        $this->app->singleton(
            FlangeRatingReference::class,
            fn (): FlangeRatingReference => new FlangeRatingReference(
                (string) config('epcc.reference_dir', base_path('../standards-packs/reference')),
            ),
        );

        // Units of measure (ADR-014). Same reasoning as PipeReference: the
        // pack is universal reference data, decoded once per process.
        $this->app->singleton(
            UnitRegistry::class,
            fn (): UnitRegistry => new UnitRegistry(
                (string) config('epcc.reference_dir', base_path('../standards-packs/reference')),
            ),
        );

        // MUST be a singleton. With a fresh instance per injection, a service
        // that asks "who is the current tenant?" gets null while another
        // instance holds the real value - which silently produced audit rows
        // stamped with the wrong company and tripped the RLS WITH CHECK.
        $this->app->singleton(TenantContext::class);

        // The evaluator is framework-free by design (ADR-003 Annex A §6).
        // Laravel only wires it up; it knows nothing about Laravel.
        $this->app->singleton(ReleaseGateEvaluator::class, fn (): ReleaseGateEvaluator => new ReleaseGateEvaluator());
    }
}
