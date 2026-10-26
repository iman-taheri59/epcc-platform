# ADR-041 — independently nominated inspection entry, original PO visibility

2026-10-19 · **OWNER AUTHORITY DECISIONS ACCEPTED / BOUNDED ENTRY IMPLEMENTED, TARGETED VERIFIED**
Companion: [فارسی](ADR-041-INDEPENDENT-INSPECTION-ENTRY-FA.md).

## Explicit owner selections

- `inspection_recording_authority = independent_project_nomination`: owner-company project.configure administrator explicitly nominates active project people or company-scoped project roles; no default recorders or inherited MR/PO/MTO grants. Recording company is the recorder's actual company, not the PO buyer.
- `inspection_purchase_order_read_scope = original_caller_visible_orders_only`: nomination does not grant PO reading. The existing original caller/project RLS must independently expose the selected PO. The offered target-buyer delegated workspace option was **not selected**; do not implement it implicitly or re-ask these choices routinely.

## Existing rules preserved, not silently replaced

Inspection is advisory and does not block physical receipt or certify usable stock (ProcurementState; ADR-032 and prior material decisions). `WarehouseService::inspect` already restricts inspecting companies to **TPI, PMC, MC (management contractor) and OWN**, requires findings for FAIL/CONDITIONAL, and uses the project IRN numbering scheme. Generic “project participants” in nomination approval is not an explicit repeal of inspector independence. Nomination is an *additional* condition within those eligible companies; EPC/SUB/VEN cannot self-sign by obtaining a new nomination. Broadening that rule would need a separate explicit decision.

Review correction: the initial local implementation had interpreted participation too broadly and used a new INSP prefix. Before final delivery it was corrected to retain these preexisting company/findings/IRN rules. Initial logs are historical; only the final related evidence certifies the corrected bounded slice. No production incident/deployment is claimed.

## Implemented scope

Owner configuration uses existing project.configure, current procurement/standards entitlement and writable project, with append-only version/reason/person-role selectors, current-role matching and explicit suspension. Selector options are limited to active eligible project members; only the configuring owner receives this bounded directory selection, through the existing identity lookup wrapper. Current participation, active user, actor company, module/pack and discipline are rechecked at entry. A PO with unknown discipline requires unrestricted membership; source discipline is never guessed.

The new create-only form lives on an already-visible PO detail. Opening that detail is the PO selection; no global/unbounded order dropdown or buyer context is added. Enter explicit result/stage/date and findings; FAIL/CONDITIONAL needs at least one language. The PO UUID is bound to the route. Source/actor/company/policy/number/time are server-owned, and unexpected input fields are rejected. A different inspecting company is allowed only when the PO is already visible to it. A nominated TPI may still be unable to record against an ordinarily hidden PO; this is the selected visibility boundary, not a reason to impersonate the buyer.

Project/inspection-entry locks serialize human numbering and nomination replacement. New observation numbers retain `%s-IRN-%04d` with the current project MAX sequence; no counter backfill or number rewrite. Current records retain nomination UUID, reported date, recorder UUID/company and server actor-name/time metadata. Controlled rows cannot be updated/deleted or attached retroactively to legacy rows. Existing nullable legacy records remain unchanged and are not re-certified. No web edit/delete/correction/supersession surface is introduced.

This is recorded observation, not a verified physical examination, signature, commercial-version approval, financial action, release/hold decision or proof of usable material. It does not auto-apply results to existing lots, alter their cached verdicts, block receipt or issue a PO. Any PO state can be observed under current eligibility; entry does not change that state. Physical date is user-reported Gregorian date at UTC midnight, distinct from server-recorded time. No idempotency/deduplication, future-date truth verification or automatic retries are claimed.

## Evidence and deferred work

**46 targeted tests /558 assertions PASS,75.037s**, including14 new inspection cases, existing register/detail cases and four focused existing Warehouse advisory/independence/findings cases. See [delivery guide](INSPECTION-ENTRY-EN.md) and `docs/evidence/2026-10-19-inspection-entry/summary.json`.

Forward-only migration190000 adds policy history and nullable attribution fields with original-caller SQL guards; no SELECT-policy expansion, SECURITY DEFINER or legacy backfill. It is tested privately, not applied to production. Guards validate attributed inserts/immutable evidence, not universal per-human SQL RBAC over trusted legacy primitives.

Separate/deferred: delegated inspection PO reading (not selected), human lot-application/hold/release, receipt entry, signature/evidence verification, controlled correction/reinspection supersession semantics, broad two-connection revocation/crash certification, remaining MTO/legacy reconciliation authority, production/load admission. ADR-033–040 purchasing/financial/source boundaries remain unchanged.
