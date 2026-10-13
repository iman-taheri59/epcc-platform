<?php

declare(strict_types=1);

namespace App\Providers;

use App\Services\StandardsPackRepository;
use App\Support\Reference\FlangeBolting;
use App\Support\Reference\FlangePtRatings;
use App\Support\Reference\MaterialLibrary;
use App\Support\Reference\ValveActuators;
use App\Support\Reference\AllowableStress;
use App\Support\Reference\FluidService;
use App\Support\Reference\ValveConstruction;
use App\Support\Reference\WallThickness;
use App\Support\Reference\ValveFaceToFace;
use App\Support\Reference\ValvePtRatings;
use App\Support\Reference\ValveTesting;
use App\Support\Reference\PipeReference;
use App\Support\Reference\FittingDimensions;
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

        $this->app->singleton(
            FittingDimensions::class,
            fn (): FittingDimensions => new FittingDimensions(
                (string) config('epcc.reference_dir', base_path('../standards-packs/reference')),
                $this->app->make(PipeReference::class),
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

        // The material library (group -> spec -> grade -> product form). Same
        // reasoning again: a reference file, decoded once per process.
        $this->app->singleton(
            MaterialLibrary::class,
            fn (): MaterialLibrary => new MaterialLibrary(
                (string) config('epcc.reference_dir', base_path('../standards-packs/reference')),
            ),
        );

        // What one flanged joint needs, used to check that the BOLT and NUT
        // lines of an MTO are not SHORT of what its flanges imply. Ordered
        // bolting is deliberately not derived from the flange count - see the
        // class docblock - so this only ever floors a take-off.
        $this->app->singleton(
            FlangeBolting::class,
            fn (): FlangeBolting => new FlangeBolting(
                (string) config('epcc.reference_dir', base_path('../standards-packs/reference')),
            ),
        );

        // Pressure-temperature ratings, the second hop after the material
        // group. It refuses to answer from an unverified curve rather than
        // returning a plausible pressure - see the class docblock.
        $this->app->singleton(
            FlangePtRatings::class,
            fn (): FlangePtRatings => new FlangePtRatings(
                (string) config('epcc.reference_dir', base_path('../standards-packs/reference')),
            ),
        );

        // Which actuators can drive which valves. Reference data, decoded
        // once: an actuator is an engineered assembly, not a material, so it
        // sits beside the material library rather than inside it.
        $this->app->singleton(
            ValveActuators::class,
            fn (): ValveActuators => new ValveActuators(
                (string) config('epcc.reference_dir', base_path('../standards-packs/reference')),
            ),
        );

        // How a manual valve is BUILT (API 600, API 602, BS 1868...), as
        // opposed to how it is dimensioned (B16.10). Two standards on one PMS
        // line, two fields, two sources.
        $this->app->singleton(
            ValveConstruction::class,
            fn (): ValveConstruction => new ValveConstruction(
                (string) config('epcc.reference_dir', base_path('../standards-packs/reference')),
            ),
        );

        // API 598 - what has to be witnessed before the valve is accepted.
        // It takes ValvePtRatings because the steel shell test pressure is not
        // tabulated anywhere: it is 1.5x the B16.34 rating at 38 C.
        $this->app->singleton(
            ValveTesting::class,
            fn ($app): ValveTesting => new ValveTesting(
                (string) config('epcc.reference_dir', base_path('../standards-packs/reference')),
                $app->make(ValvePtRatings::class),
            ),
        );

        // ASME B31.3 - what fluid service a line is in, and what examination
        // that buys. Slice 1 of the Code: clause 300.2 and 341.4.
        $this->app->singleton(
            FluidService::class,
            fn (): FluidService => new FluidService(
                (string) config('epcc.reference_dir', base_path('../standards-packs/reference')),
            ),
        );

        // ASME B31.3 Table A-1 - the S the thickness equation turns on.
        // 77 of 798 materials are independently confirmed and citable.
        $this->app->singleton(
            AllowableStress::class,
            fn (): AllowableStress => new AllowableStress(
                (string) config('epcc.reference_dir', base_path('../standards-packs/reference')),
            ),
        );

        // ASME B31.3 304.1.2 - the wall a line needs. Refuses without an
        // allowable stress rather than inventing one; Table A-1 is slice 3.
        $this->app->singleton(
            WallThickness::class,
            fn (): WallThickness => new WallThickness(
                (string) config('epcc.reference_dir', base_path('../standards-packs/reference')),
            ),
        );

        // ASME B16.10 - how long a valve is. B16.34 rates it, B16.10 measures
        // it, and a drawing needs both.
        $this->app->singleton(
            ValveFaceToFace::class,
            fn (): ValveFaceToFace => new ValveFaceToFace(
                (string) config('epcc.reference_dir', base_path('../standards-packs/reference')),
            ),
        );

        // ASME B16.34 - the ratings a VALVE is bought to, as opposed to the
        // flange ratings in FlangePtRatings. A separate binding because it is a
        // separate question, and answering it from the flange table was the
        // defect this pack exists to close.
        $this->app->singleton(
            ValvePtRatings::class,
            fn (): ValvePtRatings => new ValvePtRatings(
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
