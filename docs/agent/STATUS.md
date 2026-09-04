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
- **1.5.2 -> 1.5.3 (2026-09-04, GitHub Actions run #2, real defect, not CI infra):** editing or deleting an *existing* Teacher profile — `current_user_can('edit_post'|'delete_post', $teacher_id)` — still resolved false for manager AND administrator even on 1.5.2. Distinct bug from 1.5.1's: `map_meta_cap => true` requires `edit_published_posts`/`edit_private_posts`/`delete_published_posts`/`delete_private_posts` in addition to `edit_others_posts`/`delete_others_posts` for a published/private post authored by someone else (a Teacher profile's post_author is 0); those four keys were never declared, so WordPress auto-derived an ungranted `..._hedayati_teachers` capability from `capability_type`. 1.5.3 declares all four, pointed at `hedayati_manage_teachers` — no role/DB/version-marker change. See docs/agent/DEFECTS.md HD-006. **Not yet re-verified on CI — do not mark Teacher CPT edit/delete authorization as PASS until the next Actions run is green.**
- Remaining functional acceptance: enrollment duplicate/capacity/closed-run cases, attendance updates/no duplicates, Shamsi persistence, audit behavior, Teacher REST 404 and authorization negatives; use docs/PHASE_2B_ACCEPTANCE.md for the broader matrix.

## Local test setup and next action

- Docker setup commits: 6896979 stack, 1921f98 lifecycle scripts, de84b43 runtime tests, fff523b docs, cf261e5 REST types fix, 345e368 strict_types removal from test entry files.
- Stack: MySQL 8.0, wordpress:6.6-php8.3-apache, PHP 8.3 WP-CLI plus MySQL client; plugin/theme bind mounts; non-default mystik_ prefix; disposable volumes.
- Entry: scripts/run-acceptance.ps1 or scripts/run-acceptance.sh. Tests: docker/wp-tests/{helpers,test-phase-2a,test-phase-2b,run}.php. docker/.env comes from .env.example and stays ignored. env-down removes volumes.
- Local check: Docker/PHP/Podman/nerdctl unavailable; WSL reports virtualization/Virtual Machine Platform unavailable. Runtime acceptance NOT RUN. Static Node suites: 449 passed, 0 failed. See TEST_RESULTS.md and DEFECTS.md.
- Smallest execution path: fix HD-001, then run this checkout on an existing disposable Docker-capable Linux machine/VM with Compose. Alternatively enable the Windows virtualization requirements and install Docker Desktop. No machine was provisioned or system settings changed.
- Capture actual failing assertion text and exit status; never promote static checks into a runtime PASS. Address HD-002 with a direct pre-reset deletion assertion. Broaden runtime coverage for HD-003 before calling the full matrix complete.
