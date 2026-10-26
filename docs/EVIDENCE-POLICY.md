# Evidence policy

`docs/evidence/` stores machine-generated proof-of-run artifacts: phpunit
streams, junit XML, `summary.json` run manifests, redacted HTTP captures and
screenshots. They are **outputs, not source**:

- **Do not commit them.** The directory is git-ignored. Committing a full
  cycle added ~16 MB and 1000+ files to every clone and CI checkout while
  the artifacts were regenerable in minutes.
- **Regenerate on demand** with `scripts/run-tests.sh` (full suite) or the
  targeted `vendor/bin/phpunit --filter …` invocations named in each ADR.
- **What stays in git:** the `summary.json` run manifests that ADRs cite may
  be quoted inline in the ADR itself (a few lines). Cite the run id, the
  phpunit version, the commit SHA and the date so any reviewer can reproduce
  the exact artifact locally.
- **Honesty rules for manifests** (carried over from the existing
  `summary.json` files): a manifest must state whether the run was admitted
  (`new_phpunit_run: true/false`), the exact command, and the environment.
  Never present a stale or partial run as a fresh green suite.
