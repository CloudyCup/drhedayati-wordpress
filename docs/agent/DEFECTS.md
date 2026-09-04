# Defects and acceptance gaps — 2026-09-04 (updated same day, post-fix)

Reviewed at 345e368; fixes/coverage below applied at commits 8400588 / 06db2e2 / 2af798d /
1b16a6d. These are harness defects and evidence gaps, not newly proven product vulnerabilities.
**No product code changed** — every change below is to `docker/wp-tests/*`, `scripts/lib.*`, or
`docker/.env.example`.

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
