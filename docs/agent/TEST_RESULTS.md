# Test results

## AI Studio manager panel — course/featured in-panel tabs (2026-09-06) — STATIC GREEN, DOCKER PENDING

Branch `feature/manager-experience` (`59ce4ee` baseline = recovered Codex WIP):

| Check | Result |
|---|---|
| Node static suites | **769 / 0** (`76 + 208 + 132 + 84 + 118 + 98 + 53`) — `verify-phase3.js` gained 8 assertions for the in-panel course table, nonce/capability-guarded toggles, the server-side 8-slot cap, and "editing stays in the WP editor" |
| Local real WordPress/PHP acceptance | **NOT RUN — no PHP/Docker in the Claude Code environment.** `docker/wp-tests/test-phase-3.php` was extended (manager sees the real course row + toggle forms; reception gets no course table and a 403 from the toggle handler; feature flag round-trips on/off) and must be executed by `Acceptance (Docker WordPress)` in CI on the next branch push. |
| Real browser review | NOT DONE for `?view=courses` / `?view=featured` |
| Staging / production | NOT CONTACTED |

## AI Studio manager and student experience (2026-09-06) — GREEN LOCALLY

Branch `feature/manager-experience`:

| Check | Result |
|---|---|
| Node static suites | **762 / 0** (`76 + 208 + 132 + 84 + 111 + 98 + 53`) |
| Local real WordPress/PHP 8.3 acceptance | **499 / 0, PASS, cleanup verified** |
| Real browser review | `/panel/` manager home plus `/account/` student dashboard and upcoming schedule at desktop/mobile widths, Persian RTL, light/dark; no page-level mobile overflow (`scrollWidth === clientWidth`) |
| Staging / production | NOT CONTACTED |

## Phase 3 — launch completion (2026-09-05) — GREEN

Branch `feature/phase-3-launch-completion`. Baseline = the preserved Codex/ChatGPT WIP (commit
`7500348`, also `snapshot/codex-launch-completion-wip-2026-09-05`).

| Check | Result |
|---|---|
| `verify-phase2a.js` | 74 / 0 |
| `verify-phase2b.js` | 208 / 0 |
| `verify-phase2c.js` | 132 / 0 |
| `verify-phase2d.js` | 82 / 0 |
| `verify-phase3.js` (new) | 103 / 0 (historical Phase 3 result before manager-experience assertions) |
| `verify-audit-log.js` | 98 / 0 |
| `verify-jalali.js` | 53 / 0 |
| **Node static total** | **752 / 0** after the 1.8.1 lockout hotfix, every process exit 0 |
| `Acceptance (Docker WordPress)` GitHub Actions | run `33974539901` on the WIP baseline `7500348`: **450 / 0, PASS, cleanup verified** (first-ever real-WordPress runtime evidence for Phase 2D + launch WIP). |
| `Acceptance (Docker WordPress)` GitHub Actions | run `33975445108` on `046bd31` (feat commit): **489 / 0, PASS** (+39 = `docker/wp-tests/test-phase-3.php`); run `33976122273` on `6c9bdac` and the current tip: **491 / 0, PASS, cleanup verified** (+2 = the duplicate-phone / orphan-row guard). |
| PHP lint | Local PHP 8.3: changed account-security and staff-portal files pass. |
| Real local WordPress browser review | Desktop/mobile, RTL, light/dark: public pages, all account views, panel home/run, and forced-password screen reviewed on genuine HTTP responses; no page-level horizontal overflow. WordPress admin toolbar defect found and fixed for panel/forced flows. |
| Live staging / production | NOT CONTACTED. |

Plugin `1.8.1` adds a staging-discovered lockout-expiry regression check: `wp_login_failed` now
receives WordPress's final `WP_Error`, and an error already carrying `too_many_retries` does not
increment the counter or refresh its expiry. This adds two static assertions and one real
WordPress-runtime assertion; all prior thresholds and successful-login behavior remain unchanged.
Hotfix result: Node static **752/0**; local WordPress/PHP 8.3 acceptance **492/0**, synthetic-data
cleanup verified.

`test-phase-3.php` runtime coverage: temp-password generation (length ≥ 16, entropy classes,
uniqueness); reception-create → `must_change` marker set + `user_pass` stored only as a WP hash +
one-shot staff notice consumed exactly once + `account.created` audit (actor = reception, no
password/phone/id in the note); the full forced-change handler (too-short / mismatch /
missing-nonce all rejected with the marker intact; a valid change clears the marker, the new
password authenticates via `wp_authenticate()`, `account.password_changed` is audited without the
value; a post-change call with no marker leaves the password untouched); `hedayati_create_students`
role matrix (student/teacher/TA false, reception/manager/admin true); manager
course/category/settings capability resolution against real `map_meta_cap()`. `test-launch.php`
(from the WIP) also passes on CI: full role × {course, category, settings} matrix, staff privacy,
public opt-in defaults, cross-user denial.

**Merged to `main` via `e04c343`; not deployed, no `mystik.ir`/`drhedayati.com` contact.** Interceptor/guard
`template_redirect` behaviour and real multipart upload remain Phase 4 staging-acceptance items
(documented in the test file headers, same class as Phase 2C/2D).

---

# Test results — 2026-09-04

**Reconciliation note (2026-09-05):** everything below is preserved as an accurate historical
record of this specific review session (branch `feature/phase-2b-academic-operations`, HEAD
`345e368`) — do not edit the narrative itself. Since this was written, that branch and
`feature/phase-2c-student-portal` (Phase 2C) were both merged into `main` via a single `--no-ff`
merge commit, `32640e4` (2026-09-05). The assertion counts below (449 Node, then 458 after HD-006)
are superseded by `main`'s current 565/0 Node total and the Phase 2C Docker acceptance suite's
335/0 result — see `docs/agent/STATUS.md` for current figures. Phase 2C staging acceptance remains
NOT RUN regardless of the merge.

Repository: C:/Projects/drhedayati-wordpress
Branch: feature/phase-2b-academic-operations (historical — see reconciliation note above)
Reviewed HEAD: 345e368bfa1a17079c7436c085e9514f441aee5e
Initial git status: clean. Changes from this review are documentation only, uncommitted.

## Results

| Check | Result |
|---|---|
| verify-phase2a.js | PASS: 74 / 0 |
| verify-phase2b.js | PASS: 199 / 0 |
| verify-audit-log.js | PASS: 98 / 0 |
| verify-jalali.js | PASS: 53 / 0 |
| verify-phase2c.js | PASS: 25 / 0 |
| Node total | PASS: 449 / 0; every process exit 0 |
| Bash loading shipped .env.example with set -eu | FAIL: line 16: Local: command not found; exit 1 (HD-001) |
| WordPress integration suite | NOT RUN — no working container runtime |
| PHP lint / isolated PHP suites | NOT RUN — PHP unavailable |
| Live staging / production | NOT CONTACTED |

Docker and PHP were absent from PATH and conventional installation locations; Podman/nerdctl were absent from PATH. An unsandboxed read-only WSL status check found Ubuntu registered, default version 2, but WSL2 unsupported in the current configuration; the diagnostic requests Virtual Machine Platform and BIOS virtualization. This is not evidence of a working Linux runtime.

Reviewed AGENTS.md, CLAUDE.md, CURRENT_STATE, LOCAL_TESTING and PHASE_2B_ACCEPTANCE; all new Compose/Dockerfile/environment files, lifecycle scripts in both languages, and all four wp-tests files. Compared relevant service signatures, hooks, schema and admin authorization paths. Consulted existing product/design/security/data/deployment requirements. No runtime assertion has been executed; the advertised approximate assertion count is unverified.

## Execution handoff

1. Resolve HD-001 for Bash; keep PowerShell dotenv interpretation consistent.
2. On a disposable Docker host, run scripts/run-acceptance.sh (or .ps1 on Docker-capable Windows) from this repository.
3. Record full failing assertion names and exit code. Include a direct phone-row check after wp_delete_user and before HDIT_Env::reset for HD-002.
4. Complete missing coverage listed in HD-003; do not equate service tests with admin authorization acceptance.
5. Verify cleanup and run env-down to remove disposable volumes. HD-004 currently prevents trusting cleanup success text alone.

No dependency installation, VM provisioning, deployment, push, merge or commit was performed. Staging facts in STATUS.md are attributed to the owner's canonical handoff, not to this local review.

## Follow-up (same day): HD-001 fixed, HD-002/003/004 addressed

Commits 8400588 (HD-001), 06db2e2 (HD-002 coverage), 2af798d (HD-003 coverage), 1b16a6d
(HD-004 fix). See docs/agent/DEFECTS.md for the per-issue detail; summary:

- HD-001 fixed and independently re-verified here: `source scripts/lib.sh` now reaches the
  Docker Compose check instead of crashing on `docker/.env` line 16.
- HD-002 and HD-003 gained targeted runtime assertions (direct phone-cleanup check;
  admin-post A2/A3/A5 gate via a wp_die/wp_redirect interceptor; A4 scope; T2 via the real
  save handler; T5 authenticated; G2 cancelled-run; S3/G5 direct-delete cascades; G6 with
  attendance; J6/J8). None of this has executed against a real WordPress yet — both remain
  OPEN until a Docker-capable host runs it.
- HD-004 fixed: `HDIT_Env::reset()` now returns a verified bool (re-queries table counts and
  searches for leftover synthetic users/posts) and `run.php` only reports a clean state when
  that verification actually passed; a verified-cleanup failure now has its own exit code (3).
- Re-ran the Node static suites after these changes: still 449/0 (verify-phase2a 74,
  verify-phase2b 199, verify-audit-log 98, verify-jalali 53, verify-phase2c 25). Docker and
  PHP remain absent from this machine — the runtime suite and PHP suites are still NOT RUN.
- Added `.github/workflows/acceptance-docker-wordpress.yml` ("Acceptance (Docker WordPress)")
  so the runtime suite can actually execute on a Linux runner; it has not fired yet (nothing
  was pushed).

## GitHub Actions run #1 and #2 (2026-09-04)

- **Run #1:** failed before any assertion ran — `assert_disposable_environment()` correctly
  refused with `WP_ENVIRONMENT_TYPE is "production", expected "local"`. CI/local-test
  infrastructure defect, not a product failure. Root cause and fix: HD-005 (commit `afb5fbd`).
- **Run #2** (first run with the environment correctly detected as `local`): **212 passed, 2
  failed, 214 assertions total.** The only failures:
  ```
  [FAIL] manager: current_user_can("edit_post", <teacher>) [meta cap maps down]
  [FAIL] administrator: current_user_can("edit_post", <teacher>)
  ```
  This is the suite's first genuine **product** finding — a real Teacher CPT authorization gap,
  not a CI or harness problem. Root cause, fix (plugin `1.5.3`), and regression coverage: HD-006
  in `docs/agent/DEFECTS.md`.
- **Run #3** (commit `cbcb4da`, after the HD-006 fix):
  https://github.com/CloudyCup/drhedayati-wordpress/actions/runs/33910009101 — job "Phase 2A + 2B
  runtime acceptance" concluded `success`. **228 passed, 0 failed, cleanup verified, result PASS**
  (up from run #2's 214 total; +14 matches the HD-006 regression assertions exactly). Node static
  suites re-run the same day: **458 passed, 0 failed** (verify-phase2b rose from 199 to 208 for
  the same reason — the new §9c static regression block). This is the first fully green execution
  of the Docker runtime suite. Teacher CPT object-level authorization (`edit_post`/`delete_post`
  for manager and administrator, publish and private status) is now **runtime-verified in this
  disposable environment**. It was **not**, at that point, a staging (`mystik.ir`) retest.

## Staging smoke test — plugin 1.5.3 on mystik.ir (2026-09-04) — PASSED

Manually verified on staging by the operator (not this reviewer's environment; reported, not
independently re-run here — same evidentiary status as the other owner/operator-reported staging
results in this file):

- homepage loads
- wp-admin loads
- Hedayati Core reports `1.5.3`
- «اساتید» menu appears and opens
- disposable Teacher creation works
- Teacher edit/save works
- Teacher deletion works
- `hedayati_manage_teachers` resolves correctly for `administrator`

This is T1's retest scope (menu visibility + admin-list access + object-level create/edit/delete
for `administrator`) executed on the real staging environment, directly confirming the HD-006 fix
where it actually matters. Combined with GitHub Actions run #3 (228/0, above) and the static
suites (458/0), **HD-006 is now CLOSED** (see `docs/agent/DEFECTS.md`). No production
(`drhedayati.com`) contact occurred. This smoke test did **not** exercise the low-privilege
negative matrix (reception/teacher/TA/student) on staging, nor the broader Phase 2B functional
matrix (enrollment/attendance/sessions/audit rows) on staging specifically — those remain covered
by the local Docker CI suite only, not independently re-run on `mystik.ir`.
