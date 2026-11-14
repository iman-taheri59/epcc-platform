# ADR-061 — Manual component orientation and geometry calculations

Status: **ACCEPTED — REQUIREMENT RECORDED; TECHNICAL DESIGN OPEN** (2026-10-26).
This ADR records the owner's required capability, not an implementation choice.
The normative functional requirement is [SRS `FR-ENG-16`](SRS-EPCC-Platform.md)
(Must); work is tracked in [P-26](IMPROVEMENT-PROPOSALS-EN.md). Related piping
model: [ADR-006](ADR-006-Piping-Line-Component-Joint-Model.md) §§14–16.

## Context

The current piping model records branch-root location and branch-specific
orientation: station/offset, P2P longitudinal angle β, and circumferential
clocking θ. Those values describe a branch connection; they do not define a
general component's spatial pose or transform its local port geometry. The
read-only PCF parser is likewise not a user-facing manual orientation workflow
and does not apply model geometry.

The owner identified a broader project need: users must be able to enter
component slopes and rotations manually, and the system must perform the
resulting calculations. This applies to supported model components beyond
branch joints; branch β/θ alone cannot satisfy it.

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

This requirement is component-level and broader than the P2P branch β/θ
contract. A future implementation must reconcile the general pose/transform
model with branch-specific orientation fields; it must not treat θ as a
universal component rotation or infer an unapproved circumferential convention.

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
  rotations on the axes supported by the eventual design, connected geometry,
  and regressions for branch-specific β/θ behavior.

The exact engineering calculation catalogue is not fixed by these criteria;
that is a required output of the technical design below.

## Technical design explicitly deferred

Before schema, UI, import-write behavior, or calculation code is designed, the
following contract must be made explicit:

| Topic | Decision still required |
|---|---|
| Coordinate frame and anchor | Project/model and component-local frames, handedness, origin/anchor, and how the anchor is associated with the line or connection |
| Slope representation | Angle, rise/run, percent, or other representation; units, sign, and supported range |
| Rotation convention | Applicable axes, zero/sign directions, local-vs-global interpretation, composition order, wrap/range, and user-facing input form |
| Geometry source and precedence | Template/local port geometry, manually entered values, and future CAD/PCF geometry; how revisions, conflicts, and corrections are represented |
| Numerical behavior | Tolerances, rounding, singular/degenerate cases, and how invalid or under-specified poses are reported |
| Derived calculations/checks | Which connection, joint, clearance, slope, and discipline-specific checks must be recalculated; their governing equations and acceptance rules |
| Change/review semantics | Versioning, provenance, audit, and the effect of a pose edit on fitted/released engineering records |

No Euler/quaternion representation, global axis convention, slope unit, θ
zero-direction, database schema, or B31.3 formula is selected by this ADR.
The current provisional branch θ convention remains subject to its separate
owner confirmation.

## Scope boundaries and consequences

- The requirement is registered as SRS `FR-ENG-16` (Must) and P-26. ADR-006
  §14 now states that its β/θ fields are only the branch-joint contract.
- This ADR does not implement general component orientation, choose formulas,
  authorize a PCF-to-model writer, or replace specialist CAD authoring tools.
- Implementation should follow a focused technical design that resolves the
  deferred items, then add component-level data entry, geometry calculation,
  validation, and targeted tests. Until then, the requirement is accepted but
  the capability must not be reported as implemented.

## Decision history

- **2026-10-26 — accepted requirement:** owner directed that users be able to
  enter component slopes and rotations manually and that the system calculate
  the resulting geometry. The requirement was registered in SRS and tracked
  in P-26; this ADR captures its scope and leaves implementation details open.
