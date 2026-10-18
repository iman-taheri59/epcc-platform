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
