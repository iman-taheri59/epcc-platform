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

## 3. Consequences for the platform (feeding P-22)

1. **Tap semantics are the PCF-native representation of P2P stub-in.** The
   branch pipe ends at a wall coordinate; the header is identified
   implicitly by projection. P-22's recommendation is updated accordingly:
   the joint carries the branch-side port plus a **frozen reference to the
   header component and connection point** (ADR-006 §5.3 "derived
   attributes are frozen") — not a synthesised port on the header.
2. **Stub-on without a fitting** (CADWorx OTAP, SKEYs TTSO/TSSO) is the
   same tap idiom with the connection point on the surface — our
   `STUB_ON` kind maps to it; a set-on *fitting* remains `TEE-SET-ON` and
   is our `TEE` cell.
3. **The stub-in marker travels on the end code / weld property (TAP,
   "tapweld"), not on a distinct weld SKEY** — our `BRANCH_STUB_IN` /
   `BRANCH_STUB_ON` joint types (P-21) must be derivable on PCF import from
   tap end codes and coordinates, since the WELD entity alone is ambiguous
   with a butt weld.
4. **Pads are invisible to Plant 3D/PCF** — reinforces P-23: the pad and its
   thickness exist only in our class rules (ADR-008 §9.1) and must flow
   into MTO/weld records from there.

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

This is the cheapest correct piece available to us, and it is independent of
P-22: mark the branch end. Our `end-types.ref.json` has no `TAP` — Plant 3D's
end code for a stub-in branch (§1.2 above). Our PCF layer already parses
`TAP-POINT` (`PcfParser`) and warns `tap_connection` (`PcfAdmission`), so the
input side is half-built.

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

### 4.8 Consequence for P-22

Plant 3D confirms the core of P-22: **no synthesised port on the header**, and a
joint entity whose header side is a reference rather than a port. It corrects
the axis, though:

1. Store the along-axis station (`station_mm` from the header's upstream end).
   Plant 3D does not need to; we do, because we have no geometry to derive it
   from. It is also the only thing that distinguishes two branches on one spool
   — the case Plant 3D fails (§4.7).
2. Store the circumferential offset (signed mm from centreline, with an optional
   TOP/BOTTOM tangent designation). This is the axis Plant 3D considers worth
   stating (§4.3) and Cadmatic encodes as concentric vs eccentric (§4.5).
3. Mark the branch end as a stub-in end treatment. This is Cadmatic's
   decomposition, costs nothing in `joints`, and the PCF input for it already
   exists (§4.5).

Then the joint for a P2P branch = branch-side port + frozen (header component,
station, offset). The header keeps exactly two ends, so `openPorts()` stays
correct — which is the whole reason for preferring this over a synthesised wall
port.

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

### 5.2 TAP-POINT is not a PCF keyword, and our guard is built on it

`PcfParser::KEYPOINTS` lists `TAP-POINT`, and `PcfAdmission` warns
`tap_connection` when a keypoint's kind is `TAP-POINT`. No such keyword exists in
the PCF Reference Guide. The near-miss is Smap3D's `TAPPING_POINT` /
`TAPING_POINT`, which are *coordinate-system names inside a SolidWorks model*,
not PCF output
([Smap3D Components
Specification](https://help.smap3d.com/PlantDesign2021/SOLIDWORKS/EN/HTML/PCFExport/PCFExport_Components_Specification.htm)).

Measured by parsing three files through our own `PcfAdmission` (2026-11-10):

| input | warnings |
|---|---|
| real `TAP-CONNECTION` + `CO-ORDS` + `UNIQUE-COMPONENT-IDENTIFIER` | **none** |
| our synthetic `TAP-POINT` | `tap_connection` |
| `TEE-STUB` (the reference's other name for a stub-in tee) | **none** |

The branch-on-header guard therefore fires on a shape we invented and is silent
on both shapes the standard defines. Its tests construct the invented shape
themselves (`PcfAdmissionTest`, `JointBoundaryTest`), which is why they pass.

### 5.3 Two names for the same fabricated tee, and one we do not know

*«you can specify the stub-in tee in the PCF as **TEE-SET-ON or TEE-STUB**»*, and
`TEE-SET-ON` covers set-on, stub-in, tangential and offset tees (SKEYs TESO and
the reinforced TERF; CADWorx adds TTSO/TSSO/TSRF).
`PcfBranchClassification` recognises `TEE-SET-ON` and `TEE-SET-IN` but not
`TEE-STUB`, nor `CROSS-SET-ON` / `CROSS-STUB`.

### 5.4 The MTO rule that settles the OLET question

*«For set-on, stub-in, tangential and offset tees, the **ITEM-CODE attribute…
should be those of the branch pipe**. For pulled tees, the ITEM-CODE attribute…
should be those of the main pipe.»*

No purchased fitting: the material consumed is pipe. This is the format-level
corroboration of ADR-006 §13 — a stub-in must not be faked as an OLET, because an
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

The importer's branch detection should be rebuilt around `TAP-CONNECTION` (with
UCI) and the `TEE-SET-ON` / `TEE-STUB` / `CROSS-*` family, and `TAP-POINT`
retired rather than merely supplemented — a keyword no exporter emits is a guard
that has never run.

## 6. The PMS branch table is already the authority — and nothing asks it (2026-11-10)

Surveyed while designing P-22. This is the state of the branch side of the
house, and it changes where the work is.

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

### 6.2 What is not connected

Measured 2026-11-10 by grep across `app/`:

- `branchFor()` has **nine call sites, all in `PipingClassTest`**. No
  production path calls it.
- `ComponentService`, `JointService` and `ComponentController` contain no
  reference to `PipingClass` or `piping_class` at all — the branch placement
  path does not know the class exists.
- `PipingLineController` uses `PipingClass` only for line *eligibility*
  (which class may be assigned to a line).
- There is no screen: no route for a piping class in `routes/web.php`, no
  Filament resource (the panel has Documents, PunchItems, Subsystems,
  Transmittals, Users), and no seeder populates branch rules.
- The placement form carries a free-text `branch_size` (max 16 chars); a
  comment there notes *«the branch is port 3 on a TEE but port 2 on an
  OLET»* — the branch size is typed by hand, not resolved from the table.

So the branch table is a well-built register that nothing reads at the moment
of truth. That is not a defect in the table: ADR-008 §9.1 built it as master
data ahead of the build phase meant to consume it. But it means the missing
piece is neither the table nor the joint — it is the **consultation step**,
and it is absent from both sides.

### 6.3 What this does to P-22

The chain that should exist:

1. A branch of DN_branch is placed onto a header of DN_header.
2. The class resolves: `branchFor()` → `TEE`, `WELDOLET`, `SOCKOLET`, or
   `P2P` + `STUB_IN`/`STUB_ON`.
3. A fitting verdict takes the ordinary two-port path and nothing changes
   (this is case 0 of the joint truth table — it already works).
4. A **P2P verdict is precisely the case the joint model cannot represent.**
   The class additionally states `reinforcement` and `pad_thickness`, which
   ADR-008 §9.2 requires to *«stay traceable to the weld»*.

Two consequences for the design:

- **The joint does not have to infer that it is a stub-in; the class tells
  it.** P-21's `BRANCH_STUB_IN` / `BRANCH_STUB_ON` joint types are not a
  guess made from geometry — they are the class verdict, carried down. The
  sizes are the input, not the line membership.
- **The pad is an advantage available to us that no 3D system has.** Both
  Plant 3D (§1.3) and PCF (§5.4) are blind to reinforcement pads; ADR-008 §9.1
  already makes the thickness a mandatory datum on the rule and §9.2 requires
  it to be traceable to the weld. A branch-weld joint carrying
  `pad_thickness` from its class rule is therefore a genuine differentiator,
  and the specification for it is half-written already.

The recommended order changes accordingly: before storing a station or an
offset, add the step that asks the class. Without it a branch joint would be
typed by guesswork, and a size combination the class forbids would be
placeable — which today it is.

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

### 7.2 The problem is not confined to the unmodellable stub-in

Measured 2026-11-10. `PortCompatibility::jointType()` derives the type purely
from end types and can only return `BUTT_WELD`, `SOCKET_WELD`, `FABRICATED`,
`FLANGED`, `SCREWED`, `OTHER`, `UNKNOWN`. `Joint` carries no notion of a branch,
and `JointService` mentions BRANCH only in a comment describing what it cannot
do.

So even the branch connections that **do** work today are invisible:

| branch shape | modelled today? | what it looks like in the data |
|---|---|---|
| branch through a TEE | yes (2 joints) | `BUTT_WELD`, dia-inch = branch size |
| branch through an OLET | yes (1 joint, branch side) | `BUTT_WELD`, dia-inch = branch size |
| stub-in / stub-on (P2P) | no | — |

The system cannot answer *«how many branch welds does this line have»* **today**,
for the two shapes it already represents. P-22 is not a prerequisite for fixing
that.

### 7.3 The classification is already in the model, unused

`ports.role` is stored, shown in `Port::__toString()`, and used for size lookup
in `ComponentService`. Its values in the reference pack include `BRANCH`:
`TEE` = [RUN, RUN, BRANCH], `CROSS` = [RUN, RUN, BRANCH, BRANCH], `OLET` =
[RUN, BRANCH]. The migration's own comment says *«the branch is always seq 3 and
rules refer to it by role»*.

Nothing in the joint layer reads it. **A joint whose one side sits on a
`BRANCH`-role port is a branch connection** — detectable with no change to
`joints` at all.

### 7.4 This is already a recorded requirement

ADR-008 §9.2 (2026-10-12): *«Branch welds must be counted and reported
separately from butt welds, dia-inch metrics must not silently absorb them, the
mandatory pad thickness of a PAD rule must stay traceable to the weld, and a
joint contradicting its class is a deviation.»* Nothing is built.

### 7.5 Design conclusion — separate classification from valuation

- **Classification** (is it a branch, and of what kind): port role where a
  fitting is present; the class verdict `branchFor()` → `P2P` +
  `STUB_IN`/`STUB_ON` where it is not. The two sources should agree; a
  disagreement is the deviation §9.2 anticipates.
- **Valuation** (what it is worth): **do not fold a multiplier into dia-inch.**
  The factor is not a property of the weld; it is a property of the contract and
  the fabricator. A baked-in multiplier yields a number that looks physical but
  is commercial, and is therefore unarguable — the same mistake as storing a
  pre-measured cut length (§5.5). Keep `dia_inch` physical, and add a separate
  countable: branch welds by kind, each carrying branch size and, where the rule
  says PAD, pad thickness. Let the contract's rate table price them.
- The pad is the one item that is ours alone: neither Plant 3D (§1.3) nor PCF
  (§5.4) knows reinforcement pads exist.
