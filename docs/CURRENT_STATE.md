# CURRENT_STATE.md

**Last documentation update:** 2026-09-02
**Method:** direct inspection of the repository at branch `main`, commit `a51237d`, reconciled
against `docs/HANDOFF_LEGACY.md`.
**Repo versions:** theme `hedayati` 1.0.0 · plugin `hedayati-core` 1.1.0 · DB schema & roles `2.0.0`.

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
  `hedayati_manager` + native `administrator` augmented with all Hedayati caps. Exactly **21**
  `hedayati_*` capabilities. Future-safe cleanup: tracks `hedayati_core_managed_capabilities`,
  removes only its own obsolete/unassigned caps, never touches core/third-party caps.
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
| **Username-or-phone login** | Full backend adapter, normalization, rate limiting, roles — extends the standard `wp-login.php` pipeline | No custom/branded login form or account UI; **no staging integration acceptance** (see handoff §2.2 matrix) |
| **Roles & capabilities** | 5 roles + 21 caps registered; least-privilege verified in unit tests | No UI or services consume the operational caps yet (`hedayati_manage_course_runs`, `hedayati_verify_students`, `hedayati_view_private_documents`, etc. are defined but unused) |
| **Student accounts** | WordPress user + `student` role + phone-identity table + `hedayati_view_own_portal` etc. | No profile fields, no portal, no enrollment view, no document upload |
| **Homepage impact/value section** | Dark editorial band with 4 institutional bullet points and copy | Stat numbers (years, graduates, …) intentionally omitted pending verified data + an input mechanism (Customizer or plugin settings) — **neither mechanism is coded** |
| **Contact / consultation** | Phone/address settings, footer + CTA rendering, links to `/consult/` | The `/consult/`, `/contact/`, `/about/` pages do not exist; no consultation form or submission handler |
| **Course commerce fields** | `_course_price` as a display string; state as `open`/`closed`/`soon` | No integer-rial tuition, no payment, no Course Run model |

---

## ⬜ Planned / not implemented (no code in the repository)

- Custom login / registration / password-reset UI.
- Student profile storage (address, national ID, extensible fields), verification workflow and
  states, private-document upload/storage/streaming, document lifecycle.
- Teacher custom post type and WP-user linkage; public teacher directory/profiles.
- **Course Runs** (operational cohorts), sessions, enrollments, attendance, rosters — and their
  migrations/services/capability scoping.
- Staff interfaces: reception panel, teacher/TA portal, manager/admin operational dashboards,
  audit-log viewer.
- Application-level append-only audit logging.
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

## ❓ Uncertain — requires verification against a running environment or the institute

- **Phase 2A deployment & migration on staging.** The handoff states the plugin is deployed to
  `mystik.ir`, that the migration ran from an admin request, that
  `{prefix}hedayati_user_phones` exists (staging uses a non-`wp_` randomized prefix), and that
  `hedayati_core_db_version` / `hedayati_core_roles_version` / `hedayati_core_managed_capabilities`
  options were written. **None of this is verifiable from the repository.**
- **The CCNA example course** and any other content — database content, not in the repo.
- **PHP test suite result (78/78)** — reported by the handoff; PHP is unavailable in this
  environment, so only the Node suite (74/74) was re-confirmed.
- **Whether the deployed artifact exactly matches this repo** — no tags; the handoff flags a risk
  of server-only hotfixes diverging from Git.
- **LiteSpeed cache behavior** after deploys.
- **Custom logo** — whether a real logo image has been uploaded in WP (theme supports it; SVG "H"
  is the fallback).

---

## Repository artifacts that are not part of either deliverable

- `package-plugin/hedayati-core/` — a **stale pre-Phase-2A copy** of the plugin (no
  `class-auth`, `class-phone`, `class-roles`, `class-db-schema`, `class-user-phone-service`,
  `class-rate-limiter`, no `tests/`; other files differ). Not referenced by the handoff.
- `hedayati-core.zip` (repo root) — a build artifact; untracked (matched by `.gitignore` `*.zip`).
- `drhedayati-wordpress` (repo root, no extension) — a 62-line git-diff dump accidentally committed
  in `a51237d` ("checkpoint before Claude Code migration").
- `.gitignore` already excludes `*.zip`, `node_modules/`, `vendor/`, `.env*`, build dirs, uploads,
  logs.

These are noted for awareness only. Do not build from them; do not delete them without asking.

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
