# Human OrderGap boundary for controlled MR-linked orders

2026-10-19 · **IMPLEMENTED / TARGETED VERIFIED** — enforcement of an accepted boundary, not new MTO authority.

## References, conflict and reproduction

ADR-038 explicitly restricts human editing of controlled MR-linked DRAFTs to complete pricing by the original drafter with a live independent grant; quantities/source identity/descriptions are not editable. ADR-035's earlier statement that amendment lineage may retain a source reference describes a trusted provenance primitive, not a competing human quantity-edit grant. ADR-015 legacy lineage, ADR-033 independent draft nomination, ADR-037 issued-content freeze, ADR-039 issue allocation and ADR-040 diagnostic visibility remain unchanged.

The existing OrderGap HTTP amendment path called the trusted amendment primitive without distinguishing controlled MR-linked orders. A real HTTP regression demonstrated the original drafter changing a copied source quantity through a successor: two source-copy lines became three stored lines. `reproduction.log` records the expected failure **3 != 2**, before the repair. This invalidated source/pricing eligibility but did not itself create an issue allocation or bypass issue approval. No production data incident is claimed.

## Implemented boundary

- `OrderGapController` now calls explicit `humanAttribute` / `humanAmend` service entry points. The private shared implementation receives a fixed server-selected mode, not a posted flag.
- After the existing project advisory → project → PO → item locks, fresh parent markers are checked. If **both `author_policy_id` and `requisition_id` are non-null**, human quantity amendment and material re-attribution are refused with a bilingual `purchase_order_id` validation error (JSON422; ordinary form validation behavior otherwise). The refusal applies to this protected source class in any state; it does not imply that issued orders can be priced.
- Client fields, original-author status, cached parent relations or removing markers from an in-memory model cannot disable the check. No buyer impersonation is added.
- The board replaces only these protected rows' attribution/amendment forms with an EN/FA read-only explanation. Direct POST remains guarded; hiding the form is not the enforcement mechanism. Other rows and scope-allocation controls are not redesigned.
- Existing `attribute` / `amend` service methods remain explicitly **trusted compatibility primitives**, not user authorization APIs. Their lineage behavior is retained for existing internals, legacy tests and controlled-version invalidation tests. Human controllers must use the human boundary, not these trusted methods.

The original drafter's separate complete-pricing workflow is unchanged. No new role, nomination inheritance, generic MTO/OrderGap policy, partial-purchase surface, source mutation, reservation, reconciliation writer, cancellation release or post-issue amendment is enabled. No database migration is needed for this HTTP/service/UI repair; the preceding SQL integrity migration180000 remains required and unchanged.

## Verification

Evidence: `docs/evidence/2026-10-19-order-gap-human-boundary/summary.json`.

**77 affected tests / 764 assertions PASS in 141.914 seconds**: controlled pricing, OrderGap, MR issue allocation and controlled execution. Six new cases /82 focused assertions cover:

1. Actual original-drafter POST cannot create a successor changing copied source quantity.
2. Original drafter, other buyer user, owner, PMC and named vendor cannot use amendment/re-attribution on the protected source class; seven source/order/audit/allocation tables remain identical.
3. Both EN/FA boards omit the two protected forms and retain the existing scope-allocation form.
4. Human service entry points reload the parent and reject forged cached source-marker removal.
5. Refusal leaves the accepted original-drafter pricing path working, keeps MR APPROVED / PO DRAFT, and writes only the expected pricing revision.
6. Legacy HTTP attribution and amendment still work and preserve old quantity, successor linkage and material identity.

The combined run also retains previous targeted issue-versus-amend contention and non-buyer SQL-freeze regressions. This is bounded evidence, not exhaustive concurrency certification. An initial implementation-only closure capture omission was fixed before the passing runs; `focused.log` contains the final six-case pass. Four PHP lint checks, targeted Pint and whitespace diff checks pass. PostgreSQL17/PHP8.4 runtime was restored after cache reset; test role is NOSUPERUSER/NOBYPASSRLS, UTF8, private Unix socket only; database stopped after verification.

No routine full-suite rerun, current CI, production application, deployment, commit/push or browser acceptance claim. Previous evidence folders are unchanged.

## Still deferred / recommendations

The older legacy/manual OrderGap write surfaces are not a complete user-authorization design: parent visibility and historical RLS are not, by themselves, a modern operational grant. Do not infer a new right from their preservation here. Review those surfaces separately against canonical MTO authority deferrals before introducing roles, actor gates or disabling accepted legacy behavior. Likewise, manual human draft editing and controlled post-issue corrections need their own explicit workflow boundaries; this repair does not settle them. Keep versioned corrections/reconciliation proposals distinct from delivered functionality and never reinterpret lineage as source-capacity release.
