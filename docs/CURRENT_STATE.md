# CURRENT_STATE.md

**Last documentation update:** 2026-09-03
**Method:** direct inspection of the repository, reconciled against `docs/HANDOFF_LEGACY.md`.
Phase 2B + the Phase 2C address slice were implemented 2026-09-02/03 on
`feature/phase-2b-academic-operations`.
Phase 2A staging acceptance (`docs/PHASE_2A_ACCEPTANCE.md`): the static + read-only-DB layer on
`mystik.ir` is verified; runtime behaviour is **not** yet tested. Phase 2B (below) is implemented
on branch `feature/phase-2b-academic-operations` — repository + Node static tests (Claude) + an
independent `php -l` / PHP-suite run on PHP 8.4 (see the Tests section); its **staging/runtime**
acceptance (`docs/PHASE_2B_ACCEPTANCE.md`) is still NOT RUN.
**Repo versions (`feature/phase-2b-academic-operations`):** theme `hedayati` 1.0.0 · plugin
`hedayati-core` **1.5.0** · DB schema **2.2.0** · roles schema **2.1.0**.
**`main` versions:** plugin `1.1.0` · DB & roles `2.0.0` (nothing from this branch is merged).

> The repository is authoritative for "what is implemented". It contains **code only** — no
> WordPress core, no database, no content. Anything requiring a running WordPress instance
> (deployment status, the CCNA example course, migration execution, real login behavior) **cannot
> be verified from this repository** and is marked accordingly.

---

## ✅ Verified implemented (present and complete in the repository)

### Plugin — course catalog (Phase 1 / 1.5)

- **`course` custom post type** (`class-post-types.php`): public, `show_in_rest` true (block
  editor), archive at `/courses/`, single at `/course/{slug}`, `with_front` false, supports
  title/editor/excerpt/thumbnail/custom-fields/page-attributes/revisions, `delete_with_user` false.
- **`course-category` taxonomy** (`class-taxonomies.php`): hierarchical, public, `show_in_rest`
  true, admin column, rewrite `/course-category/...`.
- **Course post meta** (`class-course-meta.php`): 13 registered meta keys (see `docs/DATA_MODEL.md`),
  all `show_in_rest` false, `auth_callback` = `current_user_can('edit_post', $id)`. Sanitizers:
  string-array (strips tags, drops empties), strict ISO-date with `checkdate()`, registration-state
  allowlist (`open`/`closed`/`soon`, else `soon`).
- **Course meta box** (`class-meta-box.php`): Persian authoring UI — identity/schedule fields,
  featured checkbox, registration state, ISO date picker, `menu_order` (display priority) editing,
  and three accessible repeaters (syllabus, target audience, learning outcomes). Nonce +
  `edit_post` capability + autosave + post-type guards on save.
- **Term meta** (`class-term-meta.php`): `course_cat_english`, `course_cat_icon` (plain text,
  8-char cap, tags stripped), `course_cat_order` (`absint`). Add/edit form fields, save with nonce
  + `manage_categories`, custom admin columns.
- **Query helpers** (`class-query-helpers.php`, class `Hedayati_Query`):
  `get_featured_courses()` (meta `_course_is_featured` = `1`, order `menu_order ASC, date DESC`,
  hard cap 8), `get_courses_by_category()`, `get_nav_categories()` (top-level terms, PHP-sorted by
  `course_cat_order` then name), `get_related_courses()` (shared category terms, cap 3; **returns
  0 results when the course has no category terms**).
- **Institute settings** (`class-settings.php`, class `Hedayati_Settings`): Settings → Hedayati
  page, option `hedayati_institute_settings` with `phone_consult`, `phone_tabriz`, `phone_tehran`,
  `address_tabriz`. Phone sanitizer keeps `\d \s + - ( ) . # ,`. Accessors `get()` and `tel_uri()`.
- **Admin assets** (`assets/css/admin.css`, `assets/js/admin.js`): enqueued only on
  `post.php` / `post-new.php` for the `course` type; JS drives the accessible repeaters
  (move up/down/add/remove with focus management).
- **Shared helper** `hedayati_phone_to_tel_uri()` in `hedayati-core.php`.
- **Activation hook** registers post types + taxonomies, runs `Hedayati_DB_Schema::migrate()` and
  `Hedayati_Roles::register_roles()`, flushes rewrite rules. Deactivation flushes rewrite rules.

### Plugin — identity foundation (Phase 2A)

- **Phone normalization** (`class-phone.php`, class `Hedayati_Phone`): canonical E.164
  `^\+989[0-9]{9}$`. Transliterates Persian (`۰-۹`) and Arabic-Indic (`٠-٩`) digits. Accepts
  `09…`, `9…`(10 digits), `+989…`, `00989…`, `989…`, with spaces/hyphens/parens/dots. Rejects
  (does not strip) letters, markup, underscores, misplaced/multiple `+`, wrong lengths.
  `looks_like_iranian_phone()` heuristic; `format_display()` national/spaced/international.
- **Migration framework** (`class-db-schema.php`, class `Hedayati_DB_Schema`): `CURRENT_DB_VERSION`
  `2.0.0`, version stored in option `hedayati_core_db_version` **only on verified success**, atomic
  `add_option()` lock (`hedayati_db_migration_lock`) with 60s stale-lock recovery, `admin_init`
  trigger via `maybe_migrate()`. Migration `2.0.0` creates `{prefix}hedayati_user_phones` via
  `dbDelta` and confirms it exists with `SHOW TABLES LIKE` before advancing.
- **Phone identity service** (`class-user-phone-service.php`): prepared `$wpdb` CRUD on the phone
  table. `find_user_by_phone`, `get_user_id_by_phone`, `get_phone_record_by_user`,
  `is_phone_available`, `assign_phone`, `update_phone` (changing the number **always** resets
  `is_verified`/`verified_at`; unchanged number is a no-op), `delete_phone`, `verify_phone`.
  DB-constraint race converted to `phone_already_exists`. `deleted_user` hook → `delete_phone`.
- **Roles & capabilities** (`class-roles.php`, class `Hedayati_Roles`): `ROLES_VERSION` `2.0.0`,
  `admin_init` sync. Roles `student`, `teacher_assistant`, `teacher`, `reception`,
  `hedayati_manager` + native `administrator` augmented with all Hedayati caps. **21**
  `hedayati_*` capabilities as shipped in Phase 2A (`ROLES_VERSION` `2.0.0`); Phase 2B raises this
  to **22** (`ROLES_VERSION` `2.1.0`, adds `hedayati_manage_teachers` — see the Phase 2B section).
  Future-safe cleanup: tracks `hedayati_core_managed_capabilities`, removes only its own
  obsolete/unassigned caps, never touches core/third-party caps.
- **Rate limiter** (`class-rate-limiter.php`): transient buckets. Defaults 5 fails/identifier,
  30/IP, 900s window — filterable via `hedayati_rate_limit_config`. Identifier canonicalized
  (phone → E.164, else lowercased). Keys are SHA-256-truncated. `get_client_ip()` uses
  `REMOTE_ADDR` only, validated.
- **Auth adapter** (`class-auth.php`, class `Hedayati_Auth`): `authenticate` filter at **priority
  30** (phone → resolve user → `wp_authenticate_username_password()` with the real `user_login`);
  **priority 90** late rate-limit enforcement that can override success. Single failure-count path
  via `wp_login_failed`. On `wp_login`, clears the identifier bucket for the username and the
  registered phone — **never** the shared IP bucket. Generic identical error for unknown
  phone / wrong password (no user enumeration).

### Plugin — academic operations (Phase 2B) — branch `feature/phase-2b-academic-operations`

> Repository + Node-suite verified only. **No staging/runtime verification** — see
> `docs/PHASE_2B_ACCEPTANCE.md`. Not on `main`.

- **`teacher` CPT** (`class-teacher.php`): admin-only (`public` / `publicly_queryable` / `show_in_rest`
  all false — D30/D34, classic editor),
  `supports` title/editor/thumbnail/revisions, caps mapped to `hedayati_manage_teachers`. Meta:
  `_hedayati_teacher_user_id` (optional 1:1 WP-user link, uniqueness enforced in the save handler),
  `_hedayati_teacher_headline`. Side meta box (nonce + `edit_post` + autosave guards). `deleted_user`
  → **unlinks** (never deletes) the profile. Query helpers `exists()`, `get_user_id()`,
  `find_by_user_id()`.
- **Migration `2.1.0`** (`class-db-schema.php::migrate_2_1_0`): `dbDelta` creates
  `hedayati_course_runs`, `hedayati_run_staff`, `hedayati_sessions`, `hedayati_enrollments`,
  `hedayati_attendance` (columns/keys in `docs/DATA_MODEL.md`), then confirms **all five** with
  `SHOW TABLES LIKE` before advancing the version. Additive — does not touch `hedayati_user_phones`.
  `CURRENT_DB_VERSION` `2.1.0`; five `get_table_*()` accessors added.
- **`Hedayati_Text`** (`class-text.php`): shared `digits_to_ascii()` (canonical/searchable) +
  `digits_to_persian()` (display only). `Hedayati_Phone` keeps its own inline map (verified 2A code).
- **`Hedayati_Jalali`** (`class-jalali.php`): Shamsi UI layer over Gregorian storage (D9) —
  `from_gregorian()` / `to_gregorian()` (standard integer algorithm), `is_leap_year()`,
  `format()` / `format_long()` (stored ISO → Shamsi label; Persian digits optional; **time part
  copied verbatim**, Q9), `parse_input()` (Shamsi text → canonical `Y-m-d`, round-trip guarded).
  **No storage-format change.** Wired into the «عملیات آموزشی» screens — every date/datetime shows
  the Gregorian value **plus** the Shamsi equivalent (parentheses / field hint); machine-readable
  Gregorian retained; graceful fallback for an unparseable value.
- **`Hedayati_Academic_Validation`** (`class-academic-validation.php`): the approved business-state
  vocabularies as `const` arrays (validated strings, no ENUM), safe-fallback sanitizers, strict
  `parse_iso_date()` / `parse_datetime()` (canonical `Y-m-d H:i:s`, `checkdate()`), and
  `parse_optional_nonneg_int()` (empty ⇒ `null` = unknown; negative/non-numeric ⇒ `WP_Error`) /
  `parse_positive_int()`.
- **Services** (`class-*-service.php`, static, prepared SQL, capability-agnostic data layer):
  - `Hedayati_Course_Run_Service` — CRUD + `query()` + `count_for_course()`; cross-field date
    validation; `before_delete_post` (course) → `delete_run()` **cascade** (sessions, enrollments,
    attendance, staff).
  - `Hedayati_Run_Staff_Service` — `assign()` / `remove()`; instructor rows need a Teacher profile,
    assistant rows need a WP user (D11 asymmetry); one `primary_instructor` per run; duplicate
    guard; `user_is_staff_on_run()` / `run_ids_for_user()` scope helpers; `deleted_user` +
    `before_delete_post` (teacher) cleanup.
  - `Hedayati_Session_Service` — CRUD; `UNIQUE(run_id, session_number)` (service pre-check + DB);
    `next_session_number()`; datetime canonicalization; cascade attendance on delete.
  - `Hedayati_Enrollment_Service` — `enroll()` (duplicate + closed-run + capacity checks, capacity
    overridable), `set_status()`, `delete_enrollment()` (cascade attendance), `count_active()`;
    `deleted_user` → cascade.
  - `Hedayati_Attendance_Service` — `record()` upsert + `record_bulk()`; **same-run guard**
    (enrollment.run_id must equal session.run_id); `UNIQUE(session_id, enrollment_id)`;
    `deleted_user` → null `recorded_by` (row kept).
- **Admin UI** (`class-academic-admin.php`): top-level menu «عملیات آموزشی» (cap
  `hedayati_manage_course_runs`). Views: runs list (+ create), run detail (details form + staff +
  sessions + enrollments), per-session attendance grid. Every state change routes through
  `admin-post.php` with a per-action nonce, a server-side capability check, and a per-run access
  scope check (`require_run_scope()` — managers/admins bypass, other staff limited to their runs).
  Attendance writes gated on `hedayati_record_attendance` (managers see it read-only). Persian
  labels; core WP admin markup; transient-backed notices. Also: a read-only "دوره‌های اجرایی این
  دوره" side box on the `course` edit screen (links each run to the academic screen), and
  headline / linked-account columns on the Teacher list table.
- **Roles schema `2.1.0`** (`class-roles.php`): adds `hedayati_manage_teachers` (22nd managed
  capability) to `hedayati_manager` + `administrator`; future-safe sync removes nothing.
- **Audit log** (`class-audit-log.php` + migration `2.2.0`, `CURRENT_DB_VERSION` `2.2.0`):
  `{prefix}hedayati_audit_log` — `actor_id`, `action`, `object_type`, `object_id`, `note`,
  `created_at`. **No ip / user-agent / updated_at / serialized context** (D33; IP/UA retention is
  Q13). `Hedayati_Audit_Log::record()` (INSERT only, re-entrancy guard, PII-free `note`) is called
  on the **success** path of every Phase 2B mutation (create / update / delete / assign / remove /
  status / recorded) and the deletion-cleanup hooks; **never** on failure, **never** inside a
  cascade (audit history outlives its objects). Read helpers `get()`/`query()`/`count()` +
  `current_user_can_view()`. **No update/delete method** — append-only at the API. Minimal
  read-only viewer: «عملیات آموزشی → گزارش رویدادها» (`hedayati_view_audit_logs`, GET-only,
  filters validated against the vocabularies, paginated).
- **Tests — CLAUDE-EXECUTED (Node):** `verify-phase2a.js` **74/74** · `verify-phase2b.js` **171/171**
  · `verify-phase2c.js` **25/25** · `verify-audit-log.js` **98/98** · `verify-jalali.js` **36/36**
  (404 assertions, 0 failed). The Claude dev environment has **no PHP** — it cannot run `php` or
  `php -l`.
- **Tests — INDEPENDENTLY EXECUTED (external inspection, PHP 8.4, 2026-09-03):**
  - `php -l` on **all 56 PHP files in the repo → all pass** (syntax/parse only — *not* WordPress
    runtime verification).
  - `php test-phase2a.php` → **77/78**, sole failure the stale `CURRENT_DB_VERSION === '2.0.0'`
    assertion; **fixed** this session (now `version_compare(>=, '2.0.0')`).
  - `php test-phase2b.php` → **112/113**, sole failure the stale exact-`2.1.0` assertion; **fixed**
    (now `version_compare(>=, '2.1.0')`, migrate_2_1_0 + Phase-2A-preservation still asserted).
  - `php test-jalali.php` → **35/35** (repository-level PHP verification of the Shamsi layer).
  - `php test-audit-log.php` → **harness defects** (no `ext-mbstring`; DDL assertions scanned the
    whole schema file); **fixed** this session — UTF-8 mb_* test shim + the no-ip/ua/updated_at
    checks now isolate the `migrate_2_2_0` CREATE TABLE only. Re-run pending on a PHP host.
  - **`php -l` and all four PHP suites remain NOT re-executed by Claude** — no PHP here.

### Plugin — student profile (Phase 2C foundation — address only) — same branch

> Repository + Node-suite verified only. The rest of Phase 2C is **blocked on institute policy** —
> see `docs/OPEN_QUESTIONS.md` Q10–Q13.

- **`Hedayati_Student_Profile`** (`class-student-profile.php`): `hedayati_address`,
  `hedayati_city`, `hedayati_postal_code` in `wp_usermeta` (no table, no migration). Fields come
  from a filterable registry (`hedayati_student_profile_fields`). Postal code is
  digit-normalized via `Hedayati_Text` and must be exactly 10 digits or empty
  (`user_profile_update_errors` blocks the save otherwise). Admin fields render on the WordPress
  user-edit screen; self-edit needs `hedayati_edit_own_profile`, other-user access needs
  `hedayati_view_student_profiles_basic` + core `edit_user`. Read API
  `Hedayati_Student_Profile::get( $user_id )`. `HEDAYATI_CORE_VERSION` → `1.3.0`.
- **Deliberately not built:** national ID (needs the D15 encryption key), verification state
  machine (reset rules undecided), private documents (storage/retention undecided), audit log
  (IP/UA retention undecided). Each is documented as a block, not a TODO.
- **Tests:** `tests/verify-phase2c.js` — **25 passed, 0 failed**.

### Plugin — tests

- `tests/verify-phase2a.js` — Node static/structural suite. **Ran during this update: 74 passed,
  0 failed.**
- `tests/test-phase2a.php` — pure-PHP logic suite with a mocked WP environment (phone
  normalization, rejection cases, heuristics, display formats, rate-limiter canonicalization/
  thresholds/clearing, role-capability mapping, least-privilege assertions, migration constants).
  Handoff reports **78 passed, 0 failed**; **not re-run here** (PHP not available in this
  environment).

### Theme — public site

- **Bootstrap** (`functions.php`): theme supports (title-tag, post-thumbnails + `course-card`
  560×320 and `course-hero` 1200×600 sizes, html5, align-wide, custom-logo, responsive-embeds),
  nav menus `primary` + `footer`, `content_width` 1240. Enqueues `assets/css/main.css`,
  `assets/css/rtl.css`, `assets/js/main.js` (deferred, footer). Body classes `hd-site`,
  `hd-single-course`, `hd-course-archive`. Inline no-flash dark-mode script in `wp_head`
  (priority 1). Template helpers: `hedayati_registration_state_display()`,
  `hedayati_course_monogram()` (first word of English name up to 4 chars, else 2 chars of Persian
  title), `hedayati_course_card_classes()`, `hedayati_core_active()`.
- **`header.php`** — sticky header, custom-logo (SVG "H" fallback), primary nav with
  `hedayati_primary_menu_fallback`, "مشاوره ثبت‌نام" button, dark-mode toggle, mobile hamburger.
- **`footer.php`** — brand, quick links, departments from `Hedayati_Query::get_nav_categories(5)`,
  contact block from `Hedayati_Settings` (each line rendered only if set; admin-only placeholder
  when empty), copyright.
- **`front-page.php`** — sections in order: `hero-navigator`, `category-strip`, `featured-courses`,
  `impact-section`, `cta-band`.
- **`archive-course.php`** — page hero + breadcrumb, category filter chips, responsive course grid,
  `the_posts_pagination`, empty state. **`taxonomy-course-category.php`** delegates to it via
  `require`.
- **`single-course.php`** — breadcrumb, hero (tags, title, excerpt, state-aware CTA, consult
  phone, thumbnail or monogram art), quick-facts bar (only non-empty facts render), main column
  (Gutenberg content, syllabus, outcomes, audience, prerequisites — each section renders only if
  populated), sticky enrollment sidebar, related-courses grid (only if results).
- **`index.php`, `singular.php`, `archive.php`** — safe generic fallbacks. **`404.php`** — branded
  Persian not-found page.
- **`template-parts/`** — `hero-navigator` (two-column Navigator hero + department console grid,
  max 4 terms, inline-SVG icons keyed by slug, admin empty state), `category-strip` (up to 8
  terms, icon from `course_cat_icon` or first char, renders nothing if no terms),
  `featured-courses` (up to 8, admin-only hint when none), `course-card` (fully data-driven),
  `impact-section` (dark editorial band — **stat numbers intentionally omitted**),
  `cta-band` (consult phone from settings).
- **`theme.json`** v3 — brand color palette (10 named colors, default palette off), font families
  (`vazirmatn` stack, `mono`), 6 font sizes (clamp-based hero/x-large), geometric spacing scale,
  `contentSize` 780px / `wideSize` 1240px, element styles for links and h1–h3.
- **`assets/css/main.css`** (~2400 lines) — full design system: light + dark tokens via
  `[data-theme="dark"]`, custom thin scrollbar (Firefox + webkit), base reset, typography,
  buttons, header/nav (incl. mobile fixed panel), all homepage sections, course cards, single
  course page, archive/filters, 404, responsive breakpoints (1024 / 900 / 768 / 420), and a
  `prefers-reduced-motion` block.
- **`assets/css/rtl.css`** — small targeted RTL corrections (admin bar, pagination, embeds,
  sub-menu side, bidi isolation for English/number/phone fragments).
- **`assets/js/main.js`** — vanilla, no jQuery: theme toggle (writes `localStorage` only on
  explicit user action; follows OS scheme otherwise), accessible mobile nav (Escape, outside
  click, focus management, resize auto-close), sticky-header `scrolled` class.

---

## 🟡 Partially implemented

| Area | What exists | What is missing |
|---|---|---|
| **Username-or-phone login** | Full backend adapter, normalization, rate limiting, roles — extends the standard `wp-login.php` pipeline; deployed code + DB schema + roles/caps **verified on staging 2026-09-02** | No custom/branded login form or account UI; **runtime behaviour not yet acceptance-tested** (Category 2–4 of `docs/PHASE_2A_ACCEPTANCE.md`) |
| **Roles & capabilities** | 5 roles + **22** caps registered; least-privilege verified in unit tests. Phase 2B consumes `hedayati_manage_course_runs`, `hedayati_manage_teachers`, `hedayati_assign_staff`, `hedayati_create_enrollments`, `hedayati_manage_enrollments`, `hedayati_record_attendance` | `hedayati_verify_students`, `hedayati_view_private_documents`, `hedayati_view_audit_logs`, `hedayati_initiate_verification`, `hedayati_view_own_*`, teacher/TA `view_assigned_*` still unused (Phase 2C/2D) |
| **Student accounts** | WordPress user + `student` role + phone-identity table + address profile fields (usermeta) + enrollments (Phase 2B) | No portal UI, no verification state, no national ID, no document upload (all blocked — Q10–Q13) |
| **Homepage impact/value section** | Dark editorial band with 4 institutional bullet points and copy | Stat numbers (years, graduates, …) intentionally omitted pending verified data + an input mechanism (Customizer or plugin settings) — **neither mechanism is coded** |
| **Contact / consultation** | Phone/address settings, footer + CTA rendering, links to `/consult/` | The `/consult/`, `/contact/`, `/about/` pages do not exist; no consultation form or submission handler |
| **Course commerce fields** | `_course_price` as a display string; state as `open`/`closed`/`soon`. Phase 2B adds `Course Run` with integer-rial `tuition_rial` (nullable) | No payment; the theme does not yet read run tuition / dates as fallbacks (Phase 2D) |

---

## ⬜ Planned / not implemented (no code in the repository)

- Custom login / registration / password-reset UI.
- Student profile storage (address, national ID, extensible fields), verification workflow and
  states, private-document upload/storage/streaming, document lifecycle.
- Public teacher directory/profiles (the `teacher` CPT exists but is not publicly routed — D30).
- Theme-side consumption of Course Run data (run tuition/dates/registration as fallbacks for the
  `_course_*` meta on the public course page).
- Staff interfaces beyond the manager-facing «عملیات آموزشی» screen: reception panel, scoped
  teacher/TA portal (incl. teacher-facing attendance), audit-log viewer.
- Audit-log IP/user-agent capture + a retention policy (Q13); operational consumption of the log
  (alerts, reports). The metadata-only append-only log itself is **built** (see Phase 2B above).
- Dedicated `HEDAYATI_DATA_ENCRYPTION_KEY` + key versioning + HMAC for reversible national-ID
  storage and duplicate detection.
- Self-hosted **Vazirmatn** WOFF2 fonts — `functions.php` deliberately does **not** enqueue a
  font; no font files exist in the repo; the CSS stack falls back to system Persian fonts.
- Shamsi (Jalali) date input/display layer over Gregorian storage.
- Persian/Arabic → ASCII digit normalization for fields other than phone (e.g. national ID).
- SMS / OTP provider integration (provider abstraction, provider unknown).
- Homepage/footer/navigation content settings beyond the current fixed structure.
- Blog / articles and migration of legacy-site content, URLs, and SEO; redirect map.
- CI, PHP linting config (`phpcs`), `.editorconfig`, `composer.json` — none present.

---

## ✅ Verified on staging (`mystik.ir`) — 2026-09-02

From the Phase 2A staging acceptance process (`docs/PHASE_2A_ACCEPTANCE.md`); these items are no
longer "handoff-only":

- **Deployed code matches the repository.** The `hedayati-core` plugin (18 files) and `hedayati`
  theme (23 files) on `mystik.ir` are byte-identical to `main` after line-ending normalization —
  0 different, 0 staging-only, 0 repo-only, 0 junk (T1.3).
- **Deployed versions:** plugin `1.1.0`, theme `1.0.0` (T1.1–T1.2).
- **Migration recorded success:** `hedayati_core_db_version` = `2.0.0`,
  `hedayati_core_roles_version` = `2.0.0`, migration lock absent,
  `hedayati_core_managed_capabilities` = a 21-element serialized array (T3.1).
- **`{prefix}hedayati_user_phones` exists** (non-`wp_` prefix) with the **exact** Phase 2A schema:
  7 columns, `InnoDB`, `utf8mb4`, `PRIMARY KEY (id)`, `UNIQUE uq_user_id`, `UNIQUE uq_phone_e164`,
  `KEY idx_is_verified`; created 2026-09-01; **0 rows**, no duplicates/orphans (T3.2, T3.2b, T3.3).
- **No hardcoded `wp_` prefix** in the plugin (only the `wp_login` / `wp_login_failed` hook names);
  the phone table is addressed via `$wpdb->prefix` (T3.4).
- **5 custom roles installed and selectable** in the wp-admin role dropdown (T1.4), and all 21
  `hedayati_*` capabilities present in `{prefix}user_roles` (option ~4× stock size; all role slugs
  + all 21 cap names present) (T3.5).
- **Administrator retains full access:** functionally reaches Settings / Plugins / Users (T1.5),
  the auth filter chain (`authenticate` @30/@90) does not break normal admin login, and the 21
  `hedayati_*` caps are installed consistently with administrator holding all of them (T3.6). The
  exact per-role capability *matrix* and least-privilege *negatives* are not yet positionally
  enumerated (T3.5 — NEEDS REVIEW).
- **Rate limiter is live and DB-backed:** 19 `hd_rl_*` counters currently in `wp_options` from
  ordinary `wp-login.php` failures; no persistent object cache is active, so rate-limit state is
  DB-visible (relevant to the not-yet-run behavioural tests).
- **Active theme** confirmed `hedayati` from the DB side; `hedayati-core` plugin active.

**Still not verified on staging:** any runtime *behaviour* — real username/phone login, phone
normalization end-to-end, uniqueness enforcement under a real insert, rate-limit thresholds /
reset / no-double-count, role least-privilege in use, phone assign/change/verify/delete lifecycle,
and user-deletion cleanup. These require the Category 2–4 state-changing tests.

---

## ❓ Uncertain — requires verification against a running environment or the institute

- **Phase 2A runtime behaviour** — see "Still not verified on staging" above.
- **The CCNA example course** and any other content — database content, not in the repo; not
  inspected.
- **PHP test suite result (78/78)** — reported by the handoff; PHP is unavailable in this
  environment, so only the Node suite (74/74) was re-confirmed.
- **Exact role → capability matrix + least-privilege negatives** on staging (T3.5 NEEDS REVIEW —
  pending `wp cap list` per role, or the T2.7 wp-admin negative checks).
- **LiteSpeed *page* cache behavior** after deploys (no persistent *object* cache is active).
- **Custom logo** — whether a real logo image has been uploaded in WP (theme supports it; SVG "H"
  is the fallback).

---

## Repository artifacts

- **Removed 2026-09-03** (D27, owner-approved, commit on this branch): `package-plugin/`
  (stale `1.0.0` Phase-1 subset) and the root `drhedayati-wordpress` git-diff dump. The stale
  gitignored ZIPs (`./hedayati-core.zip`, `plugin/hedayati-core.zip`, old `staging-export/*.zip` —
  all `1.1.0`) were deleted from the working tree.
- **Release artifacts** are produced only by `scripts/build-packages.ps1` from
  `plugin/hedayati-core/` + `theme/hedayati/`, into `staging-export/hedayati-core.zip` /
  `staging-export/hedayati.zip`. The script verifies archive layout and that the version inside the
  plugin ZIP matches canonical source. ZIPs stay gitignored (`.gitignore` `*.zip`).
- `.gitignore` also excludes `node_modules/`, `vendor/`, `.env*`, build dirs, uploads, logs.
- `reference-react/` — design prototype, visual reference only (never wired into production).

---

## Known issues / technical debt (see also handoff §21)

- `course_cat_order` defaults to `0`, so un-ordered categories can float ahead of ordered ones.
- `Hedayati_Query::get_related_courses()` docblock still says it "falls back to published courses"
  — the code correctly returns **no** results when there are no category terms; the comment is
  stale.
- `hedayati_course_monogram()` takes up to 4 characters of the English name; the handoff's prose
  says "first 3" — doc drift, code is the reference.
- Featured-course secondary sort is `date DESC`; handoff §6.3 says "then title" — minor drift.
- Migration lock is a plain option with a 60s timeout — adequate for the tiny Phase 2A table, but
  longer future migrations will need stronger locking / ownership tokens.
- `admin_init`-only migration trigger means a plugin file replacement needs an admin page view to
  apply pending migrations — every deploy needs an explicit post-deploy migration check.
- CSS was only brace-balance checked in a prior polish pass, not run through stylelint.
