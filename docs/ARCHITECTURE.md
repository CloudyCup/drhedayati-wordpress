# ARCHITECTURE.md

## Overview

```
WordPress core (users, passwords, sessions, email, posts/pages/media/menus, REST, Settings API)
├── theme  hedayati/         → presentation only (templates, CSS, JS, design tokens)
└── plugin hedayati-core/     → all persistent domain behavior and data
    ├── Course CPT + course-category taxonomy + term/post meta
    ├── course query helpers + institute settings
    ├── Iranian phone normalization
    ├── phone-identity table + service (versioned migrations)
    ├── roles + 22 granular capabilities
    ├── authentication adapter (username OR phone + password) + rate limiter
    ├── Phase 2B — Teacher CPT + academic-operations tables (course runs, run staff,
    │   sessions, enrollments, attendance) + services + «عملیات آموزشی» admin UI
    ├── Phase 2B — metadata-only append-only audit log (+ read-only viewer)
    ├── Phase 2C (foundation) — student address fields in usermeta
    └── (future) Phase 2C: verification, private documents, national-ID encryption
```

**Principle:** WordPress authentication and content primitives stay authoritative. Business
behavior and business data live in the plugin so they survive a theme switch. The theme reads
plugin data only through stable public APIs (`Hedayati_Query::*`, `Hedayati_Settings::*`) and
degrades gracefully when the plugin is inactive (`hedayati_core_active()` /
`class_exists('Hedayati_Query')`).

**Requirements:** WordPress ≥ 6.6, PHP ≥ 8.3. All PHP files use `declare(strict_types=1)` and an
`if (!defined('ABSPATH')) exit;` guard. No autoloader — the plugin `require_once`s each class file
explicitly from `hedayati-core.php`.

---

## Plugin: `hedayati-core` (v1.5.3)

### Bootstrap (`hedayati-core.php`)

1. Defines `HEDAYATI_CORE_VERSION`, `HEDAYATI_CORE_DIR`, `HEDAYATI_CORE_URL`.
2. `require_once` for every `includes/class-*.php` (Phase 1 group, then Phase 2A group).
3. Hook registration (see table below).
4. `Hedayati_Settings::init()`, `Hedayati_Term_Meta::init()`, `Hedayati_DB_Schema::init()`,
   `Hedayati_User_Phone_Service::init()`, `Hedayati_Roles::init()`, `Hedayati_Auth::init()`;
   then the Phase 2B group: `Hedayati_Teacher::init()`, `Hedayati_Course_Run_Service::init()`,
   `Hedayati_Run_Staff_Service::init()`, `Hedayati_Session_Service::init()`,
   `Hedayati_Enrollment_Service::init()`, `Hedayati_Attendance_Service::init()`,
   `Hedayati_Academic_Admin::init()`; then `Hedayati_Student_Profile::init()`.
   (`Hedayati_Text`, `Hedayati_Jalali`, `Hedayati_Academic_Validation`, `Hedayati_Audit_Log` are pure static — required, not `init()`ed.)
5. Defines the shared helper `hedayati_phone_to_tel_uri()`.
6. `register_activation_hook`: register post types + taxonomies + Teacher CPT, run
   `Hedayati_DB_Schema::migrate()`, `Hedayati_Roles::register_roles()`, `flush_rewrite_rules()`.
   `register_deactivation_hook`: `flush_rewrite_rules()`.

### Classes

| File / class | Responsibility |
|---|---|
| `class-post-types.php` · `Hedayati_Post_Types` | Registers the `course` CPT |
| `class-taxonomies.php` · `Hedayati_Taxonomies` | Registers the hierarchical `course-category` taxonomy |
| `class-course-meta.php` · `Hedayati_Course_Meta` | `register_post_meta` for 13 course fields; sanitizers (string array, ISO date + `checkdate`, registration-state allowlist); `auth_callback` = `edit_post` on the object |
| `class-meta-box.php` · `Hedayati_Meta_Box` | Persian course-authoring meta box; renders fields + 3 repeaters; nonce/capability/autosave/post-type guarded save; edits `wp_posts.menu_order` via `wp_update_post` (hook temporarily removed to avoid recursion) |
| `class-term-meta.php` · `Hedayati_Term_Meta` | `register_term_meta` for `course_cat_english` / `course_cat_icon` / `course_cat_order`; add/edit UI; save with nonce + `manage_categories`; admin columns |
| `class-query-helpers.php` · `Hedayati_Query` | `get_featured_courses`, `get_courses_by_category`, `get_nav_categories` (PHP sort by term meta), `get_related_courses` (empty query when no shared terms) |
| `class-settings.php` · `Hedayati_Settings` | Settings → Hedayati page (Settings API); option `hedayati_institute_settings`; `sanitize_all` / `sanitize_phone`; `get()` / `tel_uri()` accessors |
| `class-phone.php` · `Hedayati_Phone` | `clean_and_transliterate`, `normalize` → E.164, `is_valid`, `looks_like_iranian_phone`, `format_display`. `CANONICAL_REGEX = /^\+989[0-9]{9}$/` |
| `class-db-schema.php` · `Hedayati_DB_Schema` | Versioned migration runner; atomic option lock + stale recovery; `admin_init` trigger; `migrate_2_0_0` creates `{prefix}hedayati_user_phones` via `dbDelta` and verifies existence; `get_table_user_phones()` |
| `class-user-phone-service.php` · `Hedayati_User_Phone_Service` | Prepared CRUD on the phone table; uniqueness/race handling; verification lifecycle; `deleted_user` cleanup |
| `class-roles.php` · `Hedayati_Roles` | Role definitions + capability sync; `get_all_hedayati_capabilities()` (**22**); future-safe cleanup via `hedayati_core_managed_capabilities`; `ROLES_VERSION` `2.1.0` |
| `class-rate-limiter.php` · `Hedayati_Rate_Limiter` | Transient buckets; identifier canonicalization; `hedayati_rate_limit_config` filter; `get_client_ip()` (`REMOTE_ADDR` only) |
| `class-auth.php` · `Hedayati_Auth` | `authenticate` filter @30 (phone adapter) and @90 (late rate-limit); `wp_login_failed` → single failure count; `wp_login` → clear identifier buckets |

### Phase 2B classes (Academic Operations)

| File / class | Responsibility |
|---|---|
| `class-text.php` · `Hedayati_Text` | Shared digit normalization for new code — `digits_to_ascii()` (canonical/searchable) and `digits_to_persian()` (**display only**) |
| `class-jalali.php` · `Hedayati_Jalali` | Shamsi UI layer over Gregorian storage (D9) — `from_gregorian()`/`to_gregorian()` (integer 33-year-cycle algorithm), `is_leap_year()`, `format()`/`format_long()` (stored ISO → Shamsi label; time copied verbatim, Q9), `parse_input()` (Shamsi text → canonical `Y-m-d`, round-trip guarded). Pure static; no storage change |
| `class-academic-validation.php` · `Hedayati_Academic_Validation` | Business-state vocabularies (validated strings, no ENUM) + strict date / datetime / integer parsing; pure functions |
| `class-teacher.php` · `Hedayati_Teacher` | `teacher` CPT (not publicly queryable), meta (`_hedayati_teacher_user_id` 1:1 link, `_hedayati_teacher_headline`), side meta box, `deleted_user` → unlink |
| `class-course-run-service.php` · `Hedayati_Course_Run_Service` | Prepared CRUD + validation for `hedayati_course_runs`; `query()` listing; `before_delete_post` (course) → cascade `delete_run()` |
| `class-run-staff-service.php` · `Hedayati_Run_Staff_Service` | `hedayati_run_staff` assign/remove; instructor↔Teacher / assistant↔user rules; one primary instructor; `user_is_staff_on_run()` scope helper; `deleted_user` / `before_delete_post` (teacher) cleanup |
| `class-session-service.php` · `Hedayati_Session_Service` | `hedayati_sessions` CRUD; `UNIQUE(run_id, session_number)`; datetime canonicalization; cascade attendance on delete |
| `class-enrollment-service.php` · `Hedayati_Enrollment_Service` | `hedayati_enrollments` enroll/status/delete; `UNIQUE(run_id, user_id)`; capacity enforcement (overridable); `deleted_user` → cascade |
| `class-attendance-service.php` · `Hedayati_Attendance_Service` | `hedayati_attendance` upsert (`record()` / `record_bulk()`); same-run guard; `UNIQUE(session_id, enrollment_id)`; `deleted_user` → null `recorded_by` |
| `class-audit-log.php` · `Hedayati_Audit_Log` | Metadata-only append-only audit log (migration `2.2.0`). `record()` (INSERT only; re-entrancy guard; token/note sanitization; actor from `get_current_user_id()`, 0 = system) + read helpers `get()`/`query()`/`count()` + `current_user_can_view()`. **No** update/delete method. No ip/user-agent (Q13). Filterable `action` / `object_type` vocabularies. Called on the success path of every Phase 2B mutation; never in a deletion cascade |
| `class-academic-admin.php` · `Hedayati_Academic_Admin` | «عملیات آموزشی» admin screen (list / run detail / attendance); `admin-post.php` handlers, per-action nonce + capability + per-run scope; Persian labels. Submenu «گزارش رویدادها» — read-only audit viewer (`hedayati_view_audit_logs`, GET-only, filters validated against the vocabularies, paginated) |

### Hook registration

| Hook | Callback | Notes |
|---|---|---|
| `init` | `Hedayati_Post_Types::register`, `Hedayati_Taxonomies::register`, `Hedayati_Course_Meta::register` | |
| `init` | `Hedayati_Term_Meta::register_meta` | via `Hedayati_Term_Meta::init()` |
| `add_meta_boxes` | `Hedayati_Meta_Box::register_boxes` | |
| `save_post_course` | `Hedayati_Meta_Box::save` (10, 2) | |
| `admin_enqueue_scripts` | `hedayati_core_admin_assets` | only `post.php`/`post-new.php` for `course` |
| `admin_menu` / `admin_init` | `Hedayati_Settings::add_page` / `register` | |
| `{tax}_add_form_fields` / `{tax}_edit_form_fields` / `created_{tax}` / `edited_{tax}` | `Hedayati_Term_Meta` render/save | |
| `manage_edit-course-category_columns` / `manage_course-category_custom_column` | `Hedayati_Term_Meta` columns | |
| `admin_init` | `Hedayati_DB_Schema::maybe_migrate`, `Hedayati_Roles::maybe_sync_roles` | version-gated |
| `deleted_user` | `Hedayati_User_Phone_Service::delete_phone` | |
| `authenticate` | `Hedayati_Auth::authenticate_phone` (30, 3), `Hedayati_Auth::enforce_rate_limit` (90, 3) | |
| `wp_login_failed` | `Hedayati_Auth::on_login_failed` | |
| `wp_login` | `Hedayati_Auth::on_login_success` (10, 2) | |

### WordPress APIs used

Custom post types & taxonomies · `register_post_meta` / `register_term_meta` · Settings API
(`register_setting`, `add_settings_section`, `add_settings_field`, `settings_fields`) · roles &
capabilities (`add_role`, `get_role`, `$role->add_cap`/`remove_cap`) · `$wpdb` + `$wpdb->prepare`
+ `$wpdb->prefix` + `get_charset_collate` + `dbDelta` · options API (`add_option` used atomically
for the migration lock) · transients (rate limiter) · `authenticate` filter chain +
`wp_authenticate_username_password` + `wp_login_failed` / `wp_login` · `WP_Query` · `current_time`.

### Persistent state written to `wp_options`

`hedayati_institute_settings`, `hedayati_core_db_version`, `hedayati_core_roles_version`,
`hedayati_core_managed_capabilities`, `hedayati_db_migration_lock` (transient-like, deleted after
migration).

### Custom database tables

`{$wpdb->prefix}hedayati_user_phones` (migration `2.0.0`); the five Phase 2B academic-operations
tables — `hedayati_course_runs`, `hedayati_run_staff`, `hedayati_sessions`, `hedayati_enrollments`,
`hedayati_attendance` (migration `2.1.0`); and `hedayati_audit_log` (migration `2.2.0`). See
`docs/DATA_MODEL.md` for columns and constraints. All addressed via
`Hedayati_DB_Schema::get_table_*()`.

---

## Theme: `hedayati` (v1.0.0)

Classic PHP theme using the WordPress template hierarchy plus a `theme.json` (v3) for editor tokens.
It is **not** a block theme and has **no** `templates/` or `parts/` HTML directory.

### `functions.php`

- `after_setup_theme` → `hedayati_setup()`: `title-tag`, `post-thumbnails` + image sizes
  `course-card` (560×320 hard crop) and `course-hero` (1200×600), `html5`, `align-wide`,
  `custom-logo`, `responsive-embeds`, nav menus `primary` + `footer`, `content_width` 1240,
  text domain `hedayati`.
- `wp_enqueue_scripts` → `hedayati_enqueue_assets()`: `assets/css/main.css`, then
  `assets/css/rtl.css` (depends on main), then `assets/js/main.js` (`defer`, in footer).
  Font loading is intentionally **not** wired up (see `docs/DESIGN_SYSTEM.md`).
- `body_class` filter → adds `hd-site`, `hd-single-course`, `hd-course-archive`.
- `wp_head` @1 → `hedayati_dark_mode_noflash()`: inline `try/catch` script that reads
  `localStorage['hedayati-theme']` (else `prefers-color-scheme`) and sets `data-theme` on `<html>`
  before first paint.
- Template helpers: `hedayati_registration_state_display()`, `hedayati_course_monogram()`,
  `hedayati_course_card_classes()`, `hedayati_core_active()`.
- `inc/menu-fallbacks.php` → `hedayati_primary_menu_fallback()`.

### Template map

| Request | Template | Key data source |
|---|---|---|
| Front page | `front-page.php` → 5 template-parts | `Hedayati_Query`, `Hedayati_Settings` |
| `/courses/` (CPT archive) | `archive-course.php` | main query + `Hedayati_Query::get_nav_categories` |
| `/course-category/{slug}` | `taxonomy-course-category.php` → `require archive-course.php` | main (tax) query |
| Single course | `single-course.php` | post + `_course_*` meta + `course-category` terms + `Hedayati_Query::get_related_courses` + `Hedayati_Settings` |
| Page / other singular | `singular.php` | post |
| Other archives | `archive.php` | main query |
| Posts list fallback | `index.php` | main query |
| 404 | `404.php` | — |
| Every page | `header.php` + `footer.php` | custom logo, `primary`/`footer` menus, `Hedayati_Query`, `Hedayati_Settings` |

### Template parts (`template-parts/`)

`hero-navigator.php` · `category-strip.php` · `featured-courses.php` · `course-card.php` ·
`impact-section.php` · `cta-band.php`. Each guards on `class_exists('Hedayati_Query')` /
`Hedayati_Settings` where relevant and renders nothing (or an admin-only hint) when its data is
absent. Icons are inline SVG — no icon font, no external requests.

### Assets

- `assets/css/main.css` (~2400 lines) — design tokens (`:root` + `[data-theme="dark"]`), reset,
  custom scrollbar, typography, buttons, header/nav, every section, responsive breakpoints
  (1024/900/768/420), reduced-motion. See `docs/DESIGN_SYSTEM.md`.
- `assets/css/rtl.css` — targeted RTL/bidi corrections for WP-generated markup.
- `assets/js/main.js` — vanilla IIFE: theme toggle, accessible mobile nav, sticky-header class.
  No dependencies.

### Client-side data flow

`header.php` inline script sets `data-theme` → `main.css` tokens switch → `main.js` syncs the
toggle button's `aria-pressed`/`aria-label` and, on explicit click, writes `localStorage` and
flips `data-theme`. OS `prefers-color-scheme` changes are followed only while no explicit choice
is stored.

### Server-side data flow (single course example)

`single-course.php` → `get_post_meta($id, '_course_*')` + `get_the_terms($id, 'course-category')`
+ `Hedayati_Settings::get('phone_consult')` / `tel_uri()` + `Hedayati_Query::get_related_courses()`
→ escaped output (`esc_html`, `esc_attr`, `esc_url`, `nl2br(esc_html(...))` for textareas).
`course-card.php` runs inside the loop and reads the same meta/taxonomy.

---

## Authentication flow (Phase 2A)

```
wp-login.php  →  authenticate filter chain
  @20  wp_authenticate_username_password        (core: username/email + password)
  @30  Hedayati_Auth::authenticate_phone
         if !looks_like_iranian_phone(identifier)      → pass through unchanged
         if password == ''                             → pass through unchanged
         normalize(identifier)  → WP_Error?             → generic invalid-credentials error
         find_user_by_phone(canonical)  → not found?    → generic invalid-credentials error
         wp_authenticate_username_password(null, user_login, password)
                                        → WP_Error?     → generic invalid-credentials error
                                        → WP_User       → return WP_User
  @90  Hedayati_Auth::enforce_rate_limit
         is_rate_limited(identifier, REMOTE_ADDR)?      → WP_Error 'too_many_retries' (overrides success)
  on WP_Error anywhere → wp_login_failed → Rate_Limiter::record_failure(identifier, ip)   [single count]
  on success           → wp_login       → clear identifier bucket for user_login + registered phone
                                          (shared IP bucket left to expire)
```

Rate-limit config (`hedayati_rate_limit_config` filter): `identifier_max_attempts` 5,
`ip_max_attempts` 30, `lockout_seconds` 900.

---

## Migration flow (Phase 2A + 2B)

```
admin_init → Hedayati_DB_Schema::maybe_migrate()
  installed = get_option('hedayati_core_db_version', '1.0.0')
  if version_compare(installed, CURRENT_DB_VERSION, '<'):   // CURRENT_DB_VERSION = 2.1.0
    acquire_lock()  (atomic add_option; steal if older than 60s)
    for each MIGRATIONS entry newer than installed (in order):
        run method → true?  → update_option('hedayati_core_db_version', version)
                    → false? → break (safe retry next request)
    release_lock()
```

`MIGRATIONS = { '2.0.0' => migrate_2_0_0, '2.1.0' => migrate_2_1_0, '2.2.0' => migrate_2_2_0 }`.
- `migrate_2_0_0()` — `dbDelta` for `hedayati_user_phones`, then `SHOW TABLES LIKE` to confirm.
- `migrate_2_1_0()` — `dbDelta` for the five academic-operations tables, then confirms **every**
  one with `SHOW TABLES LIKE`; returns `false` (no version advance, safe retry) if any is missing.
  Additive only — does not touch `hedayati_user_phones` or any Phase 2A data.
- `migrate_2_2_0()` — `dbDelta` for `hedayati_audit_log`, then `SHOW TABLES LIKE`. Additive; no
  existing table touched. Runs in version order after 2.1.0.

`CURRENT_DB_VERSION` is `2.2.0`.

Roles sync is parallel and version-gated the same way (`ROLES_VERSION` `2.1.0` — adds
`hedayati_manage_teachers`, removes nothing).

---

## Superseded architecture (historical only — do NOT rebuild)

Earlier iterations of this project, retained here so future contributors recognize and avoid them.
`reference-react/` is the only surviving artifact and is **visual reference only**.

| Superseded approach | Replaced by | Why |
|---|---|---|
| React + Vite single-page app as the production runtime | Classic WordPress PHP theme + `theme.json` | Institute requires staff-editable WordPress; an SPA needs a developer for content |
| Express (Node) API server | WordPress core + `hedayati-core` plugin endpoints/hooks | Avoid a parallel runtime and its own deploy/ops |
| Prisma ORM + schema as the data authority | `register_post_meta` / custom `$wpdb` tables via `dbDelta`; versioned migrations in `class-db-schema.php` | Stay within WordPress; no second migration system |
| PostgreSQL | MySQL/MariaDB (WordPress default), dynamic `$wpdb->prefix` | Hosting is standard WordPress on ParsPack |
| Argon2 / application-managed password hashing | `wp_authenticate_username_password`, WP session cookies | WordPress is the single identity authority |
| Authoritative phone number in `wp_usermeta` | Dedicated `hedayati_user_phones` table with DB `UNIQUE` constraints | usermeta cannot guarantee uniqueness against registration races |
| Custom `super_admin` role | Native `administrator` + operational `hedayati_manager` | WordPress reserves "Super Admin" for Multisite; keep a clean technical/operational boundary |
| `dev.drhedayati.com` on Plesk | `mystik.ir` on ParsPack | Legacy hosting is Windows/ASP.NET/MSSQL constrained |
| `Compress-Archive` release packaging | `tar -a -c -f` | `Compress-Archive` produced archives the host mis-extracted |
| Google / social login | Username **or** Iranian phone + password | Explicit institute requirement — not wanted |

Still-valid domain knowledge from that era: Course vs Course Run separation, relational operational
records, least privilege, server-side authorization, private files outside the web root,
normalization, audit logs, nullable unknown capacity/tuition, safe deletion rules, phased delivery.
