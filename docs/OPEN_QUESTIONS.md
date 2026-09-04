# OPEN_QUESTIONS.md

Unresolved questions encountered during implementation. Each entry records the
question, why it matters, what it affects, safe options if known, and whether it
**blocks development** or only **blocks deployment / a later phase**.

Rule of thumb (see `AGENTS.md` / the autonomous-session brief): a question that
touches security, sensitive student data, authentication semantics, irreversible
schema design, destructive migrations, privilege boundaries, or infrastructure is
a **stop-and-ask**. Everything else is recorded here and worked around.

---

## Q1 — Does a Course Run need a human-readable label, and who sets it?

**Status:** resolved by implementation (safe, reversible).
**Context:** `docs/DATA_MODEL.md` describes a Course Run's operational fields
(teacher, dates, schedule, tuition, capacity, statuses) but not a display label.
Staff need *some* way to tell two runs of the same course apart in the admin list.
**Decision taken:** added a nullable `label` varchar (e.g. «پاییز ۱۴۰۴ — تبریز»),
free-text, staff-set, no semantics. Empty is allowed and the UI falls back to the
course title. Purely cosmetic — no business logic depends on it.
**Blocks:** nothing. Revisit only if the institute wants a structured term/cohort
naming scheme.

## Q2 — Must a Course Run instructor also have a WordPress user account?

**Status:** resolved by implementation, following the handoff.
**Context:** `docs/DECISIONS.md` D11 + handoff §6: "instructor assignments require a
teacher profile; TA assignments require a WP staff user but not a public Teacher
CPT." It does **not** say an instructor must have an account.
**Decision taken:** `hedayati_run_staff` rows for `primary_instructor` /
`additional_instructor` reference a **Teacher profile** (`teacher_id`) and leave
`user_id` NULL. `assistant` rows reference a **WP user** (`user_id`) and leave
`teacher_id` NULL. A teacher who also logs in is linked through
`_hedayati_teacher_user_id` on the Teacher profile, and the scope helper
(`Hedayati_Run_Staff_Service::user_is_staff_on_run()`) resolves either path.
**Blocks:** nothing.

## Q3 — Should enrollment be blocked when a run is at capacity?

**Status:** resolved by implementation (overridable).
**Context:** capacity is nullable ("unknown ≠ 20"). When it *is* known, does
reception get hard-blocked at the limit?
**Decision taken:** `Hedayati_Enrollment_Service::enroll()` blocks with a
`run_full` error when `capacity` is known and active enrollments ≥ capacity, but
accepts an explicit `$allow_overfill = true` for a deliberate staff override. The
current admin UI does not expose the override toggle yet.
**Blocks:** nothing. If the institute wants "waitlist" semantics that is a new
feature, not a change here.

## Q4 — Is there a distinct "manage teachers" capability, or is it folded into course management?

**Status:** resolved by implementation → see `docs/DECISIONS.md` D28.
**Context:** the Phase 2A capability set (21) has `hedayati_manage_courses`,
`hedayati_manage_course_runs`, `hedayati_assign_staff` but nothing teacher-specific.
**Decision taken:** added `hedayati_manage_teachers` (22nd managed capability),
granted to `hedayati_manager` + `administrator`, roles schema bumped to `2.1.0`.
Rationale in D28.
**Blocks:** deployment only — staging must re-run the `admin_init` role sync and
re-verify the capability matrix (Phase 2A T3.5 was already NEEDS-REVIEW).

## Q5 — Attendance recording UI for teachers (not just managers/admins)

**Status:** deferred to Phase 2D (interfaces), not a Phase 2B gap.
**Context:** the Phase 2B admin screen "عملیات آموزشی" is gated on
`hedayati_manage_course_runs`, which teachers do not hold, so a teacher cannot
currently reach the attendance grid even though the *service* and the per-run
*scope helper* are built and enforce `hedayati_record_attendance` + assignment
scope. `docs/ROADMAP.md` puts the scoped teacher/TA portal in Phase 2D.
**Blocks:** nothing in Phase 2B. Phase 2D builds the teacher-facing screen on the
existing `Hedayati_Attendance_Service` + `Hedayati_Run_Staff_Service::user_is_staff_on_run()`.

## Q6 — What happens to operational records when a catalog `course` is deleted?

**Status:** resolved by implementation.
**Context:** courses are catalog identity and rarely deleted, but nothing stopped
an orphaned run.
**Decision taken:** permanently deleting a `course` cascades: its runs are deleted,
and each run cascade-deletes its sessions, enrollments, attendance and staff rows
(`Hedayati_Course_Run_Service::on_course_deleted()` on `before_delete_post`).
Trashing a course does **not** cascade (recoverable). This mirrors the
`deleted_user` cleanup already established in Phase 2A.
**Blocks:** nothing. The audit log (D33) records `course.deleted` + one
`course_run.deleted` per cohort and is **excluded** from the cascade — history is
preserved (handoff / D16 / D31).

## Q7 — Enrollment eligibility: must the enrolled user hold the `student` role?

**Status:** partial — implemented leniently, flagged for review.
**Context:** reception "creates enrollments". Should the target user be required
to have the `student` role, or can any account be enrolled?
**Decision taken:** the service requires only that the user exists. The admin
"enroll" dropdown is filtered to `role__in => ['student']` as a guardrail, but the
service does not enforce role — so an importer or a future self-service flow is not
boxed in.
**Open:** if the institute wants a hard rule ("only students can be enrolled"),
add it in `Hedayati_Enrollment_Service::enroll()`. Non-blocking.

## Q8 — Which Course Run does a public course page display?

**Status:** OPEN — blocks the Phase 2B theme-fallback wiring, not the backend.
**Context:** `docs/DECISIONS.md` D12 says the `_course_teacher` / `_course_next_start_date` /
`_course_price` / `_course_registration_state` meta become "backward-compatible fallbacks" once
Course Runs exist. The backend is built and the run layer never writes that meta — but the public
`single-course.php` still reads **only** the meta. To make the run the display source, the theme
needs to pick **one** run per course to surface, and that choice is a product decision:
- the next run whose `start_date` is in the future?
- the run with `registration_status = 'open'` (and if several, the soonest)?
- the most recently created non-draft run?
- show a *list* of upcoming runs instead of folding into the existing single-value fields?
And: what to show when a course has **no** runs (keep the current meta), and whether tuition should
switch from the free-text `_course_price` string to a formatted `tuition_rial` (rial→toman
formatting rules also unconfirmed).
**Why it matters:** changes public-site rendering; must not regress the current course page.
**Safe options:** (a) leave the theme untouched until decided (current state — no regression);
(b) add a `Hedayati_Course_Run_Service::get_display_run( $course_id )` with a documented, filterable
default (`hedayati_course_display_run`) once the institute picks a rule.
**Blocks:** the "theme reads run data" item only. The Phase 2B backend + admin do not depend on it.
Recommend deferring to Phase 2D (interfaces) where the public course page is revisited anyway.

## Q9 — Session datetime timezone model

**Status:** resolved by implementation (single-country institute), flagged for staging sign-off.
**Context:** `hedayati_sessions.starts_at` / `ends_at` are stored exactly as the operator enters
them (`Y-m-d H:i:s`, ASCII digits) — site-local wall-clock, because a session is a scheduled class
time ("جلسه ساعت ۹ صبح"), not a timezone-bearing instant. System timestamps elsewhere
(`created_at`, `recorded_at`, …) are UTC, consistent with Phase 2A.
**Why it matters:** if the institute ever needs cross-timezone scheduling (online cohorts in
different regions) or exports to a calendar system, wall-clock storage would need a companion
timezone or a migration to UTC instants.
**Assessment:** for a Tabriz/Tehran in-person institute this is the correct, simplest model.
**Blocks:** nothing. Confirm the expectation with the institute during Phase 2B staging acceptance;
revisit only if online/multi-timezone delivery becomes a requirement.

---

## Phase 2C — resolved (owner decisions, 2026-09-05) — see D36–D40

Q10–Q13 below are now **resolved by explicit owner decision** and implemented on
`feature/phase-2c-student-portal`. This section is kept as a historical record of what was
blocked and why; `docs/DECISIONS.md` D36–D40 record the actual decisions, and
`docs/DATA_MODEL.md` / `docs/SECURITY.md` document the resulting implementation.

## Q10 — National ID storage — RESOLVED (D36)

**Decided:** national ID is required for verified student profiles, encrypted at rest
(AES-256-GCM) with a dedicated `HEDAYATI_DATA_ENCRYPTION_KEY` (strict base64/32-byte format,
outside Git), plus a separate keyed-HMAC (`HEDAYATI_DATA_HMAC_KEY`) fingerprint for DB-level
duplicate detection — same `UNIQUE`-constraint pattern as `hedayati_user_phones` (D7). Both keys
fail closed if missing or malformed — no plaintext fallback. Only staff holding
`hedayati_verify_students` may decrypt a stored value, through one narrow, audited, POST-only
reveal action — never the student themselves, never any other role. See D36.

## Q11 — Verification workflow semantics — RESOLVED (D37)

**Decided:** `unverified` / `pending` / `verified` / `rejected` with an **enforced** transition
table (not free value-to-value movement): `unverified|rejected → pending` (initiate),
`pending → verified|rejected` (approve/reject), and `verified` exits only through
`reset_for_identity_change()` — never a direct API call. Reset triggers on a legal
first/last-name change; phone, address, and email changes do **not** reset verification (phone
verification stays independent, per the owner's explicit instruction not to conflate the two).
Rejection is reversible via a later `initiate()`. No manager/administrator override of the state
machine exists — that would be a distinct, future, explicit decision. See D37.

## Q12 — Private document storage — RESOLVED (D38)

**Decided:** bytes stored via `Hedayati_Document_Storage`, environment-gated: a configured
`HEDAYATI_PRIVATE_UPLOADS_DIR` outside the web root is **required** on staging/production (fails
closed without it); the protected `wp-content/uploads/hedayati-private/` fallback is
**local/Docker-CI only**. Real content-sniffing (`finfo` + PDF magic header + structural image
validation, not extension/declared-MIME trust) against a PDF/JPEG/PNG allowlist. Canonical,
containment-checked storage-key resolution on every read/delete (rejects traversal, absolute
keys, symlink escape). Manual archive confirmation + a computed 7-day purge-eligibility window,
purged only by an explicit staff action — never a cron job. See D38.

## Q13 — Audit log IP/UA — RESOLVED (D39, permanently)

**Decided:** the metadata-only, append-only log stays exactly as built in Phase 2B — no IP
address, no user-agent, ever. This is not a deferred decision awaiting a retention policy; the
owner explicitly chose not to collect this data. See D39.
