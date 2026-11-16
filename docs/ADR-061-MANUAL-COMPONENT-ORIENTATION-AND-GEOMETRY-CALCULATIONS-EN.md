# ADR-061 — Manual component orientation and geometry calculations

Status: **ACCEPTED — INITIAL TECHNICAL CONTRACT SELECTED; PARTIAL IMPLEMENTATION** (2026-10-26).
The current partial geometry slice persists project-frame and component-pose
revisions for straight `PIPE`, explicit-length `NIPPLE`, B16.9-2018 BW
long-radius `ELBOW` profiles (`90LR`/`45LR`), and a separate, limited B16.11-2021
forged-elbow profile for exact covered two-ended SW or threaded NPS/class rows
at 45°/90°. B16.11 requires explicit standard, class designation, and both-end
type; its angle values are not LR/SR qualifiers. SW uses a documented
application port datum at the socket bottom with table A directly and J kept
separate; threaded uses table A/C center-to-end. Profile rows, local bend-plane
convention, and transformed ports are versioned and hashed; pose input remains
explicit azimuth/inclination/roll or a dimensioned signed run/rise triangle.
Raw diagnostics for explicit two-port joints can be captured as immutable,
source-fingerprinted revisions; unchanged inputs reuse the latest revision and
changed inputs display `STALE`. Supported direct P2P wall roots also expose a
pose-derived β comparison and a two-origin axial station projection, including
the raw perpendicular distance from the branch root-port point to the header
centreline; neither resolves wall contact or creates an acceptance verdict.
This is not full FR-ENG-16: other fitting variants, wall-surface geometry,
broader dependent-check coverage, native Autodesk Plant 3D CTPL/PCAT delivery,
and engineering acceptance rules remain open. The normative functional
requirement is [SRS `FR-ENG-16`](SRS-EPCC-Platform.md)
(Must); work is tracked in [P-26](IMPROVEMENT-PROPOSALS-EN.md). Related piping
model: [ADR-006](ADR-006-Piping-Line-Component-Joint-Model.md) §§14–16.

## Context

The current piping model records branch-root location (station/offset) and,
for direct P2P roots, the explicit joint-level longitudinal angle β. It does
not yet persist a circumferential clocking θ value or a general component pose.
The location and β fields describe one branch connection; neither defines a
general component's spatial pose or transforms its local port geometry. The
read-only PCF parser is likewise not a user-facing manual orientation workflow
and does not apply model geometry.

The owner identified a broader project need: users must be able to enter
component slopes and rotations manually, and the system must perform the
resulting calculations. This applies to supported model components beyond
branch joints; the branch-specific β field (and any future θ field) cannot
satisfy it.

## Decision

The platform SHALL provide a component-level manual orientation capability for
supported model component types:

1. An authorized user can enter and update a component's slope/inclination and
   rotations about the applicable axes without requiring a CAD, PCF, or other
   model import to supply those values.
2. Orientation inputs are interpreted against an explicit, recorded
   coordinate/reference frame. The platform does not guess an unstated frame,
   axis, sign, or zero datum.
3. The system resolves the input into a 3D component pose and transforms local
   geometric features—including port and connection-point locations and
   directions—into the model coordinate frame.
4. When orientation changes, affected connection/joint geometry and applicable
   geometry-dependent checks are recalculated or explicitly reported as
   unresolved/stale; the system must not silently retain results based on the
   old pose.
5. Manual orientation is a model-data capability, not native 3D CAD authoring.

This requirement is component-level and broader than the P2P joint-level β
field and any future branch-specific θ contract. A future implementation must
reconcile the general pose/transform model with branch-specific orientation
fields; it must not treat θ as a universal component rotation or infer an
unapproved circumferential convention.

## Acceptance criteria for implementation

- Slope/inclination and rotation can be captured on the modelled component,
  rather than only as properties of one branch joint.
- The reference frame and necessary units/conventions are explicit and retained
  with the input. Missing or ambiguous orientation references are surfaced,
  not silently defaulted.
- Given a component's local geometry and an accepted orientation input, the
  system produces deterministic model-frame component/port positions and
  directions that match known-answer geometry cases.
- Editing an orientation refreshes or marks affected connection/joint geometry
  and geometry-dependent validation results; unrelated physical topology is
  not silently inferred from a drawing projection.
- Focused tests cover representative positive/negative inclinations,
  rotations on the supported axes, connected geometry, and regressions for
  branch-specific β behavior without implicit θ conversion. For the selected
  dimensioned-triangle adapter, test known-answer signed rise/run, hypotenuse
  closure, and refusal to infer a missing azimuth plane or clocking datum.
- The initial diagnostic catalogue is transformed port points/directions plus
  raw two-port gap and axis residuals where both sides resolve. Additional
  discipline-specific checks and any PASS/FAIL limits require an applicable
  engineering rule; the design does not invent a universal fit-up tolerance.

## Selected technical contract (2026-10-26)

The following contract closes the initial frame, anchor, angle, and transform
conventions for manual piping-component orientation. It is a design decision,
not a claim that the end-to-end capability or every component geometry profile
has been built.

### Project and component frames

- Each project has an explicit, versioned **project model frame**. It is a
  project-local plant grid, not an implicit geodetic CRS: right-handed axes are
  `+X = project Easting`, `+Y = project Northing`, and `+Z = elevation up`.
  The model origin is declared for that project. Coordinates used by the pose
  kernel are millimetres (`MM`); imported/drawing units require an explicit,
  recorded conversion. A frame identifier/revision travels with every pose;
  no frame is shared or inferred across projects.
- Every supported component geometry profile declares a right-handed local
  frame, local anchor, primary axis, clocking datum, and each port's local
  point and outward unit direction. The primary axis is local `+X`; local
  `+Y` is the profile's zero-clock datum and local `+Z = +X × +Y`. The pose
  translation is the model-frame coordinate of the profile's anchor.
- For a straight `PIPE`, the initial profile uses port 1's centre as the local
  origin, local `+X` toward port 2, and port 2 at `[length_mm, 0, 0]`; port
  outward directions are `-X` and `+X`. `length_mm` is the explicitly
  modelled pipe cut length. For a straight `NIPPLE`, a separate profile uses
  the same two-port centreline geometry but its own profile ID; for this
  profile, the explicit `length_mm` input means end-to-end distance between
  port centre planes. It does not model thread form, engagement/insertion,
  wrench flats, or an external solid. Both profiles return
  `UNRESOLVED/MISSING_LENGTH` rather than inventing port 2 when length is
  absent. Roll has no geometric effect on these round, coaxial-port profiles;
  any non-axisymmetric feature needs its own explicit local clocking datum.
  A fitting is supported only when a complete, versioned local profile
  supplies its anchor and every port point/direction. Port roles, a generic
  take-out, line sequence, or P2P station/offset alone are not a substitute
  for local/global coordinates.

### Manual orientation input and matrix

The user-facing manual form is **azimuth, inclination, roll** in degrees; it
is not a three-Euler-angle sequence, so no Euler composition order is implied.

- Azimuth `α` is measured from project `+X` toward `+Y` about `+Z`, normalized
to `[-180°, 180°)`. Inclination `ε` is measured from the horizontal `XY`
plane toward `+Z`, with range `[-90°, 90°]`. Roll `ρ` is a right-handed
rotation about the oriented component-local primary `+X`, normalized to
`[-180°, 180°)`. Positive roll maps the zero-clock local `+Y` toward local
`+Z`.
- At zero roll, the model-frame basis is
  `x = (cos ε cos α, cos ε sin α, sin ε)`,
  `y₀ = (-sin α, cos α, 0)`, and `z₀ = x × y₀`. For roll `ρ`,
  `y = cos ρ · y₀ + sin ρ · z₀` and
  `z = -sin ρ · y₀ + cos ρ · z₀`. `R` is the matrix whose **columns** are
  `x, y, z`; it maps local column vectors into the project model frame:
  `p_model = R · p_local + t`, `d_model = R · d_local`.
- Azimuth remains required at a vertical inclination: there it defines the
  transverse/clocking datum, not a horizontal direction. This removes the
  otherwise ambiguous roll reference for a vertical component.
- A dimensioned slope triangle is an alternate input adapter, not another
  rotation convention. It must name its vertical plane by azimuth; horizontal
  `run_mm` is non-negative along that azimuth, `rise_mm` is signed along `+Z`,
  and `ε = atan2(rise_mm, run_mm)`. If a measured hypotenuse is supplied,
  report `measured - hypot(run, rise)` as a closure residual. Side lengths or
  a projected/unscaled drawing triangle alone cannot provide sign, plane, or
  clocking; those inputs are refused as under-specified.

### Geometry, change, and validation rules

- A versioned reference geometry profile is the default source. A reviewed,
  evidenced instance override can be selected explicitly. If a later import or
  profile revision conflicts with the selected geometry, flag
  `CONFLICT_REVIEW`; do not silently choose a source or reinterpret raw PCF
  attributes as a universal pose. Manual entry remains available without PCF.
- Persist raw input, frame ID/revision, convention version, anchor, geometry
  profile ID/revision/hash, actor/time, and the resolved transform as a new
  immutable pose revision. A changed pose/profile/frame makes dependent
  derived results `STALE` until recalculated. Pose edits are allowed only
  while the owning line is `DRAFT`; AFC/released changes must first use the
  existing controlled return-to-DRAFT/re-approval path.
- When both sides of an explicit two-port joint have resolved local geometry
  and pose, calculate the port-point gap and outward-axis mismatch as raw
  diagnostics. Do not create/delete joints, snap positions, change line/joint
  ownership, or auto-discover topology. A wall-reference joint whose host
  surface/global point is not represented remains `UNRESOLVED`, not guessed.
- The matrix validator's `1e-8` orthonormality/determinant tolerance is
  numerical only. No universal engineering fit-up or angular acceptance
  tolerance is selected: until a joint/type/project rule supplies one, report
  geometry residuals as `UNASSESSED`, never as a fabricated PASS/FAIL.
- P2P `branch_longitudinal_angle_deg` remains the explicit joint-level β.
  Any pose-derived β is diagnostic only and cannot backfill or overwrite it.
  θ remains branch-specific and is not a general roll. PAD remains outside
  this integration and continues to use its existing centred β=90° geometry;
  no component pose or θ is consumed by PAD calculations.

## Initial math checkpoint — pure orientation and geometry adapters (2026-10-26)

This was the calculation-only checkpoint before the persisted application slice
recorded below. Seven lower-level mathematical helpers, the selected manual-orientation
adapter, a minimal straight-pipe profile, and a pure port-geometry transformer
are present in the working tree with focused tests. The focused P-26 PHP unit
tests pass (72 tests, 277 assertions); PHP syntax checks and Pint also pass for
the 19 P-26 service/test files. No full suite was run. These services remain a
calculation layer only and do **not** implement FR-ENG-16 end-to-end, persisted
component poses, project-frame registry, UI, connected-joint checks, or
dependent-geometry refresh:

- `ComponentTransform3D` accepts an explicit row-major 3×3 matrix `R` from a
  caller-defined local frame to a caller-defined model frame, plus a model-frame
  translation `t`. With column vectors it applies `p_model = R p_local + t`
  to points and `d_model = R d_local` to directions. It accepts only a proper,
  right-handed orthonormal rotation (orthonormality and determinant `+1` checked
  to `1e-8`); scale, shear, and reflection are refused. This numerical
  tolerance validates the matrix only; it is not an engineering geometry
  tolerance. The service does not identify either frame, choose axes/origin or
  anchor, attach units, or convert a slope/angle into `R`.
- `AxisAngleRotation3D` builds a row-major matrix with Rodrigues' formula from
  a finite non-zero axis already expressed in a caller-defined basis and a
  finite degree angle. It explicitly means a right-handed active rotation on
  column vectors, matching `ComponentTransform3D`. The 360-degree reduction is
  only for the periodic trigonometric calculation; this helper does not choose
  project axes, map drawing input, or define a user-entry/pose convention.
- `FramedRigidTransform3D` wraps that math in opaque source/target frame IDs
  and an explicit unit label. It refuses point transforms with mismatched frame
  or unit tags, composes only matching intermediate frames with identical
  units, and provides an inverse. IDs and units are exact labels only: this
  class defines no frame registry, axis basis, origin, conversion, or storage;
  any unit conversion must happen explicitly before use.
- `TriangleAngleCalculator` takes three positive true side lengths in one
  common unit, with the two adjacent sides and opposite side identified by the
  caller, and returns only their unsigned included angle by the law of cosines.
  It does not interpret hatching, assign a reference plane/sign, produce a
  component pose, or imply β/θ. The selected triangle adapter must supply the
  declared azimuth plane and signed run/rise; it was not yet implemented at this
  initial math checkpoint (see the later persisted implementation checkpoint).
- `PlanarRunRiseAngleCalculator` accepts signed `run` and `rise` coordinates
  in one caller-defined orthogonal plane and common length unit, and returns
  `atan2(rise, run)` in degrees relative only to those supplied directions. A
  zero coordinate is valid; the zero vector is refused. It does not assign
  drawing signs, select the reference plane/azimuth or model axes, or create a
  3D pose/β/θ. The caller remains responsible for the input unit and sign map.
- `RunRiseHypotenuseClosureCalculator` accepts signed `run`/`rise` coordinates
  and a positive measured hypotenuse in one caller-supplied common length unit.
  It calculates `hypot(run, rise)` and reports `measured - calculated` as a raw
  residual, without a tolerance or pass/fail decision. It does not interpret
  the drawing, select the plane/signs, or establish engineering acceptance.
- `BranchAxisAngleCalculator` measures explicitly supplied, non-zero header/run
  and branch axes that are already in one shared frame. It reports
  `β = acos(abs(unit_header · unit_branch))` in `[0°, 90°]`, and separately
  returns the angle between the oriented input vectors, their signed
  projection, and the branch vector's transverse direction. That transverse
  vector does not establish a circumferential zero or θ; its `1e-12` near-axial
  cutoff is only a numerical guard for the vector result, not an engineering
  angle tolerance. This is measurement only: it neither enforces the existing
  P2P input range of 45°–90° nor infers β from station/offset, changes the
  stored joint value, or feeds PAD geometry.
- `ManualComponentOrientation3D` implements the selected azimuth/inclination/
  roll formula as a pure adapter and can package it as a `FramedRigidTransform3D`.
  It normalizes periodic azimuth/roll, bounds inclination, and does not persist
  input provenance or select a project frame ID.
- `StraightPipeGeometryProfile` supplies the initial in-memory profile
  `STRAIGHT_PIPE_TWO_PORTS_V1` (revision `1`, `MM`): port 1 is the local origin,
  local `+X` points toward port 2, and the clocking datum is local `+Y`. A
  missing length returns `UNRESOLVED/MISSING_LENGTH` with no invented port
  coordinates; a finite positive length resolves both straight-pipe ports.
- `LinearTwoPortComponentGeometryProfile` adds a distinct
  `STRAIGHT_NIPPLE_TWO_PORTS_V1` profile (revision `1`, `MM`) for the reference
  `NIPPLE` template only. It requires the explicit end-to-end port-centre
  distance in `length_mm`; a missing length has no resolved ports. It does not
  model nipple thread engagement, wrench flats, or external solids. Elbows and
  other fittings are not routed through this straight profile.
- `ComponentPortGeometryTransformer` transforms resolved profile points and
  outward unit directions through a framed pose. It checks exact profile/pose
  units, the ADR-061 `+X`/`+Y` local-axis convention, an origin anchor, unique
  positive port sequences, and unit port directions. It reports transformed
  coordinates only; it does not calculate joint residuals or engineering
  acceptance.
- These pure adapters do not themselves own persistence, authorization,
  topology, or lifecycle. Their outputs are now consumed by the persisted
  `ComponentPoseService` vertical slice described below; they still do not
  change `ComponentService` topology/ownership or PAD calculations. The current
  joint-level β validation and the PAD's centered β=90° restriction remain
  unchanged.
- Focused tests cover the lower-level β, matrix, axis-angle, frame, triangle,
  and run/rise helpers, plus manual-orientation known answers, a frame-tagged
  point transform, straight-pipe port transformation, roll invariance,
  missing-length unresolved result, and invalid profile/unit/vector cases.
  The selected P-26 unit-test files pass (72 tests, 277 assertions); this is not
  a full-suite run and does not validate persistence, connected geometry, or the
  engineering workflow.

The seven lower-level mathematical helpers remain intentionally separate
from the selected manual input contract; `ManualComponentOrientation3D` now
constructs that convention's matrix in memory, but neither the input nor pose
is persisted. The selected manual interface is not Euler, quaternion, or free
axis-angle entry. Branch-specific θ zero-direction and any use of θ remain
unselected and require their own decision; nothing here selects a B31.3
formula or changes the PAD geometry contract.

## Implementation checkpoint — initial persisted straight-PIPE vertical slice (2026-10-26)

The following application path is now implemented and covered by focused
feature tests; it is a partial delivery, not completion of all FR-ENG-16
acceptance criteria:

- `ProjectModelFrameService` stores immutable, project-scoped ENU/MM frame
  revisions with explicit origin descriptions, tenant RLS, authorization via
  `equipment.maintain`, and a frame-change guard when a pose is attached to an
  AFC-confirmed or released line.
- `ComponentPoseService` stores immutable pose revisions for straight `PIPE`
  components, including raw azimuth/inclination/roll or the full raw
  dimensioned-triangle input and calculation-method provenance, model-frame
  anchor, current frame revision, geometry-profile revision/hash, transform and
  a snapshot of transformed port points/directions. An optional measured
  hypotenuse is retained with its signed raw closure residual; no tolerance or
  PASS/FAIL verdict is applied. Pose writes require the `equipment.maintain`
  capability and a DRAFT line. Missing pipe length is saved as
  `UNRESOLVED/MISSING_LENGTH`, with no invented port locations.
- Current geometry is resolved on read against the latest frame and current
  straight-pipe length/profile. A changed frame or profile returns `STALE` and
  withholds old port coordinates; recording a new pose revision refreshes the
  current result. The immutable historical pose snapshot remains unchanged.
- `ComponentController` exposes `piping.model.frame` and `piping.model.pose`
  POST routes. The bilingual piping model page offers two explicit pose-input
  modes: azimuth/inclination/roll, or a dimensioned signed run/rise triangle
  with mandatory plane azimuth and roll plus optional measured hypotenuse. The
  triangle mode derives inclination with `atan2`, retains raw dimensions and
  method/unit provenance, and reports (but does not judge) any closure residual.
  Authorized users can inspect resolved model-frame port points/directions and
  pose provenance. Other component templates have no local profile yet and are
  explicitly reported as unsupported rather than assigned guessed geometry.
- `JointPortGeometryDiagnosticService` reads only existing joints with two
  explicit component ports. When both endpoint profiles/poses resolve, it
  reports raw point gap in `MM` and outward-axis deviation from opposition.
  Without a sourced acceptance rule it remains `UNASSESSED`; it never applies
  an invented tolerance, mutates the joint, or infers topology. Missing/stale
  endpoint geometry is surfaced as `UNRESOLVED`/`STALE`; a wall reference
  remains `UNRESOLVED` without host-surface geometry. β remains the explicit
  joint field, θ is not inferred, and PAD remains outside this path.
- `JointGeometryDiagnosticRevisionService` records an explicit snapshot through
  the DRAFT-only `piping.model.geometry-snapshots` action. Each append-only,
  RLS-protected revision stores method/version, source JSON and a SHA-256
  fingerprint plus the raw diagnostic. It reselects the line's current joints
  and recalculates endpoint geometry after acquiring project/line/joint locks;
  it does not persist a diagnostic prepared from a pre-transaction page read.
  Repeating unchanged inputs is idempotent; any source or method change
  displays the historical revision as `STALE` until a new capture. Database
  constraints permit only `UNASSESSED`/`UNRESOLVED`/`STALE`, never PASS/FAIL;
  the history has no joint foreign key and survives DRAFT topology removal.
  No topology, joint owner, β, θ or PAD data is changed.
- Focused suites pass: `ComponentModellingTest` **65 tests / 350 assertions**,
  `BranchWallJointTest` **27 / 93**, and the selected P-26 math/profile unit
  files **78 / 298**. Pint and PHP syntax checks pass for the current slice.
  No full application suite was run. Wider sourced profiles, host-surface
  geometry and engineering acceptance rules remain open.

## Historical follow-on profile checkpoint — straight NIPPLE; elbow not yet implemented (2026-10-26; superseded below)

A separate, limited profile was added for the straight `NIPPLE` template. The
reference template defines `hasLength: true` and exactly two `RUN` ports; the
component record persists an explicit `length_mm`. The ADR now defines that
input, for this profile only, as the end-to-end distance between port centre
planes. Port 1 is the local origin, `+X` points to port 2, and outward axes are
`-X`/`+X`. This is a port-centreline calculation, not a thread-engagement or
external-shape model. The profile is separately versioned as
`STRAIGHT_NIPPLE_TWO_PORTS_V1`; a missing length leaves both port coordinates
unresolved. Both selected pose input modes remain DRAFT-only. A changed length
makes the prior result `STALE` until a new pose revision is recorded.

No elbow profile was added. The reference `ELBOW` template's qualifier list
(`90LR`, `90SR`, `45`, `180LR`) is not stored on the component instance, whose
persisted shape provides only the generic two `RUN` ports. The B16.9 reader's
centre-to-end values do not by themselves specify a complete local anchor,
port directions, bend plane, or clocking reference. Therefore a dimensioned
elbow, including LR 45°/90°, still cannot be resolved unambiguously from the
current instance inputs. The pose service rejects `ELBOW`; it does not treat
centre-to-end as a straight length. No geometry is inferred for SR elbows,
tee/cross, reducers, or other fittings. This does not change explicit β,
introduce θ, or feed PAD.

Focused verification passed: the complete `ComponentModellingTest` run was **67
/ 367** (including NIPPLE pose/staleness and ELBOW rejection), and
`ComponentPortGeometryTransformerTest` was **13 / 84**. After broadening the
live joint-diagnostic fixture to two SC-ended NIPPLEs, that focused case passed
**1 / 10**. PHP syntax checks and Pint passed for the seven changed PHP files;
the full application suite was not run.

## Implementation design — selected contract and remaining build work

The project frame, manual angle convention, anchor rule, and transform
semantics are selected above. The first schema, UI, and runtime vertical slice
is recorded in the checkpoint above. This section records implementation
boundaries and the remaining profile, diagnostics, and acceptance work.

### Separate geometry, pose, connection, and β

1. **Reference/local geometry** supplies each component port's local point and
   outward axis, plus a secondary local datum only where a non-axisymmetric
   feature needs clocking. A local-geometry provider should resolve this from a
   versioned component template, size/variant and dimensional source, or an
   explicitly reviewed instance override. The present universal component
   template pack defines port sequence/role/end type, not local coordinates or
   axes; the B16.9 fitting-dimensions pack supplies dimensional take-outs, not a
   complete generic 3D port-pose catalogue. Neither source alone currently
   resolves every modelled component into local 3D geometry.
2. **Component pose** belongs to the component instance, not a joint. It maps a
   named component-local frame into a named model/reference frame. Its anchor
   must be defined by the local-geometry source; translation locates that
   anchor, and orientation rotates local geometry. The resolved pose must carry
   frame identity, units, input provenance and a revision/convention version.
3. **Resolved port geometry** is derived by transforming local port points and
   directions. The pure `ComponentPortGeometryTransformer` checks the profile's
   local-axis/anchor contract, exact units, port sequence and unit directions.
   For the supported straight `PIPE`/`NIPPLE`, B16.9 BW LR, and separate
   B16.11 forged-elbow profiles, `ComponentPoseService` persists transformed
   model-frame points/axes in the immutable pose snapshot, fingerprints current
   sources (including B16.11 standard/class/port ends), and checks frame/profile
   revisions on read. B16.11 resolves only exact two-ended SW or threaded rows;
   other fitting profiles and dependent-check classes are not implemented.
4. **Joint topology and ownership** remain the existing manually created model.
   Geometry checks may report a gap, axis mismatch, or unresolved fit, but they
   must not create/delete joints, move components between lines, or change the
   explicitly selected joint owner. A pose edit must not silently rewrite a
   fitted/released engineering record.
5. **P2P β remains joint-level and explicit.** `branch_longitudinal_angle_deg`
   continues to mean the smaller angle between the P2P branch and header axes,
   limited by the existing path to 45°–90°. It is not a component roll or a
   general pose input. If both port axes are later resolvable, a pose-derived
   `β_geometry` may be calculated as a separate diagnostic; it must not
   backfill or overwrite the explicit field until an approved mismatch and
   review policy exists. Station and offset remain position only. θ still has
   no stored field and may not be inferred without an approved circumferential
   datum; the transverse vector from the math helper does not define that zero.
6. **PAD remains outside this integration.** It stays limited to its current
   recorded β=90°, zero-offset case; neither θ nor general component rotation
   is consumed by PAD geometry until a separate validated method is approved.

### Data and change behavior

- The implemented manual form is azimuth/inclination/roll; it persists those
  raw values, units, frame ID/revision, anchor and convention version alongside
  the resolved pose. A dimensioned triangle is accepted only when its signed
  rise, positive horizontal run, azimuth plane and true dimensions are
  explicit. For a right triangle, `atan2(rise, run)` gives inclination and an
  optional measured hypotenuse gives a closure residual. Triangle side lengths
  alone, projected pixel lengths, or hatching cannot supply sign, plane or
  clocking. The selected bilingual run/rise input is now implemented for the
  supported `PIPE`/`NIPPLE`, B16.9 BW LR, and B16.11 forged-elbow pose paths.
  It persists the raw dimensions, explicit plane and roll, method/version,
  units, calculated hypotenuse, and
  optional measured hypotenuse/residual; the residual remains `UNASSESSED`
  without an approved tolerance.
- Treat PCF as coordinates plus component-specific attributes, not as one
  universal component-pose field. `END-POINT`, `CENTRE-POINT` and branch-point
  records place keypoints in the file coordinate frame (with units declared by
  `UNITS-CO-ORDS`). PCF `ANGLE` describes a non-default fitting/bend angle for
  specified component types and is encoded in hundredths of a degree; it is
  not a world-space rotation. `SPINDLE-DIRECTION` and `FLAT-DIRECTION` describe
  specific valve/reducer features, while `DIRECTION` is also feature-specific
  (for example, a valve spindle or eccentric-reducer flat), not a generic pose.
  See [PTC's PCF identifier reference](https://support.ptc.com/help/creo/creo_pma/r12/usascii/piping/piping/Creating_Pipe_Component_Information__Spec-Driven.html).
  EPCC's current `PcfParser` parses keypoints and retains other attributes raw;
  it does not resolve these direction fields into a general transform and the
  admission path remains read-only. An importer must interpret them by
  component type, preserve units/provenance, and refuse to invent missing
  orientation data.
- Keep geometry provenance/version separate from the component's coarse
  `source` value. A versioned reference profile is the default; an evidenced
  instance override is selected explicitly for that instance. Conflicts with
  a later reference revision or import become `CONFLICT_REVIEW`; neither
  source silently replaces the selected profile.
- Make transformed port geometry and dependent checks reproducible from their
  source geometry and pose revisions. A pose/source edit must either refresh
  affected results or mark them `STALE`/`UNRESOLVED`; it must not leave a
  previously valid result appearing current. Fitted/released dependants need a
  revision/review path before values can affect construction records.
- Keep geometric coincidence as a validation signal, not automatic topology
  discovery. This preserves the current explicit joint-creation, ownership,
  and line-boundary rules.

### Implementation items still open (contract decisions are closed)

| Work item | Selected boundary | Remaining implementation |
|---|---|---|
| Project frame | Per-project, versioned, right-handed local Easting/Northing/Up frame in `MM`; origin explicit | Frame registry, origin description capture, RLS, authorization, revisioning and frame-change guard implemented; explicit unit-conversion evidence remains open |
| Component anchors and geometry | Versioned straight-`PIPE`/`NIPPLE`, B16.9 BW LR, and separate B16.11 forged-elbow profiles resolve exactly two RUN ports; missing reference rows return `UNRESOLVED` | B16.11 is limited to exact 45°/90° SW-SW or SC-SC NPS/class rows; SW port datum is the application-defined socket bottom using A directly, J remains separate; threaded uses A/C directly. Do not infer from sequence, station/offset, LR/SR, or take-out alone |
| Pose persistence | Raw angles or complete triangle input + anchor + frame/profile versions resolve to immutable pose revisions for the supported `PIPE`/`NIPPLE`, B16.9 BW LR, and limited B16.11 profiles | RLS-protected persistence, latest-revision selection, provenance/hash, B16.11 standard/class/end-type fingerprinting, raw triangle/method data and optional closure residual are implemented; other fitting variants remain open |
| User entry | Bilingual azimuth/inclination/roll and signed dimensioned run/rise modes with explicit plane, roll and anchor, under the DRAFT gate | Both input modes, optional measured hypotenuse, raw triangle/method provenance and non-verdict closure residual are implemented for the supported `PIPE`/`NIPPLE`, B16.9 and limited B16.11 elbow profiles; broader variants remain open |
| Derived geometry | Transformed ports for supported `PIPE`/`NIPPLE`, B16.9 BW LR elbows and exact covered B16.11 elbows plus raw two-port gap/axis-deviation diagnostics; explicit DRAFT-only capture stores immutable, source-fingerprinted joint revisions and displays changed sources as `STALE` | Broaden supported fitting profiles and dependent checks; wall geometry stays unresolved without host-surface data |
| Engineering acceptance | Matrix tolerance is `1e-8`; fit residuals remain `UNASSESSED` without a joint/type/project rule | Define and source each applicable linear/angular acceptance tolerance; do not invent a global one |
| Change control | Pose writes only on `DRAFT`; frame changes are guarded after poses on non-DRAFT lines; changed frame/profile hides old coordinates as `STALE` | Integrate any later fitted/released dependant review and refresh transactions without bypassing the existing return-to-DRAFT/re-approval path |
| Branch β / θ | Entered β remains authoritative; θ is not inferred and PAD does not consume pose | A separate pose-derived β diagnostic is implemented only for direct P2P stub-in/stub-on wall roots when current header/branch `PIPE` poses resolve in one frame/`MM`; it reports raw geometry β and signed geometry-minus-recorded Δ as `UNASSESSED`, is fingerprinted/STALE, and never backfills or overwrites explicit β. TEE/CROSS, OLET, θ, PAD consumption, and acceptance/mismatch verdicts remain out of scope |
| PCF/import | Feature-specific attributes are not generic pose | Any future writer must explicitly map component type, coordinate units and provenance; current parser remains read-only |

### Implementation gates and remaining work

1. **Delivered for the selected angle inputs:** the azimuth/inclination/roll
   adapter and explicit signed run/rise triangle adapter have known-answer
   tests and are used by immutable `PIPE`, `NIPPLE`, supported BW LR, and
   covered B16.11 elbow pose revisions with raw input, method/version and unit
   provenance retained.
2. **Delivered for limited sourced profiles:** the versioned `PIPE` profile
   uses its modelled pipe cut length; `NIPPLE` uses the explicitly declared
   end-to-end port-centre distance; B16.9 BW LR uses Table 6.1-1 centre-to-end;
   B16.11 threaded uses Table 6.1-3 A/C centre-to-end, while B16.11 SW uses
   Table 6.1-1 A to the application-defined socket-bottom port datum and keeps
   J separate. All use the explicit local bend-plane contract. Missing rows
   remain `UNRESOLVED`; changed sources display `STALE`. Add further variants
   only when the instance qualifier, anchor, bend plane/clock datum, and every
   port point/direction are explicit in a complete reviewed profile.
3. **Delivered for the initial frame/pose slice:** per-project frame registry,
   immutable revisions, RLS, exact frame/profile revision and DRAFT-only pose
   writes; origin changes are guarded when any pose is on a non-DRAFT line.
4. **Delivered for current two-port joints:** bilingual manual entry and port
   output plus raw gap/axis diagnostics; an explicit DRAFT-only capture writes
   append-only, RLS-protected, source-fingerprinted revisions and changes to
   inputs display `STALE`. Topology/ownership remain untouched. Wider profiles,
   host-surface geometry and other dependent-check classes remain open.
5. Keep wall-contact geometry unresolved until host-surface geometry exists;
   keep fit-up and β-difference results `UNASSESSED` until a cited
   type/project acceptance rule is configured. The implemented pose-derived β
   is a separate diagnostic only for supported P2P stub wall roots with current
   `PIPE` poses in a common frame. Do not write/backfill explicit β, infer θ,
   or feed any result to PAD.

## Scope boundaries and consequences

- The requirement is registered as SRS `FR-ENG-16` (Must) and P-26. ADR-006
  §14 states that the existing β field and any future branch-specific θ are
  limited to the branch-joint contract.
- The current vertical slice does not complete all of FR-ENG-16, add a
  PCF-to-model writer, or replace specialist CAD authoring tools. It implements
  persisted frame/pose revisions, DRAFT-only straight-`PIPE`, explicit-length
  `NIPPLE`, scoped B16.9 BW LR elbow workflows, and the exact-covered B16.11
  two-ended forged-elbow workflow, with transformed-port output, both selected
  manual input modes, raw two-port diagnostics and explicit DRAFT-only
  source-versioned snapshots. It is not a full supported-component geometry
  catalogue, a native Autodesk Plant 3D CTPL/PCAT deliverable, or a fit-up
  acceptance system. Report P-26 as `PARTIAL`, not `BUILT`.
- The profiles remain limited port-centreline calculation contracts, not a
  production geometry catalogue for every template or size variant. B16.9
  coverage remains BW `90LR`/`45LR` from the 2018 Table 6.1-1. The separate
  B16.11-2021 path covers only exact two-ended 45°/90° SW or threaded NPS/class
  rows: SW uses A to the application-defined socket-bottom datum and retains J
  separately; threaded uses A/C center-to-end. It does not alias LR/SR, infer
  unlisted class/size pairs, apply the approximate pre-weld gap, support mixed
  ends or street elbows, or generate CAD solids. Short-radius, 180-degree,
  reducing elbows, returns, and other fittings remain unsupported. The local
  bend-plane/clocking convention is recorded and roll must be explicit; a
  missing qualifier or reference dimension does not produce guessed
  coordinates. Wider profile coverage, wall-surface geometry, and engineering
  acceptance rules remain explicit gates, not inferences from rotation
  mathematics.

## Decision history

- **2026-10-26 — accepted requirement:** owner directed that users be able to
  enter component slopes and rotations manually and that the system calculate
  the resulting geometry. The requirement was registered in SRS and tracked
  in P-26.
- **2026-10-26 — initial technical contract selected under owner direction to
  close the rotation design:** project-local Easting/Northing/Up frame in mm;
  explicit component anchor/profile; azimuth/inclination/roll input and direct
  local-to-model basis formula; versioned geometry/pose provenance; no topology
  discovery; DRAFT-only edits; and strict separation from branch β/θ and PAD.
- **2026-10-26 — initial application slice:** immutable project-frame and
  straight-`PIPE` pose revisions, bilingual entry/output, stale-source handling
  and read-time explicit-joint diagnostics implemented.
- **2026-10-26 — derived-diagnostic snapshot slice:** added immutable,
  RLS-protected, revisioned joint diagnostic snapshots with source fingerprints,
  idempotent recapture for unchanged inputs, explicit `STALE` display after
  source changes, and DRAFT-only capture. Wall references remain `UNRESOLVED`
  without host-surface geometry; P-26 remains `PARTIAL` pending wider profiles,
  broader dependent checks and sourced acceptance rules.
- **2026-10-26 — straight NIPPLE follow-on profile:** added a separately
  versioned two-port centreline profile using the explicitly declared
  end-to-end port-centre distance. At that checkpoint elbows remained
  unsupported because neither the instance qualifier/plane nor a complete
  local port profile was available; no fitting geometry was guessed.
- **2026-10-26 — B16.9 BW LR elbow follow-on profile:** persisted the instance
  qualifier, added source-backed `90LR`/`45LR` local two-port profiles from
  Table 6.1-1, and defined the local P1 anchor, +X axis, +Y bend datum, and
  explicit roll-controlled bend plane. The tabulated centre-to-end is not
  treated as a straight length; changed qualifier/reference/profile hashes
  make prior poses `STALE`. Other elbow variants remain outside this profile
  slice; the requested authentic Autodesk Plant 3D CTPL/PCAT artifact remains
  open because no catalog/template source file is present. Verification passed:
  `ButtWeldElbowGeometryProfileTest` (4/69),
  `ComponentPortGeometryTransformerTest` (13/84), and the focused
  `ComponentModellingTest` (70/403); reference-pack validation, PHP syntax and
  Pint also passed. Feature tests ran against an isolated verification DB;
  the main `epcc` database was left unmigrated, and no full application suite
  was run.
- **2026-10-26 — separate B16.11-2021 forged-elbow profile:** added a
  fingerprinted SI transcription of 63 exact source rows (27 SW, 36 threaded)
  from the supplied standard, then kept its lookup and profile separate from
  B16.9. Standard, 45°/90° angle, dimensional class, and one supported
  end-type for both ends are explicit; no PMS inference, LR/SR alias, mixed
  ends, street elbow, interpolation, or extrapolation is allowed. Threaded
  ports use Table 6.1-3 A/C directly. SW ports use an explicitly documented
  application datum at the standard-defined socket bottom with Table 6.1-1 A
  directly and J separate; neither A−J nor the approximate weld gap is used.
  Standard/class/end-type flow into component storage and pose fingerprinting;
  missing reference pairs remain `UNRESOLVED`, while source changes make old
  poses `STALE`. The B16.9 BW LR path remains separate. Reference validation,
  syntax checks, Pint, and focused PHPUnit checks passed: B16.11 geometry unit
  tests (6/79 assertions), B16.11 HTTP/pose feature tests (4/55), and B16.9
  regression feature tests (2/35). The feature tests used the separately
  configured `epcc_test` database, confirmed empty before the tests; their
  `migrate:fresh` and seed setup never targeted `epcc`. The sandbox had no
  PostgreSQL cluster before rebuild; the approved rebuild created the local
  development database and applied pending migrations only (no drop/reset,
  `migrate:fresh`, or seed). Read-only migration status confirms the B16.11
  migration ran. No full Laravel suite was run.
- **2026-10-26 — separate diagnostic β for supported P2P wall roots:** added a
  pose-derived β only when a root `STUB_IN`/`STUB_ON` direct wall joint has a
  header, no branch port B, and current resolved header/branch `PIPE` pose
  geometry in one frame and `MM`. The diagnostic reports the geometric value,
  recorded explicit β, and raw signed difference separately; source/method
  fingerprints make changed inputs `STALE`. It does not backfill/change the
  joint β, set a tolerance or verdict, infer θ, or feed PAD. OLET and
  TEE/CROSS are excluded, and wall-contact geometry stays `UNRESOLVED` without
  host-surface data. Focused checks passed: β unit tests (6/39, including V1
  snapshot staleness under the V2 input schema), P2P and
  dependent feature cases (7/127, including EN/FA β display),
  host-surface unresolved regression (1/14),
  Pint and PHP syntax for changed PHP files. Both databases were confirmed
  empty before tests; feature fixtures ran their normal `migrate:fresh`/seed
  setup only against isolated `epcc_test` (131 public tables / 128 migration
  rows remain afterward). The `epcc` development database remains empty and
  unmigrated. No full Laravel suite was run.
- **2026-10-26 — pose-derived P2P wall-station projection:** added a separate
  raw dependent check for direct `STUB_IN`/`STUB_ON` wall roots, gated on
  current resolved header/branch `PIPE` geometry in the same project frame and
  `MM`. It orthogonally projects the branch root-port point onto the straight
  header centreline and reports signed station candidates and raw
  geometry-minus-recorded deltas from both header port endpoints. The data
  model has no upstream-end selector, so neither candidate is selected and the
  result stays `UNASSESSED/UPSTREAM_ENDPOINT_NOT_IDENTIFIED`; it consumes no
  `branch_offset_mm`, invents no radial/contact-surface direction, and leaves
  the overall wall-contact diagnostic `UNRESOLVED`. P2P-root input snapshots
  include the method and use schema V3; the immutable capture fingerprints the
  joint and both current poses, so changed sources make it `STALE`. Unaffected
  explicit two-port snapshots retain their V2 input contract. The feature does
  not establish an acceptance
  tolerance, fit-up verdict, θ, PAD input, or topology change. Verification
  passed: `BranchStationProjectionCalculatorTest` (2 tests / 4 assertions),
  `JointPortGeometryDiagnosticServiceTest` (7 / 64), and the focused
  `ComponentModellingTest` file (75 / 518), including snapshot
  capture/staleness and EN/FA display. PHP syntax, Pint, Blade directive
  balance, and `git diff --check` also passed. Before fixture runs both `epcc`
  and `epcc_test` had zero public tables/migrations; the normal test refresh and
  seed setup ran only against `epcc_test`, which now has 131 tables / 128
  migration rows. The development `epcc` database remains empty and
  unmigrated. No full Laravel suite was run; P-26 remains `PARTIAL`.
