# Defects and acceptance gaps — 2026-09-04 (updated same day, post-fix)

Reviewed at 345e368; fixes/coverage below applied at commits 8400588 / 06db2e2 / 2af798d /
1b16a6d. HD-001–HD-005 are harness/CI defects and evidence gaps, not product vulnerabilities —
**no product code changed** for those; every change was to `docker/wp-tests/*`, `scripts/lib.*`,
`docker/.env.example`, or `docker/docker-compose.yml`. **HD-006 is a real product defect**,
found by GitHub Actions run #2 of "Acceptance (Docker WordPress)" once HD-005 let the suite
actually execute against a real WordPress — see below.

## HD-001 — FIXED & VERIFIED — Bash bootstrap fails on default configuration

docker/.env.example:16 contained `WP_TITLE=Hedayati Local Test`. scripts/lib.sh sourced it under
`set -e`; Bash interpreted `Local` as a command and exited before Docker was checked. Independently
reproduced with Git Bash: `line 16: Local: command not found`, exit 1.

**Fix (8400588):** `scripts/lib.sh` no longer sources `.env` — it parses `KEY=VALUE` by hand
(`load_dotenv`), stripping one matching pair of surrounding quotes, same as Compose.
`scripts/lib.ps1`'s regex loader strips the same quote pair so both loaders agree.
`docker/.env.example` quotes `WP_TITLE="Hedayati Local Test"` to make the supported syntax
explicit. **Verified on this machine:** `source scripts/lib.sh` now reaches the Docker Compose
check and fails there for the expected reason (Docker absent), not on `.env` line 16.

## HD-002 — OPEN (coverage added, not yet executed) — Phone deletion acceptance discrepancy

Owner reports one orphan phone row after QA-user deletion, manually removed. class-user-phone-service.php registers deleted_user -> delete_phone, but the hook's presence does not prove runtime cleanup.

**Coverage added (06db2e2):** `test-phase-2a.php` now assigns a synthetic phone, deletes that
user via `wp_delete_user()`, and asserts BOTH the user is gone AND zero phone rows remain for its
ID — checked directly, **before** `HDIT_Env::reset()` runs, so the later table-wide DELETE cannot
hide a failure. **Still OPEN**: this assertion has not been executed on a real WordPress runtime
(no Docker/PHP on this machine). Do not treat HD-002 as closed until it passes on a Docker-capable
host. Related staging IDs: T2.8, T3.15 step 5, T3.16.

## HD-003 — PARTIALLY ADDRESSED (coverage added, not yet executed) — Runtime suite gaps

**Added (2af798d):**
- A2/A3: `handle_run_save()` exercised with no nonce and with a valid nonce but insufficient
  capability (student), via a `wp_die`/`wp_redirect` interceptor (`HDIT_AdminPost`) that throws
  instead of ever reaching the handler's real `exit()`.
- A4: an explicit staffed-on-X-not-Y per-run-scope assertion.
- A5: `handle_attendance_save()` exercised as a manager who lacks `hedayati_record_attendance`.
- T2: the 1:1 refusal now goes through the real `Hedayati_Teacher::save()` handler (nonce +
  capability + `$_POST`), not just a direct postmeta write.
- T5: the REST 404 checks are repeated as an authenticated low-privilege (student) request.
- G2: cancelled runs (not just completed) refuse enrollment.
- S3/G5: direct `delete_session()` / `delete_enrollment()` calls are asserted to cascade
  attendance.
- G6: the user-deletion cascade test now creates an attendance row first.
- J6: `hedayati_view_audit_logs` gates `Hedayati_Audit_Log::current_user_can_view()`.
- J8: an out-of-vocabulary `action` filter sanitizes to zero rows.

**Still open / explicitly out of scope for this suite** (documented in test-phase-2b.php's
docblock, not silently claimed as covered):
- R5: still a representative has/not subset, not all 22 capabilities across all 6 roles.
- B5/J9: `migrate()` against an already-current version only exercises the no-op early return,
  not a second real `dbDelta` pass.
- J1/J4: not literally every mutation/action/count is asserted individually, though the
  create/fail-silence/append-only/no-PII shape is (J1 partial, J4 not attempted — actor
  attribution across a WP-CLI-run mutation is untested here).
- Index `Non_unique` inspection and full engine/charset coverage remain untested.

None of this has been executed on a real WordPress runtime yet — treat every addition above as
"authored, not proven" until a Docker-capable host runs it green.

## HD-004 — FIXED (behavior corrected, not yet executed) — Cleanup failure can now be trusted

docker/wp-tests/run.php computed `HDIT::finish()` before the final reset, caught reset exceptions
as warnings without changing the exit code, and always printed "environment reset to a clean
state". helpers.php's `reset()` ignored every DELETE's return value.

**Fix (1b16a6d):**
- `HDIT_Env::reset()` now returns `bool`: it tracks every delete's result AND independently
  re-queries afterward (`verify_clean()` — per-table row counts, a synthetic-user search, a
  synthetic-post search) instead of trusting the write results alone.
- `HDIT_Env::assert_disposable_environment()` is a hard guard, run first, that throws unless
  invoked via WP-CLI **and** `wp_get_environment_type() === 'local'` (hardcoded by
  docker/docker-compose.yml). This does not make misuse impossible, but it stops the harness from
  quietly running its deletes against a WordPress that isn't the disposable container.
- `run.php` tracks the assertion result and the final cleanup separately: "environment verified
  clean" prints only when `reset()` actually returned true; a verified cleanup failure now yields
  a distinct exit code (`3`) instead of a footnote next to a claimed-successful line.

Not executed here — no Docker/PHP on this machine. The corrected logic itself has not run against
a real WordPress database; the next Docker-capable run should confirm `reset()` returns `true`
(not just that the suite doesn't crash).

## HD-005 — FIXED (CI/environment infra only) — wpcli container detected WP_ENVIRONMENT_TYPE=production in GitHub Actions

First run of "Acceptance (Docker WordPress)" on GitHub Actions failed before any assertion ran:
`assert_disposable_environment()` (HD-004's guard) correctly refused with `WP_ENVIRONMENT_TYPE is
"production", expected "local"`. This is the guard doing exactly its job — CI/local-test
infrastructure was misconfigured, not a product acceptance failure, and the guard was **not**
weakened or bypassed to make the run go green.

**Root cause:** the official `wordpress` Docker image's `wp-config.php` is a thin template that
reads `WORDPRESS_*` variables — including an `eval()` of `WORDPRESS_CONFIG_EXTRA` — via `getenv()`
**fresh in every PHP process**, not baked into the file once and shared. `docker-compose.yml` only
declared `WORDPRESS_CONFIG_EXTRA` (carrying `define('WP_ENVIRONMENT_TYPE','local')`) on the
`wordpress` service. The `wpcli` container mounts the same `wp-config.php` file over the shared
`wp_core` volume, but because that file re-evaluates env vars per-process rather than sharing a
baked value, the `wpcli` container's own environment never had `WORDPRESS_CONFIG_EXTRA` (or
`WP_ENVIRONMENT_TYPE`) set — so the constant was never defined there, and WordPress core's
`wp_get_environment_type()` fell through its `getenv('WP_ENVIRONMENT_TYPE')` check (also unset) to
its hardcoded `'production'` default. This didn't surface locally only because it was never run
against a real Docker host until this CI run.

**Fix:** `docker/docker-compose.yml` now sets a plain `WP_ENVIRONMENT_TYPE: "local"` environment
variable directly on **both** the `wordpress` and `wpcli` services — the same mechanism
`wp_get_environment_type()` supports natively, independent of `wp-config.php`/`WORDPRESS_CONFIG_EXTRA`
entirely. `scripts/run-acceptance.{sh,ps1}` gained a preflight step that prints
`wp_get_environment_type()` as seen inside the `wpcli` container before the suite runs, so any
future drift is visible in the first few lines of CI output instead of requiring a second run to
diagnose. `HDIT_Env::assert_disposable_environment()` (the guard itself) was not touched.

## HD-006 — FIXED (product code; plugin 1.5.3) — object-level edit_post/delete_post still false for manager/administrator on a real Teacher profile

GitHub Actions run #2 (first run with the environment correctly detected as `local`, HD-005) ran
the real acceptance suite against a live WordPress and got **212 passed, 2 failed**:

```
[FAIL] manager: current_user_can("edit_post", <teacher>) [meta cap maps down]
[FAIL] administrator: current_user_can("edit_post", <teacher>)
```

This is the first genuine **product** authorization defect this suite has caught (not a harness or
CI problem) — confirmed against real WordPress `map_meta_cap()` behaviour, not guessed from static
string matching.

**Root cause:** the Teacher CPT's `register_post_type()` sets `map_meta_cap => true` and an
explicit `capabilities` array, but that array never set `edit_published_posts`,
`edit_private_posts`, `delete_published_posts`, or `delete_private_posts`. With
`map_meta_cap => true`, WordPress's `get_post_type_capabilities()` auto-fills any *omitted* key
among those four from `capability_type` (`'hedayati_teacher'`) — e.g.
`edit_published_hedayati_teachers` — a capability string that was never granted to any role,
including `administrator` (WordPress's native admin role has no wildcard; it only holds the
explicit list from `populate_roles()`, which does not include arbitrary custom-CPT-derived names).
WordPress core's `map_meta_cap('edit_post', $user_id, $post_id)`, for a post the acting user did
not author (a Teacher profile's `post_author` is `0`) with status `publish` — the Teacher CPT's
normal status — requires **both** `edit_others_posts` (correctly wired to `hedayati_manage_teachers`
since 1.5.2) **and** `edit_published_posts` (silently the ungranted auto-filled name). Both must
resolve true; the second never could, so `current_user_can('edit_post', $teacher_id)` was false for
every role including administrator, regardless of the 1.5.2 fix. `delete_post` has the identical
trap via `delete_published_posts` (any status) / `delete_private_posts` (private status) — untested
until now, so it carried the same latent bug with no prior failing assertion to reveal it.

This is a **different bug from 1.5.1's** T1 collision (which was about the three *meta-cap key
names* — `edit_post`/`read_post`/`delete_post` — colliding with the primitive). 1.5.2's fix was
correct and necessary for what it targeted; it could not have caught this, because this is about
four *different*, previously-omitted capability keys that `map_meta_cap()` also consults.

**Fix (plugin `1.5.3`, `includes/class-teacher.php` only):** declare all four status-conditional
capabilities explicitly, pointed at the same single primitive:

```php
'edit_published_posts'   => 'hedayati_manage_teachers',
'edit_private_posts'     => 'hedayati_manage_teachers',
'delete_published_posts' => 'hedayati_manage_teachers',
'delete_private_posts'   => 'hedayati_manage_teachers',
```

No new managed capability, no role change, no DB change: `hedayati_manage_teachers` remains the
one primitive; `Hedayati_Roles::ROLES_VERSION` stays `2.1.0`, the managed-capability count stays
`22`, and `Hedayati_DB_Schema::CURRENT_DB_VERSION` stays `2.2.0` — this is purely a
`register_post_type()` capability-map completeness fix, exactly like 1.5.2 was.

**Regression coverage added:**
- `docker/wp-tests/test-phase-2b.php` — asserts the four new cap-map values directly, then
  `current_user_can('edit_post'|'delete_post', ...)` for manager **and** administrator against
  both a `publish`-status and a `private`-status Teacher profile, plus `delete_post` added to the
  existing denial checks for reception/teacher/TA/student.
- `plugin/hedayati-core/tests/test-phase2b.php` (§9b) and
  `plugin/hedayati-core/tests/verify-phase2b.js` (§9c) — static regex-based checks (no WordPress
  boot required) that the four keys are declared and point at the primitive, with a negative
  control proving the 1.5.2-era config (keys absent) would trip the guard.

**Status: FIXED IN CODE, NOT YET RE-VERIFIED.** Do not mark Teacher CPT authorization (T1/T2) as
PASS until GitHub Actions run #3 (or later) is green end-to-end. The next CI run is the actual
proof; this entry describes the fix, not a confirmed result.
