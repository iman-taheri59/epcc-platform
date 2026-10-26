> **2026-10-24 new persistence increment:** [source-bound narrative proposal/review envelopes](SCOPE-PROPOSAL-ENVELOPES-EN.md) now exist with independent non-approving opinions and exact-version binding. This does not close the full structured topology/coverage/evidence package or admission-writer gap. Earlier absence-of-persistence wording is historical; prior interfaces/policies remain unchanged.

# Scope-admission action nominations: bounded web interface

**2026-10-24 — implemented and focused/affected tested; not deployed.**

[فارسی](SCOPE-ADMISSION-AUTHORITY-WEB-FA.md) · [Internal service contract](SCOPE-ADMISSION-AUTHORITY-SERVICE-EN.md) · [Accepted SA-01/02, final section](SCOPE-ADMISSION-AUTHORITY-EN.md)

## Status and safety boundary

Owner-only action-nomination UI, owner-only recorded-key reduction register and own-source nomination diagnostics now exist. This supersedes earlier “internal service only / no human route/UI” limitations for this slice, **not** trusted package/review/evidence/admission-writer readiness. No new migration, seeded grant or role, source mutation, technical acceptance event, effective-demand selection, capacity reservation, MR/PO/stock/GRN change. No change to accepted SA-01/02 or DA-01..04. All previous implementation phases/packages remain historical dependent increments.

The service's existing configure/key/policy/preflight code is byte-identical to the delivered service increment. New methods: dashboard, suspensionRegister, suspendRecorded, diagnostics. Existing DemandReaderService/controller and both relevant migrations are unchanged. The existing reader configuration/readiness templates gain links only. New controller, three templates, EN/FA messages and five routes; all routes retain existing authenticated material-workspace middleware, GETs use private/no-store caching headers. No new permission or inherited administrator reading/action grant is introduced.

## Routes and use

Under `/projects/{projectCode}/materials/`:

| Route suffix | Verb | Access / purpose |
|---|---|---|
| `scope-admission-actors` | GET | Current owner project.configure; visible original-caller source picker and eligible company/people/company-role nomination directory |
| `scope-admission-actors` | POST | Append separately versioned propose/review/record nomination, expected version and nonblank reason |
| `scope-admission-suspensions` | GET | Current owner configurator; source-free opaque recorded-policy register |
| `scope-admission-suspensions` | POST | Append empty suspended successor using only recorded policy UUID, expected version, reason |
| `scope-admission-diagnostics` | GET | Independently current nominated source reader; own exact source/action nomination booleans only |

Append `?lang=en` for English or `?lang=fa` for Persian RTL. Language links preserve only the needed source/company/action references. Changing the configuration company/action resets the exact selected source; select it again in the bounded visible picker. Picker25 and history20 limits are navigation, **not technical inventory/coverage completeness**.

### Configuration

1. Open **Scope admission action nominations** from the independent reader settings.
2. Choose an eligible participating company and one independent action: propose, technical review or record.
3. Choose a source visible to the original configuring caller: exact batch/document/scope/pinned hierarchy.
4. Select explicit eligible user/company-role selectors. Record a reason and the displayed expected current action-policy version. No wildcard/defaults, no automatic source-read or other-action nomination.
5. Save; redirect retains this exact selection and action. View only this action's latest20 source-visible history, including policy UUID, version, actor, reason and recorded selectors. Reader-policy history is not substituted for action-policy history.

A nominee can be configured before independently receiving source-read authority, but cannot use diagnostics/preflight until separately eligible and source-read nominated. Configurator authority itself does not grant diagnostics access; an owner without their own reader nomination receives403. This is intentional, not a broken diagnostic link: the page warns about it.

Active configuration rejects empty/missing checkbox selection, invalid/foreign/ineligible selectors, unsupported action, invisible/unknown source and stale version. On the standard form, selecting suspended requires unchecking all selectors; for hidden-source reduction use the dedicated register instead. Reactivation requires visible original source and eligible selectors again. Validation redisplay accepts old form data only for the same company/document/scope/hierarchy/action, preserves the submitted stale version and explicit empty selection, and handles malformed old input without HTML/type errors. History/free text is escaped. CSRF fields are included in POST forms; production-CSRF/browser certification is not claimed by these tests.

### Recorded-key suspension

Register shows only latest owner-recorded heads per exact key **including action**: opaque policy UUID, company UUID, action, version, timestamp and suspended state. It does not query accepted source/document/observation tables or display source IDs/names, quantities, selectors or old free-text reasons. Find the opaque UUID from normal visible-source history; the register does not rediscover a hidden source for you.

Strict POST accepts `suspension[policy_id,version,reason]` only. Extra selector/active/action/source fields fail validation. The service fresh-checks current owner configure authority, locks this project, rejects archive, unknown/non-owner/wrong-project or old/suspended heads, and appends empty successor to the **recorded** exact key. No source/directory lookup. Source hiding and target company's participation exit do not prevent reduction. Repeat/stale posts fail; reappearance of source never revives the old grant. A reader-policy UUID cannot stand in for an admission action policy. This endpoint cannot activate or widen a grant. No original migration/guard changes were needed.

### Own diagnostics: nomination presence, not action approval

Enter through the selected configuration source link (if independently reader-nominated yourself), or the reader-readiness page. Supply only the exact source reference. Recognized client `proposer_id`, `reviewer_id`, `action` claims are prohibited; no HTTP route calls the internal package-actor preflight. Unknown fields cannot affect the service's validated source/diagnostic result.

The page checks actor's own current reader eligibility and exact reference under original tenant RLS, current action-policy heads and fresh company-scoped roles, then rechecks independent reading. Each action boolean means **“current nomination present”**, not “permitted to approve/record this package.” An independently visible source with no action grant shows all false; hidden/missing source is UNAVAILABLE, with all action booleans false and no action policy history/metadata. No reader nomination means403 even with a live action grant. Suspended heads/role removal/reader revocation do not fall back to older policies. Archived projects remain readable for diagnostics, with writable=false; no mutation form or post succeeds.

Always `ready_to_admit=false`, `package_verified=false`, `operationally_authorized=false`, gross=null (unknown, not zero). No source row payload, evidence contents, CDE/library/stock or quantity is read. No technical person-separation or package verification is claimed by the web diagnostics. Authentication of persisted proposer/reviewer/recorder, exact package version/review binding, historical source/topology completeness, evidence integrity/retention/current validity and complete dependency visibility remain writer prerequisites. The previously delivered internal preflight can check inequalities only; it is not an authenticated package or acceptance proof. Read-only diagnostics are not a lease, transactional authorization snapshot or general concurrent-revocation certification.

## Verification / limitations

**47 application tests, 260 assertions PASS, 103.48s**:18 new ScopeAdmissionAuthorityHttpTest +17 existing internal authority +12 reader HTTP tests. Focused/affected only, no full regression. Includes guest/owner boundaries, EN/FA/RTL and private no-store, action-specific form/history, independent source reader/no inherited admin, no default actions, prohibited browser participant claims, hidden source and no source metadata leak, source-free opaque reduction (query audit), stale/repeat/active-field injection, hidden reactivation deny/no revival, foreign selectors, wrong aggregate policy ID, reader revocation/live role removal, participant exit reduction, archive read-only/write denial, escaped history/type-safe old input, and mutation/payload/CDE/stock query prohibition.

Initial15-test run exposed a copied hidden-field list incorrectly trying to index action in the selected source tuple; fixed to include batch_id and bind action separately. The corrected15/139 run and failed12-pass run overlap final47/260; never add counts. Six PHP Pint/lint, Blade compile/clear, five-route audit, ordinary NOSUPERUSER/NOBYPASSRLS FORCE-RLS/invoker audit, unchanged prior-service/migration checks, locale/CSRF-redacted five synthetic server-rendered HTML captures and guide-link/whitespace checks passed. Static-audit command initially changed its working directory before writing follow-on audit files; those independent checks were rerun from repo root, without rerunning tests.

Evidence: `docs/evidence/2026-10-24-admission-authority-web/`. Captures are HTTP-test synthetic fixtures, not browser screenshots or production data. Tests use ordinary epcc on private socket-only PG, stopped after verification. No production migration/grant/data/deployment, CI/commit/push, browser or concurrency certification. Package is a dependent source increment, excludes vendor/runtime/DB. Prior packages were not regenerated.


Final narrow input hardening rejects associative selector maps with HTTP422 before they can reach the JSON-array DB CHECK. After this controller-only/list-validation change, **all18 HTTP tests/152 assertions PASS (41.89s)** in final-http.log. The preceding47/260 affected run was before this final hardening; unchanged internal and reader-HTTP code was not needlessly rerun. Runs overlap, not additive.

## Next bounded work and recommendation

Before an admission writer, specify and implement append-only authenticated proposal/review package storage with canonical exact-version identity, independent reviewer binding and visibility closure; then resolve real historical topology/coverage and independently controlled evidence validity. **Do not accept browser-supplied participant UUIDs, matching hashes or structural resemblance as technical approval.** Keep incomplete/unverifiable packages blocked, observation records UNADMITTED and gross/remaining unknown. Reviewed correction/withdrawal must preserve the accepted immutable lifecycle. Effective selection and complete commitment ledger remain prerequisites for DA-03 reservation. No settled actor-policy question needs reopening.
