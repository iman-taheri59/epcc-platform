# The guard registry — where a guard's text lives (English)

> Status: implemented 2026-10-29. Decision: [`ADR-059`](ADR-059-GUARD-SQL-SINGLE-SOURCE-EN.md).
> همزاد فارسی: [`GUARD-SQL-REGISTRY-FA.md`](GUARD-SQL-REGISTRY-FA.md).

## What a guard is

Forty-eight functions named `epcc_guard_*` refuse writes that would break an
invariant: a custody record that must not be rewritten, a QA decision bound to
the evidence it cites, a result recorded by someone the project actually
nominated, a legacy lot that must not be silently translated into a controlled
one. They are the part of the platform that says *no* — and the part that has to
be read carefully, because what they refuse is the specification.

## The problem this solves

Each guard was declared inline in the migration that introduced it. When a later
slice needed to change one IF, the only move available was to re-declare the
**whole body** in a new migration, identical except that one line. Today:

| Guard | Declared in |
|---|---|
| `epcc_guard_notification` | 3 migrations |
| `epcc_guard_piping_grn` | 2 (the A.4f copy changed one IF) |
| `epcc_guard_material_certificate` | 2 |
| `epcc_guard_joint_result` | 2 |
| `epcc_guard_piping_qa` | 2 |
| `epcc_guard_piping_receiving_review` | 2 |
| … and a dozen more | 2 each |

Copies are kept in step by hand, and **drift is silent**: the database ends up
saying something no single file in the repository says. Reading a migration tells
you what the guard said *then*, not what it says *now* — the only way to know the
current text was to ask a running database.

## The rule now

**`database/guards/<name>.sql` is the only place the current text lives.** One
file per guard; each is a complete `CREATE OR REPLACE FUNCTION` statement, taken
from `pg_get_functiondef()` — that is, exactly what the database is running.

- **To change a guard, edit its file.** Do not re-declare it in a migration.
- **A migration that binds a guard with `CREATE TRIGGER` must call
  `GuardSql::apply($name)` immediately before it**, so the trigger binds the
  current text even when the file changed in the same change.
- **A new guard** is added as a file, then applied and bound the same way.
- Names are validated (`^epcc_guard_[a-z0-9_]+$`) because a name is a path.

The historical copies stay in their migrations — they are how a database is built
in the first place — but migration `2026_10_29_200000_consolidate_guard_definitions.php`
applies all 48 files after them, so **the files have the last word**.

## Using it

```php
use App\Support\GuardSql;

GuardSql::apply('epcc_guard_piping_grn');   // one guard, before CREATE TRIGGER
GuardSql::apply(['a', 'b']);                // several
GuardSql::apply();                          // all of them

GuardSql::names();                          // the registry, sorted
GuardSql::body('epcc_guard_piping_grn');    // the canonical statement
GuardSql::installed();                      // name => pg_get_functiondef, as installed
GuardSql::definitionOf('epcc_guard_x');     // one definition, or null
```

## What enforces it

`tests/Feature/GuardDefinitionsTest.php` (6 tests / 150 assertions):

1. the registry is non-empty and every file is a well-named guard;
2. the set of files equals the set of installed guards — no orphan file, no
   unregistered function;
3. re-applying every file changes nothing — a freshly migrated database already
   agrees with the registry;
4. each file is valid SQL and idempotent on its own;
5. **no migration dated after the consolidated pass declares a guard inline** —
   this is the assertion that makes the rule true rather than aspirational;
6. every registered guard is actually bound by a trigger (nothing is orphaned).

## Why now, and why it was not possible before

Consolidating means replacing function bodies in an installed database. The owner
has stated that **no project has ever been registered with this software**, so
there is no installed database to carry forward. The window is open now and
closes at the first real project.

The files were extracted from the running database, not transcribed, so the
consolidated pass is a no-op by construction: behaviour is unchanged, and the
regression run says so.
