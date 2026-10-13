# Project working rules

Read `HANDOFF.md`, `docs/ADR-PROCESS.md` and the relevant primary ADRs before changing a feature. An implementation report or a code comment that cites an ADR is not a substitute for reading that ADR and its subsequent amendments.

## Mandatory user instruction — 2026-09-29

«برای هرقابلیت و امکان جدید باید adr. مربوطه تولید یا اصلاحات و موارد جدید به adr موجود در آن موضوع اضافه شود.»

- Every new capability must have an ADR: extend the ADR for an existing subject, or create a new numbered ADR for a genuinely separate decision.
- Read and record applicable ADR sections BEFORE design/implementation. Follow accepted decisions unless the user explicitly changes them.
- Update the ADR in the same work increment, including decisions, alternatives, authority, data/lifecycle effects, migration implications, acceptance checks and remaining gaps. Do not postpone this to a future delivery.
- Separate **user-confirmed decision**, **proposal awaiting decision**, **implemented behavior**, and **verified behavior**. Code is not approval; tests are not proof of architectural conformity.
- Retain history. Use dated amendments with explicit supersession references; do not silently replace earlier accepted decisions to make current code look compliant.
- For a mismatch, record it in the reconciliation/TODO first. Implement the accepted decision, or obtain an explicit change of decision. An as-built note cannot authorize a deviation retroactively.
- Completion requires ADR traceability plus code/test/document consistency. A partial capability must be described as partial.
- For PMS, read `docs/ADR-008-PMS-Piping-Class-Rules.md` §12 and `docs/ADR-RECONCILIATION-2026-09-29.md` before adding more functionality. Open gaps are not fixed by the documentation update.

Apply this throughout the repository, including UI, backend, schema, engineering reference data, authorization and integrations. Follow additional directory-specific guidance as well.

## Review the decisions themselves, not only compliance

The user additionally requires reporting defects or omissions in prior ADR decisions **with reasons**. Do not treat an accepted ADR as immune to technical criticism. Distinguish a flawed/underspecified decision, contradictory/stale prose, an unverified factual claim and an implementation gap. Cite the governing section, explain impact and alternatives, and label proposed changes as pending rather than silently superseding the accepted decision. Current scoped findings: `docs/ADR-DECISION-REVIEW-2026-09-29.md` (DR-01…DR-06 and factual notes).

- For ADR decision critiques, present each issue separately, ask how to resolve it and receive the user’s response before proceeding. Record accepted decisions separately from implementation; respect explicitly deferred questions.
