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

## Phase 2C — what is blocked and why

Only the **mailing-address** slice of the student profile was built
(`class-student-profile.php` — `hedayati_address` / `hedayati_city` /
`hedayati_postal_code` usermeta, per `docs/ROADMAP.md` P1.2). Everything below is a
deliberate non-implementation.

## Q10 — National ID storage (BLOCKS implementation)

**Needs an institute + infrastructure decision.** Per `docs/DECISIONS.md` D15 a
reversible national-ID field requires a dedicated `HEDAYATI_DATA_ENCRYPTION_KEY`
(and a separate keyed-HMAC secret for duplicate detection) placed in
`wp-config.php` / server config — **outside Git** — with key versioning. None of
that exists and it cannot be created from this repo.
**Do not** add a national-ID field, encrypted or not, until: (a) the key + HMAC
secret are provisioned on staging and production, (b) the key-versioning scheme is
agreed, (c) the institute confirms national ID is actually required and what it
unlocks. **This is a stop-and-ask item (sensitive data + encryption guarantees).**

## Q11 — Verification workflow semantics (BLOCKS implementation)

The conceptual states (`unverified` / `pending` / `verified` / `rejected`) are
approved, but three things are undecided and each changes the data model:
1. **Reset rules** — does editing the profile / phone / documents drop a
   `verified` record back to `pending`? Which field changes trigger it?
2. **Benefit linkage** — `docs/REQUIREMENTS.md` 8.6: "No approved policy that
   verification unlocks certificates/exams/benefits." Until there is one, a
   verification system has nothing to gate and its urgency is unclear.
3. **Reviewer workflow** — who moves `pending → verified/rejected`, what evidence
   is required, is a rejection reason mandatory/visible to the student?
**Safe interim:** none built. `reception` already has `hedayati_initiate_verification`
and `hedayati_manager` has `hedayati_verify_students` (defined, unused). When
unblocked, store the record as usermeta `{status, reviewed_by, reviewed_at,
reason}` and add the reset rule as an explicit, documented policy — not a guess.

## Q12 — Private document storage (BLOCKS implementation)

Per `docs/DECISIONS.md` D14 + `docs/SECURITY.md`: bytes outside `public_html`,
application-controlled streaming after capability + ownership checks, abstract
`storage_backend` + `storage_key`, MIME allowlist, generated names,
archive/deleted lifecycle. Undecided: the actual storage location on ParsPack
(can PHP write outside the web root there?), the MIME/size allowlist, the
mandatory-document list, the retention period, and the ~48-hour offsite-transfer
protocol (acknowledge/retry/delete/restore). **Do not store any real student
document this session.** A schema-only foundation is possible later but adds risk
with no user today; defer until the storage location and retention are confirmed.

## Q13 — Audit log retention (BLOCKS the IP/UA part)

`docs/DECISIONS.md` D16 approves an application-level append-only audit log
(metadata only). The blocker is narrow: **retention/privacy policy for IP and
user-agent data is required and undecided.** An audit log that records
*only* `{actor_id, action, object_type, object_id, note, created_at}` — no IP, no
UA — has no retention landmine and would make Phase 2B operations auditable. It
was **not** built this session to keep the branch focused on 2B + the approved
profile slice, but it is a clean, low-risk next step (see the next-session note).
