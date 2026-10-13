
## 7. The DELETED state (2026-09-18)

Accepted. Nothing that carries a tag is ever removed from the database; it is
marked `DELETED` and keeps its row, and therefore keeps its tag.

**The reasoning.** Section 6 recorded why automatic numbering was withdrawn:
`max(sequence)+1` cannot see rows that are gone, so a deletion silently reissued
a number. Withdrawing allocation closed that. It did not close the mirror case —
a hard `DELETE` frees the tag *string*, and the unique index then happily
accepts a re-register of a tag that is already printed on an issued isometric
and stamped on a nameplate. A tag that has left the system can never be handed
to a second object, and the only reliable way to guarantee that is to keep the
row that owns it.

**Scope.** `equipment`, `piping_lines`, `equipment_nozzles` — the tagged things.
Components and joints are excluded: they churn constantly during modelling, no
tag leaves the system with them, and the provenance columns would bloat the two
largest tables to no purpose.

**Refused while connected.** A vessel whose nozzles are welded to live lines is
not a data-entry mistake. Deleting it would leave those lines joined to
something that no longer exists — the orphan `ComponentService::remove()`
already guards against. Detach first.

**Not `SoftDeletes`.** Laravel's trait adds a global scope, which would make a
withdrawn pump silently absent from the register. "Where did P-101A go?" must
have an answer. Deleted rows remain visible and labelled; excluding them is an
explicit `->live()`.

**Terminal.** There is no undelete. An audit trail that says "deleted on the
3rd" and then shows the object alive is a lie. Re-enter the object, which leaves
both the mistake and the correction on the record.

**Consequence for future states.** `piping_lines_afc_has_provenance` encoded
"not DRAFT ⇒ has AFC evidence" and became false the moment a fourth state
existed. Any CHECK phrased as "everything except X" is a hazard when the state
set grows.
