# Demand reader web delivery evidence

final-affected.log: 45/240 PASS (83.64s), HTTP + service + incoming traceability. final-http-bilingual.log: 12/61 PASS (19.58s) after fixing language precedence in test helper, overlapping not additive; no runtime changes between these runs. initial-http.log retains overly broad assertion about caller-supplied scope in language link. format.log includes an initial generated translation missing comma, corrected before behavior tests. static.log: six-file formatting/lint, Blade compile/clear, three routes, diff whitespace. rls-audit.log: ordinary role and FORCE RLS unchanged.

http-config-{en,fa}.html and http-readiness-{en,fa}.html: synthetic HTTP responses, not browser screenshots, CSRF fields redacted. Final artifact-only test redaction adjustment was syntax-checked; no additional behavior count. predecessor-and-artifacts.log: expected changed shared files, other manifest sources unchanged, guide links and artifact language/redaction verified.

No migration/new role/default reader/reservation, production data or deployment. See bilingual DEMAND-READER-WEB guides including source-hidden suspension limitation. Private socket-only test DB stopped. Source package is dependent, not vendor/runtime/DB or complete runnable project.
