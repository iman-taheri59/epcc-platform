# Research — how Plant 3D and the PCF format handle P2P (pipe-to-pipe) branches

Researched 2026-10-12 in support of the ADR-008 §9.1 `P2P` outcome and the
P-22 design decision (how a wall weld is represented in the joint model).
Sources are vendor documentation and community threads; URLs are listed per
finding.

## 1. AutoCAD Plant 3D

### 1.1 Stub-in is a first-class routing mode — no component

Autodesk's own support article answers exactly our question ("how to connect
a branch pipe **without a tee**, weld directly visible on the line"): the
solution is *«Use the option **STub-in** when routing the pipeline»*. The
connection created is pipe-to-pipe with no fitting inserted.
([How to connect a branch pipe without a tee in AutoCAD Plant 3D,
Autodesk Support, 2024](https://www.autodesk.com/support/technical/article/caas/sfdcarticles/sfdcarticles/How-to-connect-a-branch-pipe-without-a-tee-in-AutoCAD-Plant-3D.html))

### 1.2 The stub-in weld is called a "tap weld"

- Plant 3D internally names the stub-in weld a **tapweld**: *«the stub-in
  uses a tapweld. This type of weld has in fact the same SKEY (WS) and
  therefore the same block like the buttweld (which will be used by default
  for the tees) but not the annotation "FW"»* — i.e. the weld's SKEY is the
  ordinary butt-weld SKEY (WW shop / WS field); what marks it as a stub-in
  is the end treatment, not the weld symbol.
  ([Annotation "FW" not shown for stub-in branches, Autodesk
  KB](https://knowledge.autodesk.com/support/autocad-plant-3d/troubleshooting/caas/sfdcarticles/sfdcarticles/AutoCAD-Plant-3D-Annotation-FW-for-Field-or-Field-Fit-welds-in-isometric-drawings-will-not-be-shown-for-stub-in-branches.html))
- The branch end carries the end code **TAP**; the cut-piece table treats
  the stub-in pipe end as end-type **PL**, equivalent to BW for cut
  purposes: *«Stubins have a TAP PlantEndCodes, and they are otherwise also
  known as Tap Welds. According to the .pcf file, their welds have an
  SKEY=WW»* and *«the Cut Piece Table sees a stubin as a (PL) entype»*.
  ([What stubin skey affects a Cut Piece List?, Autodesk
  forum](https://forums.autodesk.com/t5/autocad-plant-3d-forum/what-stubin-skey-affects-a-cut-piece-list/td-p/7016395))
- The weld list reports the property value **tapweld** for these welds.
  ([Isometric stub-in welds indicator, Autodesk
  forum](https://forums.autodesk.com/t5/autocad-plant-3d-forum/isometric-stub-in-welds-indicator/td-p/6704997))

### 1.3 What Plant 3D does NOT do

- No automatic **reinforcement pad**: nothing in the stub-in flow inserts a
  pad or computes reinforcement; pads, where required, are separate manual
  items. (Corroborates proposal P-23: pad material must come from the class
  rule, never from the 3D import.)
- Known reporting quirks (FW annotation, stub dimensions on isos) are
  iso-config issues, not model issues — the connection itself is fully
  modelled.

## 2. The PCF / ISOGEN format

### 2.1 There is no "stub-in" component entity

PCF has no dedicated entity for a pipe-to-pipe branch weld. The standard
represents branches in three ways (PCF Reference Guide v13, 2016; the
relevant sections are quoted in
[the public reference copy](https://pdfcoffee.com/pc-f-reference-guide-9-pdf-free.html)):

1. **A fitting component** — `TEE` emits `BRANCH1-POINT`; the set-on
   variant is `TEE-SET-ON` with its `CENTRE-POINT` on the header. When the
   main pipe is *not* present in the file, the header bore is appended to
   the `CENTRE-POINT` record so ISOGEN can still size it.
2. **A tap connection** — the fitting-less case, i.e. our P2P stub-in:
   *«Direct connection to the pipe, typically using a weld at the
   connection point»* and — the connectivity rule that matters for P-22 —
   *«The start coordinate of the tap branch does not have to lie on the
   centerline of the pipe. However, it is important that the projection of
   the tap coordinate on to the pipe centerline lies within the end points
   of the pipe.»* The branch pipe's `END-POINT` simply begins at a point on
   the header **wall**; ISOGEN resolves the connection by **projection onto
   the header centreline**, between the header's end points. No port, no
   entity on the header side.
3. **An olet** as the first component of the branch run (weldolet etc.).

### 2.2 Welds are explicit or implied

*«There are two basic strategies for managing welds in the PCF… WELD
components are not included at all… Isogen implies the existence of a weld
based on its extensive built-in rule base»* — or a `WELD` entry is written
per occurrence (`SKEY WW/WS`, `CATEGORY FABRICATION|ERECTION`).

### 2.3 Cut length is resolved at iso time, not model time

ISOGEN Option Switch 2 selects how a branch cut piece is measured:
*«0 · Calculate to the actual branch connection point. 1 · Calculate to the
center of the main pipeline in all situations. 2 · Use the coordinate
position»* ([Isogen Option Switches Reference,
Hexagon](https://docs.hexagonppm.com/r/IsogenOptionSwitches)). CADWorx
exposes the same semantics as `TAP` (small branches) and `OTAP` (large /
offset, set-on pipe-to-pipe, SKEYs `TTSO/TSSO/TERF/TSRF`) — confirming that
**set-on P2P without a fitting is also a tap-style connection** in the
ISOGEN world ([Place an offset tap, CADWorx
docs](https://docs.hexagonppm.com/r/en-US/CADWorx-Plant/24/377045)).

### 2.4 Interoperability warning for our importer

Wall connections are the fragile part of PCF in practice:

- *«Because the PCF format does not explicitly describes an OLET component,
  All OLET components are mapped to tee type = WELDED»* and olets connect
  inconsistently — some systems place them on the centreline, others on the
  surface ([AutoPIPE wiki, o-let
  import](https://communities.bentley.com/products/pipe_stress_analysis/w/pipe_stress_analysis__wiki/20686/10-imported-pcf-file-containing-branch-connection-o-let-olet-weldolet-elbolet-threadolet-etc-are-not-connected-correctly-in-autopipe-model-why-and-how-to-avoid)).
- Plant 3D stub-in branches are a known import failure for third-party PCF
  consumers ([AutoPIPE forum: PCF import from Plant 3D — stub in branches
  not
  recognised](https://bentleysystems.service-now.com/community?id=community_question&sys_id=cdd2e76f979e4614afb952800153afc4)).

Our PCF importer (roadmap P7) must therefore resolve tap coordinates by
projection and **must not require a header-side port** — and on export we
should emit the same tap idiom we expect to read.

## 3. Consequences for the platform (P-22 status)

1. **The wall-joint representation is implemented.** A P2P branch joint stores
   the branch-side port plus a frozen header component/station/offset reference
   (ADR-006 §14); it does not synthesize a port on the header. PCF's
   `TAP-CONNECTION` is the matching source idiom, and its host UCI/location are
   now parsed read-only in both standard association forms (§5.2).
2. **Stub-in and stub-on remain class-governed kinds.** A PCF tap record by
   itself is not permission to infer `STUB_IN` versus `STUB_ON` from geometry.
   The issued PMS snapshot supplies the declared P2P kind; the joint stores that
   verdict beside its observed kind. Broad tee aliases are classified only
   when SKEY is sufficient (§5.3).
3. **Plant 3D's end-treatment and weld labels are source facts, not our class
   verdict.** A WELD entity/SKEY alone is ambiguous with an ordinary butt weld;
   a future importer must combine source evidence with the issued class rule,
   not guess from the weld symbol or coordinates.
4. **Pads are invisible to Plant 3D/PCF.** The class rule is the authority for
   pad requirement/thickness; the joint snapshot retains that thickness. Pad
   MTO rows, weld/NDT workflow and a commercial rate table are separate work.

**Deliberately not built in this PCF slice:** PCF upload, model-writing apply,
projection/geometry validation, UI for wall-joint creation, multi-leg CROSS-*
application, and MTO/costing workflows. The separate OLET wall-weld model path
is now documented in ADR-006 §15; this PCF research/parser remains read-only and
does not populate it. Read-only tap recognition is not an accepted model
import.

## 4. What Plant 3D's own model does (added 2026-11-10)

§1 answered "can Plant 3D make a fitting-less branch" from vendor documentation.
This section answers the harder question P-22 actually depends on: **does the
header gain a port?** It does not. Three things carry the connection, and one
axis of the location is stored while the other is not.

### 4.1 Stub-in is a routing mode that suppresses the fitting

`PLANTPIPEADD`: *«STub-in — Toggles stub-in mode. If you are creating a branch
using the continuation grip, setting stub-in mode removes the branch
fitting.»* No component is inserted; the header pipe is not split and keeps its
two ends. ([PLANTPIPEADD, Autodesk Plant 3D User
Guide](https://knowledge.autodesk.com/support/autocad-plant-3d/learn-explore/caas/CloudHelp/cloudhelp/2021/ENU/Plant3D-UserGuide/files/GUID-E1D7EC73-34DB-41A6-ADA1-8A9CE99D0F51-htm.html))

### 4.2 The branch table decides, and silence means no connection

The spec's branch table maps header size x branch size to a fitting: *«Part
Type: Specifies the fitting to use for the branch type. Specify pipe as the part
type for a stub-in.»* And decisively: *«Without an entry, Plant 3D does not
create a connection at that point.»* ([Branch Table Setup, Spec Editor
2026](https://help.autodesk.com/cloudhelp/2026/ENU/Plant3D-SpecEditor/files/GUID-D2BAB35F-98EB-4CD9-9A95-14722DA07F1E.htm);
[practice
questions](https://seitz-ingenieure.de/en/infobase-praxisfragen/)) This is the
same authority ADR-008 §9.1 gives our class rules.

### 4.3 The stored axis is the CIRCUMFERENTIAL one

`PLANTPIPEADD` → Connection Offset: *«Specifies an off-center or tangential
connection for stub-ins and olets… [Toptangent/Bottomtangent/Distance]…
Distance: Connects to a specified distance above the centerline. Enter a
negative number to specify a connection below the centerline.»* In practice:
*«I want to take a Stub-In 25mm below from the header center line»* →
`Offset connection to [Toptangent/Bottomtangent/Distance] <Distance>: 25`
([Stub in Connection from pipe center
offset](https://forums.autodesk.com/t5/autocad-plant-3d-forum/stub-in-connection-from-pipe-center-offset/td-p/13395376))

### 4.4 The along-axis position is NOT stored — it is geometric

The branch is routed to the header centreline and the connection is resolved by
projection. The evidence is in the workarounds: to avoid a gap in the exported
PCF one is told to *«First connect the branch with the same nominal size; the
line then extends to the centerline of the main line. Afterward, change only the
nominal size of the branch»*, and Autodesk's article on dimensioning a stub-in
*«from contact point instead of center line of the main pipe line»* points at
the Data Manager node *«Tap Weld»* — the contact point exists, but it is
derived. ([practice
questions](https://seitz-ingenieure.de/en/infobase-praxisfragen/);
[dimension a stub-in from contact
point](https://www.autodesk.com/support/technical/article/caas/sfdcarticles/sfdcarticles/How-to-dimension-a-stub-in-branch-from-contact-point-instead-of-center-line-of-the-main-pipe-line.html))

**This is the one place Plant 3D's answer cannot be copied.** We have no
geometry: `components` persists only `length_mm` and `sequence`, and nothing
stores a coordinate. Plant 3D can afford an implicit station because it is a CAD
system. We cannot — for us an implicit station is no station at all.

### 4.5 Another vendor's decomposition: the stub-in is an END TREATMENT of the branch

Cadmatic's spool/material file classifies pipe end cuts as *«1 = straight cut,
2 = slanted, 3 = concentric stub-in, 4 = eccentric stub-in»*, with *«additional
end cut length… if the pipe end… is a stub-in branch»*. On this reading the
stub-in lives entirely on the BRANCH pipe's end and needs nothing on the header
at all. *Eccentric stub-in* is Plant 3D's connection offset under another name.
([Cadmatic, material file for pipe spool
documents](https://docs.cadmatic.com/plant/Content/Administrating%20Cadmatic/documents/document_header_material_pipe_spools.htm))

This is the cheapest possible source-side fact, and it is independent of
P-22: mark the branch end. Our `end-types.ref.json` has no `TAP` end type — the
PCF `TAP-CONNECTION` is a separate connection record, not an end-type synonym.
The earlier parser's `TAP-POINT` handling was incorrect; §5.2 records the
correction. The current parser reads standard tap locations/host references
read-only, but does not apply them to a branch end or joint.

### 4.6 There IS a weld entity, and it is first-class

Deleting it is the documented way to separate a stub-in branch from its header:
*«you can first delete the buttweld connection from the stub-in connection (to
disconnect the branch from the header)»*
([branch table editor
problem](https://forums.autodesk.com/t5/autocad-plant-3d-forum/branch-table-editor-problem/td-p/3882252)).
`PLANTWELDADD` places a weld explicitly
([forum](https://forums.autodesk.com/t5/autocad-plant-3d-forum/pipe-parts-not-to-appear-in-iso-sketch/td-p/12436458)),
and the Data Manager exposes a *«Tap Weld»* node. So Plant 3D's joint for a
stub-in is a real object whose header side is a reference rather than a port —
exactly the shape P-22 proposes.

### 4.7 What NOT to copy

- **Weld numbers are not in the model.** *«The welds are automatically numbered
  when creating the isometrics. These numbers are not stored in the 3D model and
  thus these numbers are not written in the PCF file.»* Numbering is configured
  per iso style (Project Setup → Isometric DWG Settings → Annotations → Table
  IDs → Weld ID (Shop)/(Field))
  ([weld numbering
  criteria](https://www.autodesk.com/support/technical/article/caas/sfdcarticles/sfdcarticles/How-to-influence-the-welds-numbering-criteria-on-isometric-drawings-in-AutoCAD-Plant-3D.html)).
  Practitioners export the weld list to CSV and re-match a `DAT_ID` ↔ weld
  number pair after every iso run, because *«there is no link afterwards»*
  ([Weld numbers and PCF
  file](https://forums.autodesk.com/t5/autocad-plant-3d-forum/weld-numbers-and-pcf-file/td-p/7984132)).
  Our `joints.joint_seq` + `numbering_policy` are first-class and frozen; we are
  ahead of Plant 3D here and should stay there.
- **Derived positions produce wrong numbers.** Cut length for a same-size
  stub-in is a known defect: *«Branch length to center is DN600, pipe is also
  DN600, which means that the cutlength must also be 600mm. According to the
  properties the cutlength is 295mm.»* Logged with development; *«the only
  workaround is to manually update the table.»*
  ([Cut length stub in not correct P3D
  2015](https://forums.autodesk.com/t5/autocad-plant-3d-forum/cut-length-stub-in-not-correct-p3d-2015/td-p/5434864);
  [CUT PIECE
  LIST](https://forums.autodesk.com/t5/autocad-plant-3d-forum/cut-piece-list/td-p/7750830))
  A stored value can be wrong too — but it is wrong *visibly*, which is the
  property that matters.
- **Positional links are fragile.** Olets sharing a coordinate lose their
  connection; the field workaround is to nudge each one: *«I move the Olet's
  always around 0.01 and 0.02 along the pipe»*, and recovery is a manual
  command, *«Connect component to adjacent… worked for overcoming this bug in
  the software.»*
  ([Issue with
  Olets](https://forums.autodesk.com/t5/autocad-plant-3d-forum/issue-with-olets/td-p/7169730);
  [Olet and Isometric
  drawings](https://forums.autodesk.com/t5/autocad-plant-3d-forum/olet-and-isometric-drawings/td-p/4828163))
  Direct evidence for an explicit header reference over a positional one — and,
  equally, for storing an along-axis position, since two connections at the same
  place are exactly what confuses it.

### 4.8 Consequence for P-22 — representation delivered

Plant 3D confirms the core of P-22: **no synthesised port on the header**, and a
joint entity whose header side is a reference rather than a port. P-22 now uses
that shape; the point is bounded and frozen at creation:

1. The along-axis station is stored in millimetres from the header's upstream
   end. Unlike Plant 3D, our register has no geometry from which to derive it;
   it also distinguishes multiple branches on one spool (§4.7).
2. The circumferential offset is stored in millimetres from the centreline and
   bounds-checked against the header. Plant 3D considers this axis worth
   stating (§4.3); Cadmatic encodes the concentric/eccentric distinction (§4.5).
3. The issued PMS verdict supplies `STUB_IN` or `STUB_ON`; the joint stores the
   observed kind separately and carries pad thickness from the same issued
   snapshot. The PCF source may contain tap coordinates and branch indicators,
   but the current parser only recognizes them read-only; it does not apply an
   end treatment or create a joint.

The P2P joint is therefore one branch-side port plus a frozen
(header component, station, offset) reference. The header retains exactly two
end ports, so `openPorts()` remains correct. `BranchWallJointTest` verifies the
wall-reference invariants and branch-size dia-inch behavior. The separate OLET
to-header weld is not the same operation and remains unmodelled.

## 5. What the PCF standard actually says — corrected (added 2026-11-10)

§2 describes the tap idiom correctly in substance but never names the keyword,
and the importer has since been built against a keyword that does not exist.
This section corrects the record.

### 5.1 The keyword is TAP-CONNECTION, and it already carries an explicit host reference

From the Piping Component File Reference Guide:

- `TAP-CONNECTION` — *«Point of secondary connection on most components where a
  new pipeline can be attached or a branch of the current pipeline can be
  positioned.»* It is listed among the Connection/Continuation attributes of
  most components. ([PCF Reference
  Guide](https://pdfcoffee.com/pc-f-reference-guide-9-pdf-free.html))
- *«Connections to pipe are handled exactly as connections to components.»* —
  tapping a PIPE is the same operation as tapping a valve.
- Resolution rule: *«The start coordinate of the tap branch does not have to lie
  on the centerline of the pipe. However, it is important that the projection of
  the tap coordinate on to the pipe centerline lies within the end points of the
  pipe.»*
- **The host is named explicitly.** *«You can achieve this connection in one of
  two ways, either by associating the tap with a component or by associating the
  tap through a UNIQUE-COMPONENT-IDENTIFIER (UCI) attribute.»* And: *«When
  including a TAP-CONNECTION in the file as a separate entry, a
  UNIQUE-COMPONENT-IDENTIFIER must be included. The identifier required is for
  the host component to which the Tapped Branch is attached.»*
- *«A pipeline component may have multiple TAP-CONNECTIONs (9 maximum)»*;
  *«Other than the mandatory CO-ORDS and the optional UCI attributes, the only
  other attribute that can be specified on a tap connection is CATEGORY»*;
  *«Co-ordinate positions given must be the point of connection of the Tapped
  Branch to the host component.»*

**This is P-22 already in the standard.** The format's canonical fitting-less
branch is a connection whose host side is *a reference to a component* (by UCI
when it is a separate entry) plus a coordinate — not a port on the header, and
not a synthesised fitting. Option 2 is not an invention; it is what the file
format has always said.

### 5.2 TAP-POINT is not a PCF keyword; standard tap syntax is now parsed

`TAP-POINT` does not exist in the PCF Reference Guide. The near-miss is
Smap3D's `TAPPING_POINT` / `TAPING_POINT`, which are *coordinate-system names
inside a SolidWorks model*, not PCF output
([Smap3D Components
Specification](https://help.smap3d.com/PlantDesign2021/SOLIDWORKS/EN/HTML/PCFExport/PCFExport_Components_Specification.htm)).

Before the corrective implementation, our `PcfParser::KEYPOINTS` listed
`TAP-POINT` and `PcfAdmission` warned on it, while a real `TAP-CONNECTION` was
silent. That was an inverted guard. As of 2026-10-26, the read-only parser
normalizes both standard forms:

1. `TAP-CONNECTION CO-ORDS ...` associated with the containing component; the
   component block is the host.
2. A separate `TAP-CONNECTION` entry carrying `CO-ORDS ...` and
   `UNIQUE-COMPONENT-IDENTIFIER <host-UCI>`; the UCI resolves to exactly one
   component in the same parsed file.

Both return a `PcfTapConnection`. A tap remains **not a host port**; separate
entries are not material. Admission emits `tap_connection` because no model
write exists, and additionally warns for absent coordinates or a missing,
dangling, or ambiguous host UCI. `TAP-POINT` now emits
`unsupported_tap_point` and is not normalized as a tap.

| input | current read-only result |
|---|---|
| associated `TAP-CONNECTION CO-ORDS ...` | `tap_connection`, host is containing component |
| separate `TAP-CONNECTION` + `CO-ORDS` + host UCI | `tap_connection`; host UCI resolved if unique |
| legacy/synthetic `TAP-POINT` | `unsupported_tap_point`, not a tap |
| `TEE-STUB` or `TEE-SET-ON` with SKEY | branch warning; SKEY determines STUB_ON vs STUB_IN |
| `TEE-STUB`/`TEE-SET-ON` without a usable SKEY | `branch_type_unresolved` |

This is still read-only admission, not PCF-to-joint import. A geometrically
valid tap does not mean that its projection, line ownership, class verdict,
branch size or pad has been validated.

### 5.3 Tee aliases need SKEY; cross aliases need per-leg handling

The reference permits both `TEE-SET-ON` and `TEE-STUB` for set-on, stub-in,
tangential and offset tee configurations. The type token alone is broad; the
SKEY carries the distinction used by the current read-only classifier:
`TESO`/`TERF` → `STUB_ON`, `TSSO`/`TSRF` → `STUB_IN`. A missing or unfamiliar
SKEY is unresolved, not guessed. `TEE-SET-IN` remains an explicit vendor/type
signal for `STUB_IN`.

`CROSS-SET-ON` and `CROSS-STUB` are known branch-specific PCF shapes, but have
multiple branch legs; the one-branch classifier does not invent a single answer
for them. They are reported as unresolved. The same rule applies to generic
`OLET`; explicitly named `WELDOLET` and `SOCKOLET` keep their distinct fitting
identities.

### 5.4 The MTO rule that settles the OLET question

*«For set-on, stub-in, tangential and offset tees, the **ITEM-CODE attribute…
should be those of the branch pipe**. For pulled tees, the ITEM-CODE attribute…
should be those of the main pipe.»*

No purchased fitting: the material consumed is pipe. This is the format-level
corroboration of ADR-006 §§13–14 — a stub-in must not be faked as an OLET, because an
OLET carries an item code of its own and would place a purchased fitting in the
MTO that was never bought.

### 5.5 Both endpoints are stored; the measurement is policy

A set-on/stub-in tee carries two coordinates: `CENTRE-POINT`, which *«must lie
on the center-line of the main pipe… the point at which the center-line of the
branch pipe intersects»*, and `BRANCH1-POINT`. *«The distance between the
CENTRE-POINT and BRANCH1-POINT coordinates is not the length of the fitting.»*
`BRANCH1-POINT` is set to the actual branch end for an accurate cut length, or —
*«Where BRANCH1-POINT cannot be accurately determined, and the branch has
adjoining pipe, set the location of BRANCH1-POINT to that of CENTRE-POINT»* — or
*«as a point on the surface of the main pipe regardless of the branch size»* when
there is no adjoining pipe.

Which one the cut length is measured to is **not in the data**; it is a
configuration choice in ISOGEN (`Length to Centre Line` / `Length to Branch
Point`, and Option Switch 2: 0 = actual branch connection point, 1 = centre of
main pipe in all situations, 2 = coordinate position)
([ISOGEN Configuration User's
Guide](https://www.scribd.com/document/272252634/ISOGENConfigurationUsersGuide-pdf)).

**The lesson for us: store both facts, defer the policy.** That is the same shape
as `joints.numbering_policy` — which is why §4.8 recommends storing the station
and the offset rather than a pre-measured cut length.

### 5.6 Consequence

The read-only input side now recognizes `TAP-CONNECTION` in both standard forms,
resolves a separate entry's host UCI, and reports unsupported or unresolvable
branch data rather than silently flattening it. `TAP-POINT` is retired and
reported as unsupported. `TEE-SET-ON` / `TEE-STUB` use SKEY to distinguish
STUB_ON from STUB_IN; `CROSS-*` and generic OLET remain visible but unresolved.

The actual importer is still unbuilt. Before it can create a P-22 wall joint it
must use the resolved host identity (not nearest-coordinate guessing), apply the
PMS branch verdict and pad, validate the projection against the header extents,
reconcile the branch line and preserve existing manual/fitted joints. It must
also decide how to explode a multi-leg `CROSS-*` into per-branch joints. See
ADR-023 for this increment's acceptance and deliberately-not-built list.

## 6. The PMS branch table is the authority — the joint path now asks its issued snapshot

Surveyed while designing P-22 and updated after the branch-class and wall-joint
slices. The remaining gaps are authoring UI and source-to-model application,
not a missing joint representation or a missing class verdict.

### 6.1 What is built, and built well

`piping_class_branch_rules` is a genuine two-dimensional matrix:
`header_range int4range` × `branch_range int4range` → `component_type`, with
`priority` as the declared tiebreak (ADR-008 §4.3, overlap is a feature).

- `component_type ∈ {TEE, WELDOLET, SOCKOLET, P2P}` — DB CHECK.
- `p2p_kind ∈ {STUB_IN, STUB_ON}` — mandatory on P2P cells, forbidden on
  fittings, and the CHECK is NULL-explicit because the first draft passed a
  kind-less P2P row (ADR-008 §9.1).
- `reinforcement ∈ {NONE, PAD}` with `pad_thickness numeric(8,3)` in
  millimetres — mandatory and > 0 whenever `reinforcement = 'PAD'`, and a
  thickness without a pad is refused.
- `PipingClassService::branchFor($class, $headerDn, $branchDn)` — narrowest
  range wins, then `priority DESC`, then `created_at ASC`; throws
  `BranchLargerThanHeaderException` or `NoRuleForSizeException`.
- `BranchReinforcementCalculator` — ASME B31.3 §304.3.3 area replacement with
  `evaluate()` and `requiredPadThickness()`, and `BranchConnectionPadGate`,
  which runs at class publication and again on schedule-only revisions.
- Covered by `PipingClassTest`, `BranchReinforcementCalculatorTest` and
  `PmsPinnedBranchResolutionTest`.

In ADR-008 §9.1's own words, a P2P cell is *«semantically load-bearing for
build phase 3: a P2P cell means **expect NO component at this branch**»*.

### 6.2 What is connected now (corrected 2026-10-26)

The earlier survey below this section described the pre-branch-plan baseline.
The production joint path now asks the line's **issued PMS snapshot** rather
than mutable live class rows:

- `JointService::branchVerdictFor()` identifies a fitting's `BRANCH` port,
  reads the header DN from its sibling `RUN` port, and passes the header ×
  branch pair to the shared verdict resolver.
- `wallVerdictFor()` takes the header component DN and branch-port DN through
  that same resolver. `joinToWall()` records the P2P joint with a single branch
  port plus frozen header/station/offset; the header never gains a fake port.
- The joint carries the observed branch kind next to the snapshot's declared
  `branch_class_type`, `branch_class_p2p_kind`, `branch_pad_thickness_mm` and
  rule state. A disagreement is observable rather than overwritten.
- There is still no screen that creates a wall joint and no PCF-to-model writer.
  `PcfAdmission` now reads standard taps and reports them, but does not apply
  them. The branch-rule authoring UI/seeding gap also remains.

The public `PipingClassService::branchFor()` lookup is not the production
path for an issued joint: the joint resolves the immutable issued
specification. Do not describe “no direct `branchFor()` call site” as “the
class is not consulted”; those are different facts.

### 6.3 What this does to P-22

The implemented chain is:

1. A branch of DN_branch lands on a header of DN_header.
2. The line's issued PMS snapshot supplies the branch-table verdict and any
   reinforcement pad thickness.
3. A fitting verdict uses the two-port path and checks the observed component
   against the declared class result.
4. A P2P verdict uses `joinToWall()`: branch-side port + frozen header
   component/station/offset. It records the class verdict and pad without
   inventing an OLET or a wall port.

The classification and class verdict are implemented; source-to-model
application is not. A PCF tap can now be parsed and its UCI host resolved, but
no importer creates the wall joint. A fitting-less PCF tee also requires
SKEY-aware classification; multi-leg CROSS-* still needs a per-branch apply
design.

## 7. The commercial reason branch connections must be distinguishable (owner, 2026-11-10)

Owner requirement, verbatim: *«در پروژه‌ها معمولاً قیمت اجرا و جوشکاری اتصال
انشعاب از قیمت اجرا و جوشکاری جوش معمولی بیشتر است. سیستم باید بتواند اتصالات
انشعابی را از معمولی تفکیک کند»* — branch connections cost more to execute and
weld than ordinary welds, and the system must be able to separate them.

### 7.1 Why dia-inch cannot carry it

`PortCompatibility::diaInch()` is `min(sizeA, sizeB)` — the ordinary industrial
convention. For a branch weld that is the branch size; for a straight butt weld
of the same pipe it is the same number. A 2" stub-in on a 24" header and a 2"
straight butt weld therefore produce **identical dia-inch**.

But dia-inch measures **weld metal**, and most of the extra cost of a branch
connection is not weld metal:

1. cutting the hole in the header — a separate operation, often machine-cut;
2. contouring/coping the branch end to the header OD — the saddle cut;
3. fit-up of a contoured joint on a curved surface;
4. the weld itself is a contour weld, not a flat girth weld — more passes,
   frequently out of position;
5. the reinforcement pad, where required — a second weld plus material;
6. NDT access: the geometry makes examination harder.

Five of the six are invisible to dia-inch. This is the strongest form of the
argument: it is not that dia-inch is too coarse, it is that **dia-inch measures
the wrong thing** for this comparison.

### 7.2 What the model can now distinguish

The previous baseline analysis found that `PortCompatibility::jointType()` by
itself could only return ordinary compatibility kinds and could not say
“branch”. That remains true of end-type compatibility; branch classification
now has its own explicit model path.

| branch shape | modelled now? | recorded distinction |
|---|---|---|
| branch through a TEE/CROSS | yes | branch-port role and `branch_kind`; ordinary physical dia-inch stays unchanged |
| complete OLET assembly | yes | OLET RUN/FA wall weld plus ordinary BRANCH-to-pipe weld; `branch_kind=OLET` and the issued verdict sit on the root weld exactly once |
| stub-in / stub-on (P2P) | yes, via `joinToWall()` | one branch port + frozen header reference; `BRANCH_STUB_IN` / `BRANCH_STUB_ON` |

`lineMetrics()` counts both physical OLET welds, but classifies the branch
connection once; the wall-weld dia-inch uses the branch size. The UI and
PCF-to-model apply path are still absent, so this is model/service capability,
not an operational import workflow. This does not change the read-only boundary
for PCF `TAP-CONNECTION` data.

### 7.3 The classification is implemented

`ports.role` includes `BRANCH` (`TEE` = [RUN, RUN, BRANCH], `CROSS` = [RUN,
RUN, BRANCH, BRANCH], `OLET` = [RUN, BRANCH]). TEE/CROSS two-port joints read
that role to classify branch welds. OLET is explicit: its RUN wall joint gets
`branch_kind=OLET` and the branch-port-to-pipe weld remains ordinary. P2P wall
joints are also explicit, with `STUB_IN` or `STUB_ON`, `isOnWall()` and the
frozen header reference identifying the other side without inventing a port.

The class verdict is carried separately from observation; `branchDeviation()`
can report agreement, mismatch or an ungoverned case. This is now modeled and
tested, not merely an inferred future use of `ports.role`.

### 7.4 The requirement is partly implemented

ADR-008 §9.2 requires branch welds to be counted separately, dia-inch to remain
physical, pad thickness to stay traceable, and class contradictions to be
visible. The joint/service path now records the kind, snapshot verdict and pad
thickness and exposes a separate branch-weld count. Contractual rates, a cost
schedule and UI reporting are deliberately not part of this slice; the
contract's rate table still assigns monetary value.

### 7.5 Design conclusion — separate classification from valuation

- **Classification** (is it a branch, and of what kind): port role where a
  fitting is present; the line's issued PMS snapshot supplies `P2P` +
  `STUB_IN`/`STUB_ON` where it is not. Both the observed kind and class verdict
  are retained; a disagreement is the deviation §9.2 anticipates.
- **Valuation** (what it is worth): **do not fold a multiplier into dia-inch.**
  The factor is not a property of the weld; it is a property of the contract and
  the fabricator. A baked-in multiplier yields a number that looks physical but
  is commercial, and is therefore unarguable — the same mistake as storing a
  pre-measured cut length (§5.5). Keep `dia_inch` physical and count branch
  welds separately by kind, carrying branch size and, where the rule says PAD,
  pad thickness. `lineMetrics()` supplies the count; a future contract rate
  schedule may price it. No such rate schedule is implemented in this slice.
- The pad is the one item that is ours alone: neither Plant 3D (§1.3) nor PCF
  (§5.4) knows reinforcement pads exist.
