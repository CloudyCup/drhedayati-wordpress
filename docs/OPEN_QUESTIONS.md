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
**Blocks:** nothing. Note for Phase 2C: audit-log entries, once they exist, must
**not** be part of this cascade (handoff / D16 — academic history is preserved).

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
