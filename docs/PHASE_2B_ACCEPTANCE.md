# Phase 2B — Academic Operations Acceptance (staging matrix)

**Status: NOT STARTED — repository implementation only.**

Phase 2B (Teacher CPT, Course Runs, staff assignment, sessions, enrollments,
attendance, **metadata-only audit log**) plus the Phase 2C address-profile slice
were implemented on branch `feature/phase-2b-academic-operations`. As with Phase
2A, **runtime/behavioural acceptance runs on staging (`mystik.ir`)** and is a
**pre-merge / pre-deployment gate**. Nothing in this file has been executed —
every row below is **NOT RUN**.

Constraints (unchanged from `docs/PHASE_2A_ACCEPTANCE.md`): operator drives every
authenticated step; no destructive DB changes without per-test approval; no
production contact; take a fresh full backup before any state-changing test.

---

## What was verified in the repository (not on staging)

| Check | Tool | Result |
|---|---|---|
| Node — Phase 2B static + logic + behavioural port | `node …/verify-phase2b.js` | **171 passed, 0 failed** (2026-09-03) |
| Node — audit log | `node …/verify-audit-log.js` | **98 passed, 0 failed** (2026-09-03) |
| Node — Shamsi/Jalali (incl. 15k-day round-trip fuzz) | `node …/verify-jalali.js` | **36 passed, 0 failed** (2026-09-03) |
| Node — Phase 2C address slice | `node …/verify-phase2c.js` | **25 passed, 0 failed** |
| Node — Phase 2A regression | `node …/verify-phase2a.js` | **74 passed, 0 failed** — no regression |
| PHP — `test-phase2b.php` / `test-audit-log.php` / `test-phase2a.php` | `php …` | **NOT RUN** — PHP unavailable in the dev environment (2A count assertion updated 21 → 22) |
| `php -l` on changed files | — | **NOT RUN** — PHP unavailable |

The Node suite covers: business-state allowlists, date/datetime/integer parsing,
Persian-digit normalization, migration 2.1.0 wiring (DDL, UNIQUE keys, dynamic
prefix, no `wp_` literal, no ENUM), roles schema 2.1.0 (`hedayati_manage_teachers`,
count = 22), plugin bootstrap wiring, and security-shape checks (nonces,
capability checks, `$wpdb->prepare`, per-run scope).

It does **not** and cannot prove: real INSERT/UPDATE/DELETE behaviour, UNIQUE
constraint enforcement, cascade deletes, capacity enforcement, per-run scope
against a live user, or the deletion-cleanup hooks.

---

## Staging matrix — ALL NOT RUN

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
| T1 | `teacher` post type visible to manager/admin only; not to reception/teacher/student/TA | cap map to `hedayati_manage_teachers` |
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

Phase 2A behavioural acceptance (Categories 2–4 of `docs/PHASE_2A_ACCEPTANCE.md`)
remains **open** and is still the first pre-deployment gate. Phase 2B staging
acceptance should run **after** Phase 2A's, on the same disposable-account
discipline, ideally in the same staging window.
