# Primary project memory — Dr. Hedayati Computer Institute

Updated 2026-09-05 (Phase 3 section appended below, above Phase 2D). Canonical owner
handoff, with independent local review recorded separately in TEST_RESULTS.md. Read this first on
future work; update these concise files as work progresses. Code establishes what exists; the
owner's handoff establishes intent. Older conflicting status prose is superseded by this file.

## Phase 3 — launch completion (2026-09-05) — MERGED TO MAIN, RUNTIME-CI GREEN, NOT STAGING-TESTED

**Merged to `main` via `e04c343` from `feature/phase-3-launch-completion`.** The prior Codex/ChatGPT "launch-completion"
working-tree WIP was preserved verbatim as the branch's first commit (`7500348`) and also on
`snapshot/codex-launch-completion-wip-2026-09-05`; nothing was discarded.

Owner decisions applied (see `docs/DECISIONS.md` D41–D43): adopt + finish the WIP; reception (and
manager/administrator) may create student accounts (`hedayati_create_students`, `ROLES_VERSION`
`2.3.0`, 24 managed caps); strong random **temporary password** shown once to staff, never
persisted plaintext, forced first-login change before any portal access; Course Runs public only
through explicit per-run staff opt-in; consultation page is phone/contact CTA only; manager keeps
wp-admin (no separate front-end manager dashboard); students never see verification rejection notes.

- **New:** `Hedayati_Account_Security` (forced first-login password change; `template_redirect`
  priority-1 interceptor; `hedayati_must_change_password` boolean marker; PRG on validation
  failure; `account.created` / `account.password_changed` PII-free audit). `Hedayati_Staff_Portal`
  (front-end `/panel/` — teacher/TA scoped runs+roster+sessions+attendance, reception
  lookup/create-student/enroll/identity/document intake) — rewritten from the WIP's dense
  one-liners to readable form, logic preserved, every handler re-checks capability + object scope.
  `Hedayati_Public_Content` (About/Contact/Consult/Teachers pages + course/teacher publication
  opt-in; run projection limited to `start_date`/`tuition_rial`/`registration_status`).
- **Capability-consistency fixes (D42):** `course` CPT → dedicated `hedayati_manage_courses` map
  (HD-006 pattern); `course-category` taxonomy + `Hedayati_Term_Meta` → `hedayati_manage_courses`;
  `Hedayati_Settings` → `hedayati_manage_settings` (+ `option_page_capability_*` filter).
- **Theme:** `page.php` (generic Page template — keeps `.entry-content`, adds `role=main`),
  `page-panel.php`, `template-parts/public-runs.php`, `assets/css/public-pages.css`,
  self-hosted **Vazirmatn** WOFF2 (login + public pages, no CDN). Plugin `1.7.0`→`1.8.0`, theme
  `1.1.0`→`1.2.0`. **No DB schema change** (`CURRENT_DB_VERSION` stays `2.3.0`).
- **Tests — GREEN:** Node static suites **732 passed, 0 failed** (`verify-phase2a` 74,
  `verify-phase2b` 208, `verify-phase2c` 132, `verify-phase2d` 82, **`verify-phase3` 85 new**,
  `verify-audit-log` 98, `verify-jalali` 53). `Acceptance (Docker WordPress)` GitHub Actions on the
  Phase 3 HEAD (`6c9bdac`, run `33976122273`): **491 passed, 0 failed, cleanup verified,
  RESULT: PASS** (up from the Codex-WIP baseline's 450 — `docker/wp-tests/test-phase-3.php` adds
  the temp-password / forced-change / capability-matrix runtime coverage). This is the first
  real-WordPress runtime evidence for Phase 2D **and** the launch-completion work — it is green.
- **Merged to `main`. NOT staging-tested. NOT deployed.** No `mystik.ir` / `drhedayati.com`
  contact. Staging is Phase 4: provision `HEDAYATI_DATA_ENCRYPTION_KEY` / `HEDAYATI_DATA_HMAC_KEY`
  / `HEDAYATI_PRIVATE_UPLOADS_DIR`, deploy the integrated 2A+2B+2C+2D+3 build once, run a single
  consolidated acceptance matrix. On deploy the `admin_init` roles sync must run (2.2.0 → 2.3.0)
  and the plugin recreates the `account` / `panel` / `about` / `contact` / `consult` / `teachers`
  Pages if missing.
- **Known runtime-testability gaps (documented, not skipped — same class as Phase 2C/2D):**
  `Hedayati_Account_Security::intercept()` and the portal `template_redirect` guard chains need a
  real HTTP request; real multipart file upload can't be fabricated by WP-CLI. All are explicit
  Phase 4 staging-acceptance items.
- **Visual completion pass done (2026-09-05).** No local WordPress/PHP/Docker, so all 8 Phase 3
  user-facing surfaces (`/account/` views, the forced-change screen, `/panel/` home/students/run,
  About/Contact/Consult/Teachers, the single-course "upcoming runs" part) were reviewed rendered
  through the **real theme CSS** (`main.css` + `account.css` + `public-pages.css` + `rtl.css`,
  faithful reconstructed markup) at desktop + mobile widths, RTL, light + dark. Fixes landed in
  commit `3e274d9` (`fix(phase3): visual-completion pass…`) — CSS + template polish only, no
  logic/security change: mobile portal-nav overflow + collision, flat panel nav cards, unstyled
  run/roster/result lists, the inline temp-password reveal, the tall attendance stack, teacher-card
  alignment + avatar, empty `.hd-page-copy`, forced-change screen chrome, a thin student dashboard,
  and missing document-upload labels. `verify-phase3.js` 85 → 101; Node total **748/0**. A final
  visual check on real hardware is folded into Phase 4 staging acceptance.
- **Real local WordPress visual follow-up (2026-09-05).** The current feature branch was served
  through a disposable local WordPress/PHP/MariaDB runtime and reviewed in a browser at desktop
  and mobile widths, Persian RTL, and light/dark modes. Public pages, every student-account view,
  `/panel/` home/run, and the forced password screen rendered without page-level horizontal
  overflow. This exposed WordPress's admin toolbar above the staff and forced-password journeys;
  both flows now suppress it. `verify-phase3.js` is **103/0**; Node total **750/0**.
- **Staging login correction (2026-09-05).** Plugin `1.8.1` stops a request already rejected as
  `too_many_retries` from being recorded as another failed credential attempt. The 15-minute
  transient can now expire even if someone retries while blocked. No threshold, password rule,
  role, capability, or database schema changed. Hotfix branch `fix/staging-login-lockout`:
  Node static **752/0** and local real-WordPress acceptance **492/0**.

## Phase 2D — account shell & student self-service portal (2026-09-05) — IMPLEMENTED, NOT MERGED, NOT STAGING-TESTED

**Branch `feature/phase-2d-account-shell`, off `main` @ `32640e4` (Phase 2B + Phase 2C already
merged).** Implements `docs/PHASE_2D_PLANNING.md`'s Phase 2D scope: `Hedayati_Auth_UI` (branded
login, no public self-registration, password-reset enumeration hardening, role-aware routing,
wp-admin exclusion for students) and `Hedayati_Student_Portal` (a real `account` Page,
`?view=`-routed dashboard/profile/verification/enrollments/documents, every mutation an
`admin-post.php` action owned by `get_current_user_id()` only — never a posted `user_id`).

- **Plugin `1.6.0` → `1.7.0`; theme `1.0.0` → `1.1.0`. No DB/roles schema change** —
  `CURRENT_DB_VERSION` stays `2.3.0`, `ROLES_VERSION` stays `2.2.0`, managed capability count stays
  23. Every read/write reuses an existing table and an existing `hedayati_*` capability.
- Node static suites: 642/0 total (`verify-phase2a.js` 74, `verify-phase2b.js` 208,
  `verify-phase2c.js` 132, `verify-phase2d.js` 77 new, `verify-audit-log.js` 98, `verify-jalali.js`
  53). `git diff --check` clean throughout.
- `docker/wp-tests/test-phase-2d.php` authored and wired into `docker/wp-tests/run.php` /
  the `Acceptance (Docker WordPress)` workflow — covers account-page bootstrap, role-aware login
  redirect, no-self-registration, password-reset enumeration-hardening filter logic, the central
  "student A cannot touch student B" ownership property (profile/phone/documents), phone
  normalization/uniqueness/reset-on-change through the new portal caller, verification-display
  narrowing, and read-only Shamsi-dated enrollments.
  **GitHub Actions result: not yet run in this session as of this note — this branch has not been
  pushed. Update this line with the actual run result once pushed and checked**, mirroring how
  Phase 2C's STATUS.md entries tracked real CI evidence rather than an assumed pass. **Correction:**
  under the §11 trigger fix (`push`/`pull_request` both scoped to `main`), pushing this feature
  branch alone will **not** trigger a run — a pull request against `main` or an explicit
  `workflow_dispatch` targeting this branch is required. Do not assume a push alone produces CI
  evidence; a release-blocking password-reset defect was found and fixed on this branch
  (see the commit correcting `Hedayati_Auth_UI`'s enumeration hardening) before any such run.
- Two runtime-testability gaps are explicitly documented (not silently skipped), matching Phase
  2C's precedent: (1) `is_uploaded_file()` cannot be satisfied by a WP-CLI process, so full
  end-to-end upload acceptance needs a real HTTP request; (2) the full `template_redirect` →
  `is_page()` guard chain needs a real HTTP request to exercise. Both are staging acceptance items.
- Fixed one real bug found while writing the Docker tests:
  `Hedayati_Student_Portal::handle_document_download()`'s success path ended with a raw `exit()`
  (not through `wp_die`/`wp_redirect`), which would have killed the WP-CLI test process — the same
  class of bug Phase 2C's `class-student-admin.php` had; fixed with the same `HDIT_TESTING`-gated
  `maybe_exit()` seam.
- **NOT merged to `main`. NOT staging-tested. NOT deployed.** No production or staging contact
  occurred while building this. `docs/PHASE_2D_STAGING_ACCEPTANCE.md`-equivalent staging status
  remains explicitly NOT RUN (no such file exists yet — author one before any staging attempt,
  matching Phase 2C's `docs/PHASE_2C_ACCEPTANCE.md` precedent).

## Phase 2C — student identity, verification, private documents (2026-09-05) — MERGED TO `main`

**Built on `feature/phase-2c-student-portal` (kept, superseded), off `main` (Phase 2B already
merged); now itself merged into `main` (`--no-ff` commit `32640e4`).** Owner
resolved `docs/OPEN_QUESTIONS.md` Q10–Q13 (see `docs/DECISIONS.md` D36–D40) and Phase 2C was
implemented per an approved, 3-times-revised plan (national-ID encryption + strict key format +
defense-in-depth decrypt authorization; an enforced verification-state machine; environment-gated
private-document storage with real content-sniffing, path-containment hardening, and
upload/purge failure-consistency handling; a dedicated `hedayati_upload_student_documents`
capability instead of overloading `edit_user`; a single privileged national-ID reveal action with
no other plaintext path anywhere).

- **Plugin `1.6.0`; `CURRENT_DB_VERSION` `2.3.0`; `ROLES_VERSION` `2.2.0`; 23 managed capabilities.**
- Node static suites: 564/0 (`verify-phase2a.js` 74, `verify-phase2b.js` 208, `verify-phase2c.js`
  131, `verify-audit-log.js` 98, `verify-jalali.js` 53).
- New `docker/wp-tests/test-phase-2c.php` real-WordPress-runtime suite extends the `Acceptance
  (Docker WordPress)` GitHub Actions workflow (triggered on push to
  `feature/phase-2c-student-portal`). **This was the completion gate for Phase 2C — satisfied.**
  Two early runs on this branch (commits `e24cfca` / `968867a`) failed: a pre-existing
  `docker/wp-tests/test-phase-2a.php` hardcoded exact-version assertion broke on Phase 2C's
  legitimate version bump (same category as an earlier Node-suite issue, just missed in this PHP
  file), and a genuine bug — the `profile_update` hook's `$old_user_data` properties for
  `first_name`/`last_name` live-query `get_user_meta()` on access rather than freezing a snapshot,
  so by the time the hook fired the "old" value already equalled the new one and the
  legal-name-change verification reset never triggered. Fixed by hooking `update_user_meta`
  instead (fires before the `UPDATE` query runs) — commit `2fc121f`. A further packaging-time
  check (before building the staging artifact) caught the plugin's `Version:` docblock header
  still reading `1.5.3` while `HEDAYATI_CORE_VERSION` said `1.6.0` — fixed in `da77119`, with a
  new static assertion so the two locations can't silently drift apart again.
  **GREEN on the final Phase 2C HEAD, commit `20d5fd4` (run id `33954971036`): 335 passed, 0
  failed, cleanup verified.** Node static suites on the same HEAD: 565/0
  (`verify-phase2a.js` 74, `verify-phase2b.js` 208, `verify-phase2c.js` 132, `verify-audit-log.js`
  98, `verify-jalali.js` 53). No known open Phase 2C product defect.
  **Merged into `main` 2026-09-05 (`--no-ff` commit `32640e4`, together with Phase 2B).**
  Staging deployment (`mystik.ir`) and production contact (`drhedayati.com`) remain untouched and
  deliberately deferred — see `docs/PHASE_2C_STAGING_DEPLOY_CHECKLIST.md` for what remains before
  that step, and `docs/DEPLOYMENT.md` for the required `wp-config.php` constants that are **not
  yet provisioned anywhere**. Phase 2D+ planning is recorded in `docs/PHASE_2D_PLANNING.md`;
  implementation has not started.
- `docs/PHASE_2C_ACCEPTANCE.md` (staging smoke-test matrix) is authored but **NOT executed** —
  staging execution and any deploy remain separate, explicit, owner-approved steps, unaffected by
  the `main` merge. No production or staging contact has occurred at any point in this project.
- Known, documented gap: the Docker/WP-CLI test harness cannot fabricate a real HTTP file upload
  (`is_uploaded_file()` is only ever true for one), so `Hedayati_Document_Storage::save()`'s
  upload-origin gate is asserted statically (source inspection) rather than exercised end-to-end
  in Docker CI; everything after that gate (content-sniffing, path hardening, randomization,
  orphan-cleanup) is exercised via a documented, reflection-based testing seam
  (`process_and_store()`). Full coverage of the gate itself needs a real HTTP request — see
  `docs/PHASE_2C_ACCEPTANCE.md` E1.

---

## Workspace and boundaries

- Primary shared repository: C:/Projects/drhedayati-wordpress. This is the active WordPress project; drhedayati-v2 and React/Vite + Express/Prisma/PostgreSQL are historical only.
- Branch: feature/phase-2b-academic-operations. Inspected HEAD: 345e368bfa1a17079c7436c085e9514f441aee5e. Clean before this documentation review; review notes remain uncommitted.
- Deliverables: custom theme theme/hedayati/ and domain plugin plugin/hedayati-core/. No product features requested in this review.
- Staging: mystik.ir, Iran-IP restricted. Production: drhedayati.com. No merge, push, deployment or production contact without explicit approval. Do not commit unless asked. No live environment was contacted in this review.

## Approved public experience

- Persian RTL first, Shamsi display, normalized ASCII digits for backend/search. Red/white brand with designed dark/light modes; professional, smooth and appropriate for an institute. Preserve Concept C / NavigatorHome; do not redesign blindly.
- Homepage: sticky logo/nav/CTA/mobile header; two-column Navigator hero with 2×2 console/grid; four-column dynamic category strip; featured courses in two rows with all-course emphasis, no fabricated suggestions; «چرا مجتمع دکتر هدایتی؟» impact section; full-width red CTA; four-column footer (brand, quick links, departments, contact).
- Public courses archive, category and single pages; About/institute information and Contact/CTA areas. Keep course content editable by staff. Existing docs report dedicated About/Contact/consult pages still missing; intent does not imply implementation.
- Theme structure: style.css, theme.json, functions.php, front-page.php, template parts, assets/css/main.css and rtl.css, assets/js/main.js, archive/singular/404 fallbacks.
- Do not resurrect old Google Maps/event ideas unless confirmed in current requirements. Do not invent institute facts, prices or statistics.

## Panels and privacy

- Roles: student, teacher_assistant, teacher, reception, hedayati_manager, administrator. Preserve least privilege and the approved capability matrix.
- Student intent: username OR Iranian phone plus password; no Google login. Own portal/profile/enrollments/private documents only. Admin verification can unlock additional options, with benefits to be confirmed rather than invented.
- Profile intent: national ID, phone, email, address and extensible data. Address/city/postal-code foundation exists; full student portal, verification workflow, national-ID storage and document upload are future work.
- National-card/birth-certificate copies must remain private, never public Media Library URLs. Long-term web-host storage is not intended: planned operational transfer to institute local storage about every 48 hours; a separate media host may follow. Transfer/verification/storage decisions still need implementation design; do not imply automation exists.
- Teacher/staff operations belong in academic administration. Future role panels should be simple for ordinary institute staff. Account-enumeration resistance is required.

## Current implementation

- Plugin 1.5.3; CURRENT_DB_VERSION 2.2.0; ROLES_VERSION 2.1.0; 22 managed Hedayati capabilities.
- Phase 2A: Iranian phone normalization, unique phone table, username/phone authentication, generic phone errors, rate limiter, roles/caps. Phone verification and student identity verification are distinct.
- Phase 2B tables: course_runs, run_staff, sessions, enrollments, attendance. Migration 2.2.0 adds audit_log. Dynamic WordPress table prefix; Gregorian ISO storage and ASCII digits.
- Teacher CPT is admin-only, optional 1:1 WP-user link. Runs have capacity, integer-rial tuition, status and dates. Sessions are unique by run/session number. Instructors require Teacher profiles; TA requires WP user; one primary instructor per run.
- Enrollments unique by run/user with capacity and completed/cancelled-run guards; allowed status values are validated. Attendance upsert/bulk is unique by session/enrollment, rejects cross-run IDs, and nulls recorded_by on user deletion.
- Audit API is append-only, survives domain cascades, and stores only id, actor_id, action, object_type, object_id, note, created_at. No IP/UA/JSON or private document data. Do not revive older IP/UA plans.
- Jalali helper exists; Course Run input tries ISO then Jalali, stores Gregorian Y-m-d, accepts Persian digits. Public/other input coverage is not necessarily complete.

## Owner-reported staging evidence (not independently re-run here)

- Phase 2A non-destructive checks largely passed: roles/admin/schema/matrix, username auth, limiter, 10 phone-login formats, privacy-safe malformed inputs, uniqueness and verification-reset lifecycle. Category-4 destructive tests remain deferred and are not required for the normal gate.
- Correction: deleting a disposable QA user left one orphan phone row, manually removed. Automatic cleanup is NOT proven. HD-002 remains open; blanket cleanup PASS claims are invalid.
- Phase 2B health gate passed on mystik.ir: plugin 1.5.2, DB 2.2.0, roles 2.1.0, six new InnoDB/utf8mb4 tables, healthy homepage/admin, Teacher and Course Run creation.
- Teacher CPT bug on 1.5.1: primitive hedayati_manage_teachers reused as singular meta caps with map_meta_cap=true, making bare permission checks false. Fix 478b920 / 1.5.2 uses distinct edit_hedayati_teacher/read_hedayati_teacher/delete_hedayati_teacher meta caps, retaining the primitive. Owner reports staging retest passed for the bare-primitive check, menu visibility and profile *creation* (create_posts only needs the collection cap, unaffected by the bug below). Full low-privilege matrix still requires evidence.
- **1.5.2 -> 1.5.3 (2026-09-04, GitHub Actions run #2, real defect, not CI infra):** editing or deleting an *existing* Teacher profile — `current_user_can('edit_post'|'delete_post', $teacher_id)` — still resolved false for manager AND administrator even on 1.5.2. Distinct bug from 1.5.1's: `map_meta_cap => true` requires `edit_published_posts`/`edit_private_posts`/`delete_published_posts`/`delete_private_posts` in addition to `edit_others_posts`/`delete_others_posts` for a published/private post authored by someone else (a Teacher profile's post_author is 0); those four keys were never declared, so WordPress auto-derived an ungranted `..._hedayati_teachers` capability from `capability_type`. 1.5.3 declares all four, pointed at `hedayati_manage_teachers` — no role/DB/version-marker change. See docs/agent/DEFECTS.md HD-006.
- **GitHub Actions run #3 (commit `cbcb4da`) is GREEN:** https://github.com/CloudyCup/drhedayati-wordpress/actions/runs/33910009101 — 228 passed, 0 failed, cleanup verified, result PASS. Node static suites 458/0. This is the first fully green execution of the local Docker runtime suite; it runtime-verifies HD-002, HD-004 and HD-006 (see docs/agent/DEFECTS.md for exactly what each closes and what remains explicitly open — HD-003's documented gaps, e.g. the full 22-cap x 6-role matrix and a second real `dbDelta` pass, are NOT covered by this green run).
- **Staging smoke test on mystik.ir PASSED (2026-09-04, plugin 1.5.3):** homepage loads; wp-admin loads; Hedayati Core reports 1.5.3; «اساتید» menu appears and opens; disposable Teacher creation/edit/save/deletion all work; hedayati_manage_teachers resolves correctly for administrator. This is T1's retest scope executed on the real staging site. **HD-006 is now CLOSED** — fixed, runtime-verified in local CI, and staging-verified. No production (drhedayati.com) contact occurred.
- Remaining functional acceptance not covered by the smoke test or local CI: the full low-privilege negative matrix (reception/teacher/TA/student) specifically on staging, and HD-003's documented still-open items (R5 full 22-cap x 6-role matrix, B5/J9 second dbDelta pass, J1/J4 exhaustive mutation/actor-attribution coverage, index/engine/charset inspection). Category-4 destructive Phase 2A tests remain deferred and not required, per the existing precedent. The historical staging orphan-phone-row observation (original HD-002 report) remains an unexplained, unreproduced data point — the passing local deletion-cleanup test does not retroactively explain it. Use docs/PHASE_2B_ACCEPTANCE.md for the full matrix.

## Local test setup and next action

- Docker setup commits: 6896979 stack, 1921f98 lifecycle scripts, de84b43 runtime tests, fff523b docs, cf261e5 REST types fix, 345e368 strict_types removal from test entry files, afb5fbd HD-005 (WP_ENVIRONMENT_TYPE propagation), cbcb4da HD-006 (Teacher object-level caps, plugin 1.5.3).
- Stack: MySQL 8.0, wordpress:6.6-php8.3-apache, PHP 8.3 WP-CLI plus MySQL client; plugin/theme bind mounts; non-default mystik_ prefix; disposable volumes.
- Entry: scripts/run-acceptance.ps1 or scripts/run-acceptance.sh (local); `.github/workflows/acceptance-docker-wordpress.yml` ("Acceptance (Docker WordPress)") runs the same suite on a GitHub Actions Linux runner. Tests: docker/wp-tests/{helpers,test-phase-2a,test-phase-2b,run}.php. docker/.env comes from .env.example and stays ignored. env-down removes volumes.
- Local check (this machine): Docker/PHP/Podman/nerdctl still unavailable; WSL reports virtualization/Virtual Machine Platform unavailable. Runtime acceptance still NOT RUN **on this machine**. Static Node suites here: 458 passed, 0 failed.
- **GitHub Actions run #3 (commit cbcb4da) is GREEN: 228 passed, 0 failed, cleanup verified, result PASS.** This is the actual runtime proof this section used to be waiting on — obtained via CI, not this machine. See TEST_RESULTS.md and DEFECTS.md for exactly what that run verifies (HD-002, HD-004, HD-005, HD-006) versus what remains explicitly open (HD-003's documented gaps; the staging retest of 1.5.3).
- Next action: staging (`mystik.ir`) retest of plugin 1.5.3 (Teacher edit/delete authorization, T1) is DONE and PASSED (2026-09-04, see above). Broaden HD-003's still-open runtime coverage (R5 full matrix, B5/J9 real second dbDelta pass, J1/J4 exhaustive assertions) as separate, non-blocking follow-up work.

## Merge gate — Phase 2B (2026-09-04) — SATISFIED, MERGED 2026-09-05

Evidence at the time: Node static suites 458/0; local Docker CI runtime suite 228/0 with verified
cleanup (GitHub Actions run #3, commit `cbcb4da`); staging smoke test of plugin 1.5.3 PASSED on
`mystik.ir` covering the branch's own defect (Teacher CPT object-level authorization, HD-006) plus
core admin flows (menu, create/edit/delete, capability resolution); `CURRENT_DB_VERSION` `2.2.0`
and `ROLES_VERSION` `2.1.0` stable and correct; no known open product defect. Deferred-but-not-
blocking, mirroring how Phase 2A's Category 4 was treated: HD-003's documented coverage gaps
(R5/B5/J9/J1/J4/index-engine-charset), the staging low-privilege negative matrix, and the
unexplained historical phone-row observation — **all three remain open today, unchanged by the
merge below.** `docs/ROADMAP.md`'s older "theme-side Course Run fallback wiring" bullet under
Phase 2B's "remaining before merge" was stale — `CURRENT_STATE.md` scopes that work to Phase 2F,
and it is not part of `docs/PHASE_2B_ACCEPTANCE.md`'s own matrix; `ROADMAP.md` has been corrected
accordingly.

**Merged 2026-09-05:** `feature/phase-2b-academic-operations` and `feature/phase-2c-student-portal`
were merged into `main` together via a single `--no-ff` merge commit, `32640e4`, after Phase 2C's
own Docker acceptance suite (below) ran green on the exact HEAD merged. No deploy, staging
provisioning, or production contact occurred as part of this merge.
