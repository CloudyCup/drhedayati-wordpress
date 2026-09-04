# Phase 2B — Academic Operations Acceptance (staging matrix)

**Status: PARTIAL — owner-reported staging health gate and administrator Teacher retest passed; broader functional matrix open.**

2026-09-04 canonical handoff: plugin 1.5.2, DB 2.2.0, roles 2.1.0, six new
InnoDB/utf8mb4 tables, healthy homepage/admin, Teacher and Course Run creation passed.
This is owner-reported evidence, not an independent run here. Full per-role negatives,
functional cases and local runtime acceptance remain open. See agent/STATUS.md and
agent/DEFECTS.md. Older NOT RUN statements below describe the original plan and are
superseded only for these explicitly reported health checks.

Phase 2B (Teacher CPT, Course Runs, staff assignment, sessions, enrollments,
attendance, **metadata-only audit log**) plus the Phase 2C address-profile slice
were implemented on branch `feature/phase-2b-academic-operations`. As with Phase
2A, **runtime/behavioural acceptance runs on staging (`mystik.ir`)** and is a
**pre-merge / pre-deployment gate**. The original matrix below is not fully executed; the reported health checks above do not close every assertion within a row.

Constraints (unchanged from `docs/PHASE_2A_ACCEPTANCE.md`): operator drives every
authenticated step; no destructive DB changes without per-test approval; no
production contact; take a fresh full backup before any state-changing test.

---

## 2026-09-03 — staging pre-check finding: Teacher CPT authorization (T1) FAILED on 1.5.1; fixed in 1.5.2

A staging capability probe of the (not-yet-deployed-as-current, but built) Teacher CPT
found that **section C test T1 fails on plugin `1.5.1`**:

- **Observed on staging:** `administrator` (user ID 1) — `get_role('administrator')->has_cap('hedayati_manage_teachers')` = YES and
  `$user->allcaps['hedayati_manage_teachers']` = true, **but**
  `current_user_can('hedayati_manage_teachers')` = **false**; the «اساتید» menu was
  absent and `/wp-admin/edit.php?post_type=teacher` returned "you need a higher
  level of permission".
- **Root cause — WordPress meta-capability collision.** `class-teacher.php` registered
  the CPT with `map_meta_cap => true` while assigning the **primitive**
  `hedayati_manage_teachers` as the *value* of the singular meta caps `edit_post` /
  `read_post` / `delete_post`. WordPress's `_post_type_meta_capabilities()` copies
  those three values into the global `$post_type_meta_caps` map as **keys**, and
  `map_meta_cap()` then rewrites any incoming `hedayati_manage_teachers` check into a
  per-object `edit_post`/`read_post`/`delete_post` check. With no object ID
  (`current_user_can('hedayati_manage_teachers')`, menu checks, list-table access)
  the mapped check calls `get_post(null)` and returns `do_not_allow` — so the
  primitive appeared "unheld" even though the role grants it.
- **Fix (plugin `1.5.2`, `includes/class-teacher.php` only):** the singular meta caps
  now use **distinct** names — `edit_hedayati_teacher` / `read_hedayati_teacher` /
  `delete_hedayati_teacher` — which `map_meta_cap()` resolves down to the collection
  caps (`edit_posts` etc.), all of which require the single primitive
  `hedayati_manage_teachers`. `hedayati_manage_teachers` stays a plain primitive and
  is never object-scoped. **No DB schema, `CURRENT_DB_VERSION` (2.2.0),
  `ROLES_VERSION` (2.1.0) or managed-capability-count (22) change** — this is a CPT
  mapping fix, not a role migration. The distinct meta-cap names are **not** added to
  any role.
- **Regression guard added:** `verify-phase2b.js` §9b and `test-phase2b.php` §9 parse
  the actual `capabilities` map, assert the three meta caps never reuse the primitive
  or a collection-cap name, and port WP's `_post_type_meta_capabilities()` +
  `map_meta_cap()` collision logic — including a negative control that the exact
  `1.5.1` config trips the guard. The former assertion (a bare
  `contains("=> 'hedayati_manage_teachers'")` string check) stayed green through the
  bug and has been removed.
- **Administrator T1 retest passed per owner handoff:** primitive capability true, menu accessible, Teacher profile creation works. Full manager/low-privilege negative matrix remains open.

---

## What was verified in the repository (REPOSITORY VERIFIED — not on staging)

Independently re-executed on PHP 8.4 on 2026-09-03 against the current Session-3 HEAD. These
figures **replace** the older pre-fix/pre-cleanup counts (56 PHP files, Phase 2A 77/78, Phase 2B
112/113, audit-log suite "awaiting re-run").

**Updated 2026-09-03 for plugin `1.5.2`** (Teacher CPT meta-cap fix). Node suites re-run by
Claude at `1.5.2`; the `php` figures are the last independent PHP 8.4 run at `1.5.1` and are
**pending an independent re-run at `1.5.2`** (`test-phase2b.php` gained the §9 Teacher-cap guard;
Claude has no PHP binary).

| Check | Tool | Result |
|---|---|---|
| Node — Phase 2B static + logic + behavioural port (incl. §9b Teacher meta-cap guard) | `node …/verify-phase2b.js` | **199 / 0** (2026-09-03, `1.5.2`) |
| Node — audit log | `node …/verify-audit-log.js` | **98 / 0** (2026-09-03) |
| Node — Shamsi/Jalali (incl. multi-decade round-trip fuzz) | `node …/verify-jalali.js` | **53 / 0** (2026-09-03) |
| Node — Phase 2C address slice | `node …/verify-phase2c.js` | **25 / 0** |
| Node — Phase 2A regression | `node …/verify-phase2a.js` | **74 / 0** — no regression |
| **Node total** | | **449 / 0** (`1.5.2`) |
| `php -l` — all **48** tracked PHP files | independent inspection, PHP 8.4, 2026-09-03 (`1.5.1`) | **48 / 48 PASS, 0 syntax errors** (syntax/parse only — not WordPress runtime). 56→48 because `package-plugin/` was removed (D27) |
| `php test-phase2a.php` | independent, PHP 8.4 (`1.5.1`) | **79 / 0** |
| `php test-phase2b.php` | independent, PHP 8.4 (`1.5.1`); **pending re-run at `1.5.2`** (added §9 Teacher-cap guard) | **115 / 0** at `1.5.1` |
| `php test-audit-log.php` | independent, PHP 8.4 (`1.5.1`) | **69 / 0** |
| `php test-jalali.php` | independent, PHP 8.4 (`1.5.1`) | **39 / 0** |
| **Independent PHP total (`1.5.1`)** | | **302 / 0** — re-run expected to rise with the new §9 guard |
| Claude re-execution of any `php` command | Claude dev env | **NOT POSSIBLE** — no PHP binary here; Claude re-confirmed the Node suites only |
| Package recreation | Claude, 2026-09-03 (`1.5.2`) | `hedayati-core.zip` **43 entries**, entry `hedayati-core/hedayati-core.php`, header + `HEDAYATI_CORE_VERSION` **1.5.2**; `hedayati.zip` **29 entries**, entry `hedayati/style.css` — layout/version confirmed, **not** a runtime check |

The Node suite covers: business-state allowlists, date/datetime/integer parsing,
Persian-digit normalization, migration 2.1.0 wiring (DDL, UNIQUE keys, dynamic
prefix, no `wp_` literal, no ENUM), roles schema 2.1.0 (`hedayati_manage_teachers`,
count = 22), plugin bootstrap wiring, and security-shape checks (nonces,
capability checks, `$wpdb->prepare`, per-run scope).

It does **not** and cannot prove (NOT STAGING / WORDPRESS-RUNTIME VERIFIED — do
not mark these as verified merely because the repository tests pass): `dbDelta`
execution and the migrations on `mystik.ir`, WordPress hook firing, capability
mapping in a live role structure, admin-UI behaviour, authentication behaviour,
real INSERT/UPDATE/DELETE behaviour, UNIQUE constraint enforcement, cascade
deletes, capacity enforcement, per-run scope against a live user, and the
deletion-cleanup hooks. The full matrix is still open; see the owner-reported health subset above.

---

## Staging matrix — partial health evidence; remaining cases open

### A. Migration 2.1.0

| # | Test | Expected |
|---|---|---|
| B1 | After deploy + `admin_init`, `hedayati_core_db_version` = `2.2.0` | migrations 2.1.0 then 2.2.0 ran in order; option advanced only on success |
| B2 | 6 new tables exist under the real prefix: `…hedayati_course_runs`, `…hedayati_run_staff`, `…hedayati_sessions`, `…hedayati_enrollments`, `…hedayati_attendance`, `…hedayati_audit_log` | InnoDB, utf8mb4 |
| B3 | Column / index audit matches `migrate_2_1_0()` + `migrate_2_2_0()` | incl. `uq_run_session`, `uq_run_user`, `uq_session_enrollment`; audit_log has **no** ip/user_agent/updated_at column, indexes `idx_object`/`idx_actor`/`idx_action`/`idx_created_at` |
| B4 | `hedayati_user_phones` unchanged (schema + row count) | Phase 2A data preserved |
| B5 | Re-run migrations (reset version marker on a backup) → idempotent, no dupe tables/keys | `dbDelta` no-op |
| B6 | Migration lock absent after run | no crashed/mid-migration state |

### B. Roles schema 2.1.0

| # | Test | Expected |
|---|---|---|
| R1 | `hedayati_core_roles_version` = `2.1.0`; `hedayati_core_managed_capabilities` = 22 entries | sync ran |
| R2 | `hedayati_manager` and `administrator` gain `hedayati_manage_teachers` | per D28 |
| R3 | No other role gains it; no Phase 2A capability lost | future-safe sync |
| R4 | `administrator` retains every native capability | non-regression |
| R5 | Full per-role matrix (closes Phase 2A T3.5) via `wp cap list <role>` | matches Appendix A + `hedayati_manage_teachers` on manager/admin only |

### C. Teacher CPT

| # | Test | Expected |
|---|---|---|
| T1 | `teacher` post type visible to manager/admin only; not to reception/teacher/student/TA | cap map to `hedayati_manage_teachers`. **FAILED on 1.5.1 (meta-cap collision); fixed in 1.5.2 for the bare primitive/menu/creation; administrator retest passed per owner for that scope.** **A second, distinct object-level gap was then found by the GitHub Actions Docker runtime suite (not staging): `current_user_can('edit_post'\|'delete_post', <teacher_id>)` on an *existing* profile still resolved `false` for manager AND administrator under 1.5.2** (`map_meta_cap => true` also requires `edit_published_posts`/`edit_private_posts`/`delete_published_posts`/`delete_private_posts`, never declared before 1.5.3 — see `docs/agent/DEFECTS.md` HD-006). **Fixed in 1.5.3. NOT YET RE-VERIFIED on CI or staging — do not mark T1 PASS until a green CI run confirms it.** Retest after deploy: as `administrator` and as `hedayati_manager` → `wp eval 'wp_set_current_user(1); var_export( current_user_can("hedayati_manage_teachers") );'` = `true`; «اساتید» menu present; `edit.php?post_type=teacher` loads; `wp eval 'var_export( current_user_can("edit_post", <teacher_id>) );'` = `true` for an existing **published** profile (not just a freshly-created one); `wp eval 'var_export( current_user_can("delete_post", <teacher_id>) );'` = `true`. As `reception` / `teacher` / `teacher_assistant` / `student` → all `false` and the direct URL denied |
| T2 | Linking a WP user to a Teacher profile; linking the same user to a 2nd profile is refused | 1:1 enforced in save |
| T3 | Deleting the linked WP user unlinks (does not delete) the Teacher profile | `on_user_deleted` |
| T4 | Teacher CPT not reachable on the front end (`publicly_queryable => false`) | public directory is Phase 2D |
| T5 | `GET /wp-json/wp/v2/hedayati_teacher` returns 404 / no teacher data (unauth **and** low-priv) | `show_in_rest => false` (D34) — regression check for the leak fixed on this branch |

### D. Course Runs

| # | Test | Expected |
|---|---|---|
| D1 | Create a run against a real course; bad `course_id` refused | `invalid_course` |
| D2 | Invalid `run_status` / `registration_status` fall back (draft / closed) | validated strings |
| D3 | Bad date, end-before-start refused | `invalid_*_date`, `date_range` |
| D4 | `capacity` / `tuition_rial` empty → NULL; negative / non-numeric refused | "unknown" ≠ 0 |
| D5 | Persian-digit capacity/tuition/date inputs normalize to ASCII | |
| D6 | Delete run cascades sessions + enrollments + attendance + staff | `delete_run()` |

### E. Sessions

| # | Test | Expected |
|---|---|---|
| S1 | Create session; duplicate `(run_id, session_number)` refused at service **and** DB | `session_number_exists` + `uq_run_session` |
| S2 | `starts_at` required and canonicalised; `ends_at` optional; ends ≤ starts refused | `time_range` |
| S3 | Delete session cascades its attendance | |

### F. Staff assignment

| # | Test | Expected |
|---|---|---|
| F1 | `primary_instructor` without a Teacher profile refused | `instructor_needs_profile` |
| F2 | Second `primary_instructor` on a run refused | `primary_instructor_exists` |
| F3 | `assistant` without a WP user refused | `assistant_needs_user` |
| F4 | Duplicate (same person, same role, same run) refused | `assignment_exists` |
| F5 | Deleting a WP user removes their `assistant` rows | `on_user_deleted` |
| F6 | Deleting a Teacher profile removes its instructor rows | `on_post_deleted` |
| F7 | `user_is_staff_on_run()` true for a linked-teacher instructor and for a TA user | scope helper |

### G. Enrollments

| # | Test | Expected |
|---|---|---|
| G1 | Enroll a student; duplicate `(run_id, user_id)` refused at service **and** DB | `already_enrolled` + `uq_run_user` |
| G2 | Enroll into a `completed` / `cancelled` run refused | `run_closed` |
| G3 | Capacity full → `run_full`; `$allow_overfill` bypasses | |
| G4 | Status transitions (`active` → `withdrawn` → …) validated | |
| G5 | Delete enrollment cascades its attendance | |
| G6 | Deleting the student's WP user deletes enrollments + their attendance | `on_user_deleted` |

### H. Attendance

| # | Test | Expected |
|---|---|---|
| H1 | Record present/absent/late/excused; invalid status refused | `invalid_attendance_status` |
| H2 | Record for an enrollment whose run ≠ session's run refused | `run_mismatch` (IDOR guard) |
| H3 | Second `record()` for same `(session, enrollment)` updates, does not duplicate | upsert + `uq_session_enrollment` |
| H4 | `recorded_by` set to the acting user; deleting that user nulls it, keeps the row | `on_user_deleted` |
| H5 | Bulk record reports per-row errors without aborting the batch | |

### I. Authorization (negative)

| # | Test | Expected |
|---|---|---|
| A1 | `reception` / `teacher` / `student` cannot see the "عملیات آموزشی" menu | cap `hedayati_manage_course_runs` |
| A2 | Direct POST to `admin-post.php?action=hedayati_run_save` without the nonce → 403 | |
| A3 | Direct POST with a valid nonce but insufficient capability → 403 | server-side cap check |
| A4 | A non-manager staffed on run X cannot act on run Y (scope) | `require_run_scope()` |
| A5 | `hedayati_manager` (no `hedayati_record_attendance`) sees attendance read-only, cannot POST it | matrix respected |

### K. Shamsi/Jalali display (Phase 2B admin)

| # | Test | Expected |
|---|---|---|
| K1 | Every date in the «عملیات آموزشی» screens shows Gregorian + Shamsi in parentheses | e.g. `2026-03-21 (۱۴۰۴/۱۲/۳۰)` |
| K2 | A session datetime shows the wall-clock **time unchanged** next to the Shamsi date | Q9 — time is not timezone-converted |
| K3 | Stored values are still Gregorian ISO / ASCII (check the DB, not the screen) | no storage change |
| K4 | Nowruz boundary dates convert correctly (`2026-03-20` → `۱۴۰۴/۱۲/۲۹`, `2026-03-21` → `۱۴۰۵/۰۱/۰۱`) | 33-year-cycle algorithm |
| K5 | An empty / malformed stored date renders as `—` / plain Gregorian, never a PHP warning | graceful fallback |

### J. Audit log (metadata-only, append-only)

| # | Test | Expected |
|---|---|---|
| J1 | Each successful mutation (run/session/staff/enrollment/attendance create·update·delete) writes exactly one `hedayati_audit_log` row with the expected `action` | wired in the service success path |
| J2 | A **failed** mutation (bad input, duplicate, capacity full, wrong nonce/cap) writes **no** audit row | audit call is after the error returns |
| J3 | Deleting a course / run / user cascades the domain rows but leaves every prior audit row intact (incl. `enrollment.created` / `attendance.recorded` for the deleted objects) | table excluded from cascades |
| J4 | `actor_id` = the acting wp-admin user; a WP-CLI mutation records `actor_id = 0` | `get_current_user_id()` |
| J5 | No row ever contains an IP, user-agent, name, phone, national ID or document reference in any column, `note` included | schema + `note` discipline |
| J6 | «گزارش رویدادها» is visible to `hedayati_manager` / `administrator` only; direct URL as `reception` / `teacher` → 403 | `hedayati_view_audit_logs` |
| J7 | The viewer is read-only — no way to edit/delete an entry from the UI or via `admin-post.php` | append-only at the API |
| J8 | Filters (`object_type`, `action`, `object_id`) + pagination behave; an out-of-vocabulary `flt_action` is ignored, not passed to SQL | validated against the vocabularies |
| J9 | Re-running migration 2.2.0 (reset marker on a backup) is idempotent | `dbDelta` no-op |

---

## Note

Phase 2A non-destructive acceptance largely passed, with phone cleanup discrepancy HD-002 open. Category 4 remains deferred/not required for the normal gate. Continue broader Phase 2B functional acceptance on synthetic data; local success does not authorize staging changes or deployment.
