# Primary project memory — Dr. Hedayati Computer Institute

Updated 2026-09-04. Canonical owner handoff, with independent local review recorded separately in TEST_RESULTS.md. Read this first on future work; update these concise files as work progresses. Code establishes what exists; the owner's handoff establishes intent. Older conflicting status prose is superseded by this file.

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

## Merge gate — Phase 2B (2026-09-04)

**READY TO MERGE, not yet merged.** Evidence: Node static suites 458/0; local Docker CI runtime
suite 228/0 with verified cleanup (GitHub Actions run #3, commit `cbcb4da`); staging smoke test of
plugin 1.5.3 PASSED on `mystik.ir` covering the branch's own defect (Teacher CPT object-level
authorization, HD-006) plus core admin flows (menu, create/edit/delete, capability resolution);
`CURRENT_DB_VERSION` `2.2.0` and `ROLES_VERSION` `2.1.0` stable and correct; no known open product
defect. Deferred-but-not-blocking, mirroring how Phase 2A's Category 4 was treated: HD-003's
documented coverage gaps (R5/B5/J9/J1/J4/index-engine-charset), the staging low-privilege negative
matrix, and the unexplained historical phone-row observation. `docs/ROADMAP.md`'s older "theme-side
Course Run fallback wiring" bullet under Phase 2B's "remaining before merge" is stale — CURRENT_STATE.md
scopes that work to Phase 2D, and it is not part of `docs/PHASE_2B_ACCEPTANCE.md`'s own matrix;
ROADMAP.md has been corrected accordingly. No merge, deploy, or production contact performed in
this session — a human decision is still required to actually merge.
