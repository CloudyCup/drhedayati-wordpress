# ROADMAP.md

Prioritized backlog, reconciled from `docs/HANDOFF_LEGACY.md` and the current repository
(2026-09-02). Priorities: **P0** blocker · **P1** launch · **P2** enhancement · **P3**
future/optional.

Do not start feature development from this file without confirming scope with the project owner —
several items still need institute decisions (marked ❓, see the bottom of this file and
`docs/REQUIREMENTS.md`).

---

## P0 — blockers

1. **Phase 2A staging integration acceptance.** 🟢 **Non-destructive behavioural acceptance
   COMPLETE / PASSED 2026-09-03** on `mystik.ir` (disposable `student` users `qa_phase2a` ID 2 /
   `qa_phase2a_b` ID 3, synthetic data, deleted at teardown; WP-CLI via the hosting toolkit). All
   the checks listed below passed — see `docs/PHASE_2A_ACCEPTANCE.md` "Behavioural execution log
   (2026-09-03)". Not exercised: T2.4 (native unknown-username wording — non-gating).
   **Category-4 destructive tests remain NOT RUN / DEFERRED — NOT REQUIRED for this gate.**
   For the record, what was verified on `mystik.ir`, with a disposable `student` account (never
   destructive cases against an admin account):
   - normal username/password login; phone + password login;
   - `0914…`, `+98914…`, `0098914…`, `989…`, Persian-digit, Arabic-digit forms all resolve to the
     one account;
   - unknown phone and wrong password → identical privacy-safe generic error;
   - 5-attempt identifier lockout and 30-attempt IP lockout through the real filter chain; 900s
     expiry;
   - DB `UNIQUE` behavior and the friendly `phone_already_exists` duplicate error;
   - role/capability presence and least-privilege (reception lacks `manage_options`, TA lacks
     attendance, manager lacks `manage_options`);
   - changing a number resets verification; unchanged number preserves it;
   - deleting the test user removes its `hedayati_user_phones` row.
   Record exact results; clean up test data deliberately.
2. **Reconcile source of truth.** Confirm the deployed staging artifact equals this repo (theme +
   plugin versions, any server-only edits, Vazirmatn status). Fix drift.
3. **Commit / tag the accepted Phase 2A artifact** so local Git == staging. Fix only defects found
   by the P0 tests, re-run checks, redeploy, re-verify non-regression.
4. **Repo hygiene** — ✅ **done 2026-09-03** (owner-approved, isolated commit on
   `feature/phase-2b-academic-operations`): `package-plugin/` and the root `drhedayati-wordpress`
   dump removed; stale `1.1.0` ZIPs deleted; `scripts/build-packages.ps1` added so releases can
   only be built from canonical source. See D27 / D35.

## P1 — required for launch

1. **Phase 2B — academic operations.** 🟢 **MERGED to `main`** (`--no-ff` commit `32640e4`,
   alongside Phase 2C; originally built on `feature/phase-2b-academic-operations`, which has since
   been superseded by `main`'s history): Teacher CPT (+ optional 1:1 WP-user link); Course Runs
   (nullable capacity/tuition, integer-rial tuition, separate `run_status` / `registration_status`
   as validated strings); Sessions (`UNIQUE(run_id, session_number)`, canonical datetimes); staff
   assignments (primary/additional instructor, TA — D11 asymmetry); Enrollments
   (`UNIQUE(run_id, user_id)`, capacity check); attendance (`UNIQUE(session_id, enrollment_id)`,
   same-run guard); metadata-only append-only audit log (migration `2.2.0`). A plugin-`1.5.3`
   staging smoke test PASSED on `mystik.ir` (2026-09-04) covering the Teacher CPT object-level
   authorization fix (HD-006) and core admin flows — **staging acceptance beyond that smoke test
   remains NOT RUN**; merging to `main` did not require or constitute it. See
   `docs/agent/STATUS.md` and `docs/PHASE_2B_ACCEPTANCE.md` for the exact evidence and what remains
   explicitly deferred (not blocking, mirroring how Phase 2A's Category 4 was treated): HD-003's
   documented coverage gaps (full 22-cap × 6-role matrix, a second real `dbDelta` pass, exhaustive
   mutation/actor-attribution assertions), the staging low-privilege negative matrix, and the
   unexplained historical staging phone-row observation (HD-002 — still not retroactively
   explained by anything built since).
   **Explicitly NOT part of this item, deferred to Phase 2D/2F** (corrected from an earlier draft of
   this bullet): theme-side fallback wiring so the public course page reads Course Run data for
   `_course_teacher` / `_course_next_start_date` / `_course_price` / `_course_registration_state` —
   see `docs/CURRENT_STATE.md`'s "Planned / not implemented" list. `docs/PHASE_2B_ACCEPTANCE.md`'s
   own matrix never tested the public theme; this was never part of Phase 2B's actual acceptance
   scope.
2. **Phase 2C — student identity & security.** 🟢 **MERGED to `main`** (`--no-ff` commit `32640e4`;
   originally built on `feature/phase-2c-student-portal`, kept but superseded by `main`'s history):
   address/city/postal-code profile fields in usermeta (`Hedayati_Student_Profile`); the
   metadata-only append-only audit log (`hedayati_audit_log`, `Hedayati_Audit_Log`, D33); national
   ID (encrypted at rest + keyed-HMAC duplicate detection, `Hedayati_Crypto` +
   `Hedayati_Verification_Service`, D36); an enforced verification-state workflow (D37); and
   private document upload/storage-outside-webroot/authorized streaming/manual-retention
   (`Hedayati_Document_Storage` + `Hedayati_Document_Service`, D38). See
   `docs/OPEN_QUESTIONS.md` Q10–Q13 (all resolved) and `docs/DECISIONS.md` D36–D40. Audit log
   IP/UA fields are **permanently** not added (D39, not a deferred policy). Plugin `1.6.0`,
   `CURRENT_DB_VERSION` `2.3.0`, `ROLES_VERSION` `2.2.0`, 23 managed capabilities. Node static
   suites 565/0; the extended `Acceptance (Docker WordPress)` GitHub Actions runtime suite is
   **GREEN on the merged HEAD** (335/0, cleanup verified). **Staging execution of
   `docs/PHASE_2C_ACCEPTANCE.md` and any deploy remain separate, owner-approved, NOT-YET-DONE
   steps — merging to `main` is not staging acceptance and is not a deploy.** The three required
   `wp-config.php` constants are not provisioned anywhere. Benefit linkage (verification unlocking
   certificates/exams) remains unapproved and unbuilt — `docs/REQUIREMENTS.md` 8.6, unchanged.
3. **Interfaces + launch completion.** The old "Phase 2D/2E/2F" split is superseded — Phases 2E
   and 2F were consolidated into a single **Phase 3 "launch completion"** (the shape the prior
   Codex working session had already taken, ratified by the owner 2026-09-05). Status:
   - **Phase 2D — shared account shell + student portal: 🟢 IMPLEMENTED** on
     `feature/phase-2d-account-shell` (off `main` @ `32640e4`), now the base of the Phase 3 branch.
   - **Phase 3 — launch completion: 🟢 MERGED TO `main`, runtime-CI GREEN** via `e04c343`.
     Front-end staff `/panel/` (teacher/TA/reception);
     reception-created student accounts + one-shot temporary password + forced first-login change
     (`Hedayati_Account_Security`, D41); public About/Contact/Consult/Teachers pages + per-run
     publication opt-in (D43); course/taxonomy/settings capability-consistency fixes (D42);
     `page.php`; self-hosted Vazirmatn; Shamsi on the course page. Plugin `1.8.1`, theme `1.2.0`,
     no DB change. Node static **752/0**; local WordPress acceptance **492/0, PASS**. **Staging validation in progress; not deployed to production.**
   - **Phase 4 — integrated staging** (`mystik.ir`, once, end to end) and **Phase 5 — production
     cutover** (`drhedayati.com`) remain the only steps after Phase 3 merges to `main`.
4. **Public content & pages.** Create About, Contact, and consultation pages (templates + a
   consultation submission handler ❓ UX undecided); teacher directory/profiles; editable
   homepage/footer/navigation content where staff editing is required. Note: `header.php`,
   `footer.php`, `menu-fallbacks.php`, `hero-navigator.php`, `impact-section.php`, `cta-band.php`
   already link to `/about/`, `/contact/`, `/consult/`.
5. **Self-host Vazirmatn.** Add approved WOFF2 weights (400/500/600/700/800), enqueue in
   `functions.php`, `font-display: swap`, no CDN. The CSS/`theme.json` stack already names it.
6. **Shamsi date layer.** 🟡 **Conversion helper + Phase 2B admin display + Course Run date input
   done** (`Hedayati_Jalali` — `from/to_gregorian`, `format`/`format_long`, `parse_input`; Shamsi
   shown alongside every Gregorian date in the «عملیات آموزشی» screens; Course Run `start_date` /
   `end_date` accept ISO **or** Shamsi and store Gregorian; 53/53 Node tests incl. a multi-decade
   round-trip fuzz). **Remaining:** Shamsi input on the remaining date fields (sessions,
   enrollments) once those get edit UIs; public-site Shamsi rendering on the course page and future
   student/teacher interfaces; plus Persian/Arabic → ASCII digit normalization for national ID and
   other searchable numeric fields (national ID is Q10-blocked).
7. **Homepage impact-section statistics.** ❓ verified numbers from the institute + a mechanism to
   edit them (Customizer options or plugin settings — neither exists yet). Until then the section
   stays number-free.
8. **Legacy content / URL / SEO inventory + migration + redirect map.** Inventory the live
   ASP.NET site (pages, URLs, courses, articles, images, forms, exam/certificate functions,
   contact info, meta). Build redirects for every changed path. Migrate worthwhile content.
9. **Launch hardening & QA.** Roles/security review, privacy/retention decisions, performance
   (Lighthouse/Web Vitals baseline, responsive images, lazy-load, cache strategy), accessibility
   (WCAG keyboard/contrast/labels/reduced-motion/screen-reader), full RTL + light/dark QA at
   mobile/tablet/desktop widths, backup/restore drill.
10. **Controlled `mystik.ir` → `drhedayati.com` cutover** with backups, redirect map, DNS/email/
    TLS/cache/rollback checklist, and explicit owner approval (see `docs/DEPLOYMENT.md`).

## P2 — enhancements

- **AI Studio manager and student experience — in progress on `feature/manager-experience` (D45).**
  Built: unified `/panel/` manager home, matching `/account/` learning dashboard, owner-scoped
  upcoming-class schedule, and the in-panel **course table + featured curation** tabs
  (`?view=courses`, `?view=featured`) against the real course CPT with capability/nonce-guarded
  toggles. Node static green (769/0); Docker CI + browser review still pending. Consultation,
  progress, certificates, materials, support, notifications, and an in-panel Settings form remain
  separate modules with their own policy + acceptance gates — see `docs/AI_STUDIO_PANEL_MATRIX.md`
  §E and `docs/AI_STUDIO_INTEGRATION.md`.
- Improved course-authoring editor UX (structured fields currently sit below a large Gutenberg
  canvas).
- Change the `course_cat_order` default so unordered categories don't sort to the front. ❓ (small
  behavior change — confirm desired ordering)
- Fix stale docblock in `Hedayati_Query::get_related_courses()`; align featured-course secondary
  sort with the documented intent (title vs date); align `hedayati_course_monogram()` docs.
- Operational reporting / search / bulk workflows once staff usage patterns are known.
- SEO: unique titles/meta descriptions, canonical URLs, Open Graph, structured data where
  accurate, XML sitemap, Search Console migration checks.
- Site search UX for public content. ❓
- Stronger migration locking (ownership tokens) before any long-running migration ships.
- `phpcs` / WordPress coding-standards config, `.editorconfig`, and CI to run the existing test
  suites automatically.
- Stylelint pass over `main.css`.

## P3 — future / optional

- SMS / OTP integration via a provider abstraction (provider ❓ unknown).
- Online payment gateway + accounting/refund workflows. ❓ not currently required.
- Automated ~48-hour offsite transfer of private documents + reconciliation. ❓ protocol
  unspecified.
- Separate media host for public guides/videos. ❓ not approved as current architecture.
- Expanded TA privileges (e.g. attendance) — only after explicit institute approval. ❓
- Richer learning/exam/certificate capabilities once requirements are verified. ❓

---

## Items needing an institute / owner decision before implementation

- Does identity verification unlock any benefit (certificates, accredited exams)? What documents
  are mandatory? (Document web-host retention — 7 days post-archive-confirmation, then manual
  purge — and audit IP/UA — never collected — are now resolved, D38/D39.)
- Consultation page: form fields, where submissions go (email? CRM? WP admin?), spam handling.
- Impact-section statistics: the actual numbers, and Customizer vs plugin-settings as the editor.
- Course category default ordering behavior.
- SMS provider (for future OTP/notifications).
- Payment: needed for launch or not.
- Cutover timing and the legacy-content scope to migrate vs redirect vs drop.
- ~~Fate of the stale repo artifacts~~ — resolved (removed 2026-09-03, D27/D35).
