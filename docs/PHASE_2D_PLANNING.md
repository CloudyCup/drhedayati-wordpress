# Phase 2D Planning — Account Shell & Student Portal

**Status: planning only. No implementation has started.** This document reshapes the previously
oversized single "Phase 2D — interfaces" bullet (`docs/ROADMAP.md`, historical) into three smaller,
sequential phases, defines the minimum planning gate before writing any front-end account code, and
records the business decisions the owner must make first. Written against `main` @ `32640e4`
(Phase 2B + Phase 2C merged; plugin `1.6.0`, DB `2.3.0`, roles `2.2.0`, 23 capabilities).

---

## 0. Reshaped phase sequence

The old single "Phase 2D — interfaces" bullet tried to cover branded login, a student portal, a
teacher/TA portal, a reception panel, manager dashboards, and the audit-log viewer all at once.
That is too large a unit to plan, build, or accept coherently. It is split into three:

| Phase | Scope | Depends on |
|---|---|---|
| **2D** | Shared Persian RTL account/panel shell (nav, auth chrome, theme toggle reuse) + the **student** portal (self-service profile, phone, verification status, enrollments, private documents — never plaintext national ID) | Phase 2C backend (merged) |
| **2E** | Teacher, TA, reception, and manager-facing panels on the same shell + the authorization-consistency fixes this reconciliation surfaced (Course's native post capabilities, Settings' `manage_options` requirement, the student-admin screen's direct-`user_id` scope guard) | Phase 2D's shell |
| **2F** | Public-site completion (Course Run display rule, About/Contact/Consultation, teacher directory, Shamsi/font/logo) + integrated staging readiness (Phase 2A+2B+2C+2D+2E all smoke-tested together on `mystik.ir`) | Phases 2D + 2E |

This document plans **Phase 2D only** in implementation-ready detail. Phases 2E/2F are scoped here
at a level sufficient to sequence and estimate, not to implement — each gets its own planning pass
before its own implementation prompt, the same way this document precedes Phase 2D's.

**Phase 2D's one blocking decision (account-creation model) is now resolved — §4a/§13.** No owner
question remains outstanding for Phase 2D specifically. Implementation may proceed once the
documentation correction itself is committed and static tests are reconfirmed green (§14).

---

## 1. Shared account/panel shell

A single Persian RTL shell, visually continuous with the existing "Navigator" homepage direction
(`docs/DECISIONS.md` D17), used by every authenticated screen in Phases 2D and 2E:

- **Chrome:** a slimmer variant of `header.php`'s sticky header (logo, minimal nav, theme toggle,
  user menu with display name + role + logout) — reuse `--hd-*` custom properties and
  `[data-theme="dark"]` from `theme/hedayati/assets/css/main.css` rather than inventing a new
  palette. No new JS framework: the existing vanilla-JS convention (`assets/js/main.js`'s IIFE
  style, no jQuery, no bundler) extends to the shell's own interactions (nav toggle, per-panel tab
  switching).
- **Layout primitive:** a two-region shell — a right-hand (RTL-primary) collapsible sidebar nav
  (role-specific screen list, §2) and a main content region — collapsing to a top tab bar or
  off-canvas drawer under the theme's existing mobile breakpoints (1024/900/768/420, `main.css`).
- **Templates, not a block theme:** stays consistent with the classic PHP template-hierarchy
  approach (`docs/ARCHITECTURE.md`) — a new template (e.g. `page-templates/account.php` or a
  dedicated rewrite-routed set of templates under a `/account/` prefix) rather than a client-side
  router. Server-rendered per screen, matching the rest of the theme.
- **Data boundary:** the shell reads plugin data only through stable public APIs, matching D4 — no
  direct `$wpdb` calls from the theme. Phase 2D likely needs a small set of new read-oriented
  accessor methods on the existing `Hedayati_*_Service` classes (e.g. "get this user's enrollments
  formatted for display") rather than reusing the wp-admin-oriented methods as-is.
- **No new JS framework, no REST-as-SPA-backend architecture** — this must not reintroduce the
  superseded React/Vite direction (D5). Forms post to `admin-post.php`-equivalent front-end
  handlers (a themed `template_redirect`-based controller, or `admin-post.php` with
  `is_user_logged_in()` front-end actions — a concrete choice for the Phase 2D implementation
  prompt, not decided here) using the same nonce+capability pattern already proven in
  `class-academic-admin.php` / `class-student-admin.php`.

---

## 2. Route and screen maps per role

Illustrative slugs — the exact rewrite structure is a Phase 2D implementation detail, not fixed
here. "Existing" means the screen already exists as a **staff-only wp-admin** screen and Phase 2D/E
is adding a front-end-facing equivalent or (for student) a first front-end at all.

| Role | Screens (Phase 2D unless marked 2E) |
|---|---|
| **Student** | `/account/` (dashboard), `/account/profile/` (address/city/postal + phone display, national-ID **presence only**), `/account/verification/` (status: unverified/pending/verified/rejected, read-only), `/account/enrollments/` (runs + upcoming sessions), `/account/documents/` (list + self-upload) |
| **Teacher** *(2E)* | `/panel/runs/` (assigned runs), `/panel/runs/{id}/roster/`, `/panel/runs/{id}/sessions/`, `/panel/runs/{id}/attendance/{session}/` |
| **Teacher Assistant** *(2E)* | `/panel/runs/` (assigned, read-only), `/panel/runs/{id}/roster/` (read-only) — no attendance/session screens (D11) |
| **Reception** *(2E)* | `/panel/students/` (lookup — front-end equivalent of the existing wp-admin screen), `/panel/students/{id}/` (basic profile view, enrollment intake, initiate verification, document upload) |
| **Manager** *(2E)* | Front-end equivalents of «عملیات آموزشی» and «دانشجویان و احراز هویت» are **optional** for 2E — the existing wp-admin screens already serve `hedayati_manager`/`administrator` adequately; 2E's manager-facing work is primarily the **capability-consistency fixes** (§8), not new screens, unless the owner specifically wants a front-end dashboard |
| **Administrator** | No new screens — retains full wp-admin access; may use any front-end screen through the same capability checks |

---

## 3. Screen-by-screen capability + ownership/assignment matrix

| Screen | Capability | Ownership/assignment check |
|---|---|---|
| `/account/profile/` (view/edit own) | `hedayati_edit_own_profile` | `$user_id === get_current_user_id()` — already the pattern `Hedayati_Student_Profile` uses |
| `/account/verification/` (view own status) | `hedayati_view_own_portal` | same — read-only, no write capability needed |
| `/account/enrollments/` (view own) | `hedayati_view_own_enrollments` | same |
| `/account/documents/` (list/upload own) | `hedayati_upload_own_documents` (upload) + `hedayati_view_own_portal` or a to-be-confirmed dedicated own-view path (download follows the `hedayati_view_own_portal && $user_id === get_current_user_id()` shape documented — but not enforced — in `class-document-service.php`'s docblock; the Phase 2D controller must implement this check itself, see §5) | `$user_id === get_current_user_id()` — enforced by the new controller, not the service |
| Reception `/panel/students/` *(2E)* | `hedayati_lookup_students` | none (reception's mandate is unscoped by design — any student) |
| Reception intake/enroll/upload *(2E)* | `hedayati_create_enrollments` / `hedayati_initiate_verification` / `hedayati_upload_student_documents` | target must hold `student` role (existing `require_student_scope()` pattern — see §9 for its limit) |
| Teacher `/panel/runs/` *(2E)* | `hedayati_view_assigned_runs` | `Hedayati_Run_Staff_Service::user_is_staff_on_run()` — **reuse this exact existing helper**, do not reinvent |
| Teacher attendance *(2E)* | `hedayati_record_attendance` | same helper, plus the existing same-run guard between session/enrollment |
| TA `/panel/runs/` (read-only) *(2E)* | `hedayati_view_assigned_runs` | same helper; TA never reaches a write action (no `hedayati_record_attendance`) |
| Manager screens *(2E, if built)* | existing `hedayati_manage_*` caps | none (manager is the unscoped operational role by design, D10) |

**Principle carried forward from Phase 2B/2C:** capability alone is never sufficient for a
screen touching a specific student/run/document — every screen above pairs a capability with an
explicit ownership or assignment check, reusing existing helpers (`user_is_staff_on_run()`,
`$user_id === get_current_user_id()`) rather than inventing new scope logic per screen.

---

## 4. Branded login/password-reset + the account model (RESOLVED)

**Branded login/password-reset UI** (`docs/REQUIREMENTS.md` 6.11) is in scope for Phase 2D: a
themed wrapper around `wp-login.php` (custom `login_header`/`login_footer` hooks + a stylesheet,
the standard low-risk WordPress approach) or a fully custom front-end form posting to
`wp_signon()`. Either preserves D6 (WordPress remains the only identity authority) and D20 (no
Google/social login) — the choice is a Phase 2D implementation detail, not a business decision.

### 4a. Account model — RESOLVED: reception-created only

**Decided:** student accounts are **reception-created** for this staging candidate, consistent
with how enrollments are already reception-created in Phase 2B and how national-ID/document
intake is already staff-only in Phase 2C. Phase 2D's branded login screen includes login and
WordPress password reset (`wp_lostpassword_url()`/`retrieve_password()` flow, themed) — **it does
not include a "ثبت‌نام" (register) link, a public registration form, or any public
account-creation endpoint.** Public self-registration remains a **possible later, separately
approved** feature (its own spam/abuse-control and unverified-account-scope questions are
deliberately not designed now, since it isn't being built) — do not add a registration link, a
disabled/hidden registration form, or any scaffolding toward it in Phase 2D.

---

## 5. Student self-service — detailed spec

- **Profile:** `Hedayati_Student_Profile::get()`/save already usable as-is for address/city/postal;
  Phase 2D's `/account/profile/` screen is a front-end-styled wrapper over the same registered
  meta + sanitizers, gated `hedayati_edit_own_profile`, matching the existing self-edit contract.
- **Authoritative phone update:** phone changes must go through `Hedayati_User_Phone_Service`
  (never usermeta) so the DB-level `UNIQUE` constraint and the "changing the number resets
  verification" rule (D8) keep applying — do not let a new front-end form write phone data any
  other way.
- **Verification status:** **read-only** in Phase 2D, and **narrower than `get_status()`'s full
  return value.** `Hedayati_Verification_Service::get_status()` returns `status`, `reviewer_id`,
  `reviewed_at`, `note`, and `has_national_id` — the portal controller must project this down to
  **`status` and national-ID presence only** before it ever reaches a template. `reviewer_id` and
  `reviewed_at` identify and timestamp a staff action and must not go to the student. **`note` is
  staff-internal** (it is the reviewer's working note, not written with a student audience in
  mind) and must not be shown automatically — treat it as staff-only unless the owner later
  approves a **separate, deliberately-written student-safe explanation field** for a `rejected`
  outcome (§13). The student never sees a "verify me" or "reveal" action — those stay staff-only
  (D36/D37, unchanged).
- **Enrollments/runs/sessions:** read-only listing via `Hedayati_Enrollment_Service::list_for_user()`
  (already exists) joined with `Hedayati_Course_Run_Service::get()`/`Hedayati_Session_Service` reads
  — no new write path needed for Phase 2D (self-enrollment is not in scope; reception creates
  enrollments, per Phase 2B's existing model).
- **Private documents:** `Hedayati_Document_Service` is **capability-agnostic by design** — its
  docblock *describes* an authorization contract (`hedayati_view_own_portal` +
  `$user_id === get_current_user_id()` for self-access) for a future caller to implement; **no
  such caller exists yet, and the service itself performs no authorization check.** Phase 2D must
  build the actual front-end portal controller that calls `list_for_user()` / `download()` /
  `upload()` **only after** explicitly checking the capability **and** verifying the acting user
  owns the target document/user_id — do not treat the documented contract as already-enforced
  behavior, and do not call these methods from a new controller without writing that check first.
- **National ID: never a plaintext self-view, no exceptions.** The student's own profile/portal
  shows **presence only** (`get_national_id_masked()` — `'set'`/`'not_set'`), exactly like the
  existing staff-only admin screen does for non-privileged staff. `get_national_id_decrypted()`'s
  service-level `hedayati_verify_students` check (D36) already makes a self-view technically
  impossible even if a Phase 2D screen mistakenly tried to call it — but the screen must not
  attempt to call it, must not call `get_status()` and forward its raw array to a template, and
  must not expose `note`/`reviewer_id`/`reviewed_at` by any path; masked/status-only is the
  correct, intentional design, not a workaround.

---

## 6. Teacher / TA screens (Phase 2E, spec sketch)

- Teacher: assigned runs (`Hedayati_Run_Staff_Service::run_ids_for_user()`), rosters
  (`Hedayati_Enrollment_Service::list_for_run()`), sessions (`Hedayati_Session_Service`), attendance
  (`Hedayati_Attendance_Service::record()`/`record_bulk()`) — all through the existing service layer,
  scoped by `user_is_staff_on_run()`, exactly mirroring `class-academic-admin.php`'s existing
  pattern but front-end-styled and capability-gated to the teacher role instead of
  `hedayati_manage_course_runs`.
- TA: same runs/roster read paths, explicitly no attendance/session write screens (D11) — TA lacks
  `hedayati_record_attendance` and `hedayati_manage_assigned_sessions` today; a Phase 2E screen must
  not grant a capability the role doesn't already have to "make the UI work."

---

## 7. Reception screens (Phase 2E, spec sketch)

Front-end equivalent of the existing wp-admin student-lookup/intake flow, **without** ever
introducing WordPress core `edit_user`/`edit_users` for reception (D40's correction stays in
force): lookup (`hedayati_lookup_students`), basic profile view
(`hedayati_view_student_profiles_basic`), enrollment creation (`hedayati_create_enrollments`),
initiate verification (`hedayati_initiate_verification`), national-ID/document intake
(`hedayati_upload_student_documents` + the student-role scope check, §9). This is a re-skin of
`Hedayati_Student_Admin`'s existing logic for a front-end audience, not new authorization design.

---

## 8. Manager operations + capability-consistency fixes (Phase 2E)

This reconciliation pass found two real, pre-existing inconsistencies (confirmed by reading
`class-post-types.php` and `class-settings.php`, not assumed):

1. **Course management uses native WordPress post capabilities** (`capability_type => 'post'` in
   `class-post-types.php`) instead of a dedicated `hedayati_manage_courses`-style gate, even though
   `hedayati_manage_courses` is defined and granted to `hedayati_manager` — **it is never checked
   anywhere in the plugin.** Confirmed definitively, not hedged: `Hedayati_Roles::get_roles_definition()`
   grants `hedayati_manager` only `read` plus its `hedayati_*` capabilities — **no native
   `edit_posts`, `edit_others_posts`, `publish_posts`, or any other WordPress core post
   capability.** A `capability_type => 'post'` CPT maps to exactly those native primitives, none of
   which `hedayati_manager` holds. **`hedayati_manager` cannot create, edit, or delete a `course`
   post at all under the current mapping** — this is not a partial/edge-case gap to verify further,
   it is a complete inability, confirmed by reading `Hedayati_Roles::get_roles_definition()`
   directly. **Fix for Phase 2E (the only recommended direction — not a retirement option):** give
   the `course` CPT its own `capabilities` map backed by `hedayati_manage_courses`, following the
   already-tested Teacher CPT pattern (`class-teacher.php`, proven and regression-covered by
   HD-006's fix) — `map_meta_cap => true` with all of `edit_post`/`read_post`/`delete_post` **and**
   `edit_published_posts`/`edit_private_posts`/`delete_published_posts`/`delete_private_posts`/
   `edit_others_posts`/`publish_posts`/`read_private_posts`/`delete_others_posts` pointed at
   `hedayati_manage_courses`, exactly as HD-006 required for the Teacher CPT to actually work.
   `hedayati_manage_courses` stays a real, enforced, dedicated capability — it is not unused
   scaffolding to remove.
2. **Settings requires core `manage_options`** (`class-settings.php`'s `CAPABILITY` constant),
   which `hedayati_manager` deliberately does **not** hold (D10 — operational role, no WordPress
   technical administration) — meaning `hedayati_manager` cannot use Settings → Hedayati today even
   though `hedayati_manage_settings` exists and is granted to that exact role. **Fix for Phase 2E:**
   change `class-settings.php`'s capability check to `hedayati_manage_settings`, consistent with
   D10's intent that `hedayati_manager` runs institute operations without needing
   `manage_options`.

Both fixes are small, mechanical, and low-risk (capability-map changes only, no schema/data
change) — but they are real product defects this reconciliation surfaced, not hypothetical, and
belong in Phase 2E's implementation scope rather than Phase 2D's (they're independent of the
account shell).

---

## 9. The student-admin direct-`user_id` scope guard

`Hedayati_Student_Admin::require_student_scope()` (`class-student-admin.php`) checks only that the
posted `$user_id` currently holds the `student` role — it does not verify any relationship between
the acting staff member and that specific student. **This is not a live vulnerability today**:
`reception` and `hedayati_manager` have an intentionally **unscoped** mandate (any student is a
legitimate target for intake/enrollment/verification-initiation), so trusting a posted `user_id`
against "is this a student" is a correct, sufficient guard for their current use.

**It becomes a real gap the moment a less-privileged, scoped actor reuses this pattern** — which
Phase 2E's teacher/TA panels and any Phase 2D student-self-service screen will do. Before either
lands:

- **Student self-service (Phase 2D):** any screen touching "my own" data must check
  `$user_id === get_current_user_id()`, never re-use `require_student_scope()` alone — a student
  must not be able to post an arbitrary `user_id` and act on another student's record just because
  the target also holds the `student` role.
- **Teacher/TA (Phase 2E):** any screen must check `Hedayati_Run_Staff_Service::user_is_staff_on_run()`
  or an equivalent enrollment-membership check, not "is this a student" — a teacher must not be
  able to act on a student who isn't in one of their own runs.

**Recommendation:** extend `require_student_scope()` (or introduce a small family of scope
helpers alongside it, mirroring `user_is_staff_on_run()`'s existing shape) as part of Phase 2D/2E's
implementation, not as a standalone hotfix now — the fix only makes sense once there's a concrete
caller with a narrower mandate than reception/manager to test it against.

---

## 10. Public completion dependencies (Phase 2F, listed for sequencing only)

- **Course Run display rule** (`docs/OPEN_QUESTIONS.md` Q8, still open) — which run a public course
  page shows (next upcoming? soonest open registration? a list?) is an unresolved product decision,
  not an implementation gap; do not guess it when Phase 2F starts.
- **About / Contact / Consultation pages** — templates + (for Consultation) a submission handler;
  UX for the handler is still ❓ per `docs/REQUIREMENTS.md` 2.12.
- **Teacher directory** — flipping the `teacher` CPT `public`/`show_in_rest` back on (D30/D34) needs
  a deliberate public-read design first (what's shown, what's private), not just a flag flip.
- **Shamsi dates** — the helper and admin-side display/input already exist (`Hedayati_Jalali`);
  Phase 2F's remaining work is public-site rendering and any date fields not yet covered.
- **Font/logo** — self-hosted Vazirmatn WOFF2 files (D18, not yet shipped) and a real uploaded
  Custom Logo asset (currently SVG "H" fallback) are asset/content tasks, not code changes.
- **Approved content** — no fabricated institute facts/statistics (D19); Phase 2F must not invent
  numbers for the homepage impact section or anywhere else.

---

## 11. GitHub Actions trigger strategy fix

Current state (`.github/workflows/acceptance-docker-wordpress.yml`): triggers only on
`push` to two hardcoded branch names (`feature/phase-2b-academic-operations`,
`feature/phase-2c-student-portal`) plus `workflow_dispatch`. This means **every future feature
branch needs a manual edit to this file just to get CI**, and there is no pull-request trigger at
all — a PR against `main` today gets no automated Docker acceptance signal.

**Recommended fix for Phase 2D's own branch (and permanently going forward):**

```yaml
on:
  push:
    branches:
      - main
  pull_request:
    branches:
      - main
  workflow_dispatch: {}
```

- `push: branches: [main]` catches every merge (including fast-forwards and squash merges that
  don't go through a PR).
- `pull_request: branches: [main]` runs the suite on every PR targeting `main`, regardless of the
  feature branch's name — no more hardcoding branch names per phase.
- Keep `workflow_dispatch` for manual re-runs.
- Consider (not required) adding `concurrency` keyed on the PR/branch ref (already present in the
  file, keyed on `github.ref`) so superseded pushes cancel their own in-flight run rather than both
  running to completion.

This is a small, low-risk, mechanical fix — recommended to land at the **start** of Phase 2D
(before Phase 2D's own feature branch is pushed) so Phase 2D gets automatic CI without another
manual workflow edit.

---

## 12. Acceptance criteria and proposed tests, per phase

### Phase 2D

**Acceptance criteria:**
- Shared shell renders correctly in both light/dark themes, RTL-correct, at the theme's existing
  breakpoints, with no new JS framework/bundler introduced.
- Student can log in (branded UI), view/edit their own address profile, see their own phone
  (read/update through `Hedayati_User_Phone_Service` only), see their own verification status
  (masked national-ID presence only, no plaintext ever), see their own enrollments/sessions
  read-only, list/upload/download their own private documents.
- A student cannot reach any other student's data by manipulating a posted `user_id` (see §9).
- A student cannot reach a decrypted national ID through any code path, including their own.
- No regression to any existing Phase 2A/2B/2C wp-admin screen or public page.

**Proposed static tests:** a new `tests/verify-phase2d.js` in the existing convention — structural
checks that new front-end controller code enforces `$user_id === get_current_user_id()` before any
self-service read/write, that no new code path calls `get_national_id_decrypted()` from a
front-end/self-service context, and that new templates don't introduce a JS bundler/framework
dependency (grep for `node_modules` imports, `import React`, etc.).

**Proposed Docker runtime tests:** extend `docker/wp-tests/` with a `test-phase-2d.php` covering —
a real student login through the branded flow; self-service profile/phone update through the real
services (asserting the DB-level phone `UNIQUE`/reset-on-change rules still hold when reached via
the new front-end path); the negative case in §9 (student A cannot act on student B via a posted
`user_id`); confirming the document self-view/self-upload authorization contracts documented in
`class-document-service.php` are now actually reachable and correctly gated (they were "ready but
unreachable" as of Phase 2C).

### Phase 2E

**Acceptance criteria:** teacher/TA/reception panels reachable only by their own role + correct
scope (never see another teacher's run, another student outside reception's unscoped mandate where
intentional); the two capability-consistency fixes (§8) verified — `hedayati_manager` can use
Settings without `manage_options`, and course-editing capability resolution is deliberate (either
`hedayati_manage_courses`-gated or the native-post-capability choice is confirmed and documented,
not silently inconsistent); `require_student_scope()`'s extended scope helpers (§9) correctly deny
a teacher acting on a non-assigned student.

**Proposed tests:** extend `docker/wp-tests/test-phase-2e.php` — negative matrix for teacher/TA
panel access outside their assignment; `hedayati_manager` Settings-page save through
`hedayati_manage_settings` (not `manage_options`); course capability resolution for
`hedayati_manager` and a negative role.

### Phase 2F

**Acceptance criteria:** every P1 public-content item in `docs/ROADMAP.md` either shipped or
explicitly still ❓-blocked on an owner decision (Q8, consultation UX); full integrated staging
smoke test covering Phase 2A through 2E together on `mystik.ir` (not a merge — an actual staging
run); Lighthouse/Web Vitals + accessibility baseline per `docs/REQUIREMENTS.md` §13.

**Proposed tests:** a consolidated `docs/PHASE_2F_ACCEPTANCE.md` staging matrix superseding the
per-phase acceptance docs, executed once, end to end.

---

## 13. Unresolved business decisions — recorded, not chosen

These are recorded here exactly as found; none are decided by this document. Do not implement
Phase 2D against a guessed answer to any of these.

**Resolved since the first draft of this document (owner decisions):**

- **Account creation model:** reception-created only for this staging candidate — see §4a. Phase
  2D includes branded login + WordPress password reset, no public self-registration endpoint or
  registration link. Public self-registration remains a possible later, separately approved
  feature — do not build toward it speculatively.
- **Course capability-consistency direction** (§8.1): **decided** — a dedicated `course`
  CPT capability map backed by `hedayati_manage_courses`, following the already-tested Teacher CPT
  `map_meta_cap` pattern (HD-006). Not a retirement option; `hedayati_manage_courses` becomes a
  real, enforced capability in Phase 2E.

**Still unresolved:**

1. **Does identity verification unlock any benefit** (certificates, accredited exams) —
   `docs/REQUIREMENTS.md` 8.6, unchanged by Phase 2C, still fully open.
2. **Consultation page UX** — form fields, submission destination (email/CRM/WP admin), spam
   handling (`docs/REQUIREMENTS.md` 2.12, unchanged).
3. **Course Run public display rule** (Q8) — which run a public course page shows once Phase 2F
   wires theme fallbacks to run data.
4. **Manager front-end dashboards in Phase 2E — wanted, or is wp-admin sufficient?** (§2) — affects
   Phase 2E's actual scope; the capability-consistency fixes (§8) are needed regardless of the
   answer, but new manager-facing screens are not, if wp-admin already serves the role adequately.
5. **A student-safe explanation field for a `rejected` verification, if the owner ever wants one**
   (§5) — today the reviewer `note` is staff-internal only; showing a rejection reason to the
   student (if ever wanted) needs a deliberately separate, owner-approved field, not exposing the
   existing `note`.

### Recommended smallest set the owner must answer before Phase 2D implementation starts

**None of the five remaining unresolved items block Phase 2D.** The one item that did block it —
the account-creation model — is now resolved (reception-created, §4a). Items #1–#5 above affect
Phase 2D's optional benefit-messaging, or Phases 2E/2F specifically, and can be answered later
without rework. Phase 2D implementation may proceed on the documentation correction alone; no new
owner question is required first.

---

## 14. Proposed Phase 2D implementation prompt (NOT executed)

The following is a draft prompt for a **future** implementation session. No owner decision blocks
Phase 2D anymore (§13) — this is recorded here for review only. **Do not execute it as part of
this or any planning/documentation-correction task.**

> Before any product code: create branch `feature/phase-2d-account-shell` off `main` @ `32640e4`
> or later, **preserving the current uncommitted documentation corrections** — commit them onto
> the new branch (not onto `main`) as the branch's first commit(s), rather than discarding or
> re-deriving them. Do not begin implementation until that documentation correction is committed
> and the existing static suites still read **565 passed, 0 failed**, confirmed by re-running them
> on the new branch before writing any product code.
>
> Then implement Phase 2D (shared account shell + student self-service portal): §1 (shell), §2's
> student row, §3's student rows, §4/§4a (branded login + WordPress password reset only — no
> registration link or endpoint, per the resolved reception-created account model), §5 (full
> student self-service spec, as corrected) of `docs/PHASE_2D_PLANNING.md`. Apply §11's GitHub
> Actions trigger fix on this same branch before any product code.
>
> Mandatory security requirements, non-negotiable:
> - **Never show verification review notes or reviewer/reviewed-at metadata to a student.**
>   `Hedayati_Verification_Service::get_status()` returns `note`, `reviewer_id`, and `reviewed_at`
>   alongside `status` and `has_national_id` — the student-facing controller must project only
>   `status` and national-ID **presence** (never the decrypted value, never any raw `get_status()`
>   array forwarded to a template).
> - **Never call `get_national_id_decrypted()` from any self-service code path.** Masked presence
>   only, always.
> - **Add explicit ownership checks in the new portal controller itself** — `Hedayati_Document_Service`
>   is capability-agnostic and enforces nothing on its own; every call to `list_for_user()` /
>   `download()` / `upload()` from the new controller must be preceded by an explicit
>   capability check **and** `$user_id === get_current_user_id()` check written in that
>   controller. Do not treat the service's docblock-described contract as already-enforced
>   behavior anywhere in the new code.
> - Implement §9's `$user_id === get_current_user_id()` guard on every new self-service code path
>   — do not reuse `require_student_scope()` alone for anything under `/account/`.
> - **Test password-reset and login responses for account-enumeration resistance** — a request for
>   a nonexistent username/phone/email must return the same generic response (timing and content)
>   as one for an existing-but-wrong-credential account, consistent with the existing
>   `Hedayati_Auth` generic-error precedent (D-series, Phase 2A) — extend that same discipline to
>   the new branded password-reset flow, which WordPress core's default `retrieve_password()`
>   does not fully guarantee out of the box (verify and patch if the default behavior leaks
>   account existence).
> - **Send no-cache headers on every authenticated portal page response** and verify LiteSpeed (or
>   any active page cache) is configured to never cache an authenticated `/account/*` request —
>   add this as an explicit runtime check (e.g. asserting `Cache-Control`/`Pragma` headers, and a
>   documented LiteSpeed exclusion rule if the hosting cache is active), not an assumption.
>
> No new JS framework/bundler; extend the existing vanilla-JS/PHP-template conventions. Add
> `tests/verify-phase2d.js` and `docker/wp-tests/test-phase-2d.php` per §12's Phase 2D test plan,
> including explicit assertions for every bullet above (note/reviewer exposure, decrypt-path
> absence, document-controller ownership checks, enumeration-resistant reset/login responses,
> no-cache headers). Run the existing static suites after every subsystem; do not consider Phase
> 2D done without a green Docker acceptance run on the exact HEAD to be merged, mirroring the
> Phase 2C workflow. Do not merge, deploy, or contact `mystik.ir`/`drhedayati.com` without explicit
> further instruction.
