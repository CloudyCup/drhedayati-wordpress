# ROADMAP.md

Prioritized backlog, reconciled from `docs/HANDOFF_LEGACY.md` and the current repository
(2026-09-02). Priorities: **P0** blocker · **P1** launch · **P2** enhancement · **P3**
future/optional.

Do not start feature development from this file without confirming scope with the project owner —
several items still need institute decisions (marked ❓, see the bottom of this file and
`docs/REQUIREMENTS.md`).

---

## P0 — blockers

1. **Phase 2A staging integration acceptance.** Isolated/static tests pass; real WordPress
   behavior is unproven. On `mystik.ir`, with a disposable `student` account (never test
   destructive cases against an admin account), verify:
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
4. **Repo hygiene (low effort):** decide the fate of `package-plugin/`, root `hedayati-core.zip`,
   and the stray root file `drhedayati-wordpress` (accidental diff dump). Recommend removing them
   in a dedicated commit — needs owner sign-off since "do not delete files" applied to this
   documentation task.

## P1 — required for launch

1. **Phase 2B — academic operations.** 🟡 **Repository implementation complete** on branch
   `feature/phase-2b-academic-operations` (not merged): Teacher CPT (+ optional 1:1 WP-user link);
   Course Runs (nullable capacity/tuition, integer-rial tuition, separate `run_status` /
   `registration_status` as validated strings); Sessions (`UNIQUE(run_id, session_number)`,
   canonical datetimes); staff assignments (primary/additional instructor, TA — D11 asymmetry);
   Enrollments (`UNIQUE(run_id, user_id)`, capacity check); attendance (`UNIQUE(session_id,
   enrollment_id)`, same-run guard). Migration `2.1.0`, five services, `hedayati_manage_teachers`
   capability, per-run ownership-scope enforcement in the «عملیات آموزشی» admin UI, Node static
   tests (170/170).
   **Remaining before merge:** (a) staging behavioural acceptance — `docs/PHASE_2B_ACCEPTANCE.md`
   (NOT RUN); (b) run PHP suites + `php -l` where PHP is available; (c) theme-side fallback wiring
   so the public course page reads run data for `_course_teacher` / `_course_next_start_date` /
   `_course_price` / `_course_registration_state` (currently the meta is still the only display
   source — no dual *entry*, but no fallback *read* yet); (d) close Phase 2A behavioural acceptance
   first.
2. **Phase 2C — student identity & security.** 🟡 **Foundation slice done** on
   `feature/phase-2b-academic-operations`: address/city/postal-code profile fields in usermeta
   with an extensible registry + server-side normalization (`Hedayati_Student_Profile`).
   **Still blocked on institute policy** (`docs/OPEN_QUESTIONS.md` Q10–Q13): verification workflow
   and states (reset rules + benefit linkage); `HEDAYATI_DATA_ENCRYPTION_KEY` + key versioning +
   separate HMAC provisioned outside Git **before** any national ID; private document
   upload/storage-outside-webroot/authorized streaming/lifecycle; the append-only audit log's
   IP/UA fields + retention. A metadata-only audit log (no IP/UA) is unblocked and is the
   recommended next step.
3. **Phase 2D — interfaces** (built on proven backend services, not mock data): branded
   login/registration/password-reset; student portal (profile, verification status, documents,
   enrollments/sessions); teacher & TA portal (assigned runs/rosters/sessions/attendance);
   reception panel; manager/admin operational dashboards; audit-log viewer.
4. **Public content & pages.** Create About, Contact, and consultation pages (templates + a
   consultation submission handler ❓ UX undecided); teacher directory/profiles; editable
   homepage/footer/navigation content where staff editing is required. Note: `header.php`,
   `footer.php`, `menu-fallbacks.php`, `hero-navigator.php`, `impact-section.php`, `cta-band.php`
   already link to `/about/`, `/contact/`, `/consult/`.
5. **Self-host Vazirmatn.** Add approved WOFF2 weights (400/500/600/700/800), enqueue in
   `functions.php`, `font-display: swap`, no CDN. The CSS/`theme.json` stack already names it.
6. **Shamsi date layer.** Jalali input + display over the Gregorian ISO storage, consistently
   across all new interfaces; plus Persian/Arabic → ASCII digit normalization for national ID and
   other searchable numeric fields.
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
  are mandatory? Retention period for documents and for IP/user-agent audit data?
- Consultation page: form fields, where submissions go (email? CRM? WP admin?), spam handling.
- Impact-section statistics: the actual numbers, and Customizer vs plugin-settings as the editor.
- Course category default ordering behavior.
- SMS provider (for future OTP/notifications).
- Payment: needed for launch or not.
- Cutover timing and the legacy-content scope to migrate vs redirect vs drop.
- Fate of the stale repo artifacts (`package-plugin/`, root zip, stray diff file).
