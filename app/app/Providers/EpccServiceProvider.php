<?php

declare(strict_types=1);

namespace App\Providers;

use App\Services\StandardsPackRepository;
use App\Support\Reference\PipeReference;
use App\Support\Units\UnitRegistry;
use App\Support\TenantContext;
use Epcc\StandardsEngine\ReleaseGateEvaluator;
use Illuminate\Support\ServiceProvider;

final class EpccServiceProvider extends ServiceProvider
{
    public function register(): void
    {
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
