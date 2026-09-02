# DATA_MODEL.md

All entities live in a standard WordPress database. **Never hardcode the `wp_` prefix** — the
staging install uses a non-`wp_` randomized prefix. Code always uses `$wpdb->prefix`.

**Legend:** ✅ implemented · ⬜ planned (no code yet).

---

## WordPress-native entities in use

| Entity | Use |
|---|---|
| `wp_users` / `wp_usermeta` | ✅ Identity authority — usernames, password hashes, sessions, email, display name. Future: student profile fields in usermeta. |
| Posts — `post` type `course` | ✅ Course catalog entries. |
| Posts — `page` | ✅ (planned content) About / Contact / consultation / articles. |
| Post revisions | ✅ Enabled for `course`. |
| `wp_posts.menu_order` | ✅ Course display priority, edited via the course meta box. |
| Terms — taxonomy `course-category` | ✅ Hierarchical course categories. |
| `wp_options` | ✅ Institute settings + plugin version/state markers (see below). |
| Nav menus | ✅ `primary`, `footer` locations (fallbacks provided). |
| Media library | ✅ Featured images (`course-card` 560×320, `course-hero` 1200×600). ⬜ **Not** for private student documents. |
| Roles & capabilities | ✅ 5 custom roles + 22 `hedayati_*` caps (21 from Phase 2A + `hedayati_manage_teachers` from Phase 2B; see `docs/SECURITY.md`). |
| Transients | ✅ Auth rate-limit buckets. |

---

## Course CPT — `course` ✅

Registered in `class-post-types.php`.

| Setting | Value |
|---|---|
| `public` / `publicly_queryable` / `show_ui` | true |
| `show_in_rest` | **true** (block editor + REST for editing) |
| `hierarchical` | false |
| `has_archive` | `courses` → `/courses/` |
| `rewrite.slug` | `course` → `/course/{slug}`, `with_front` false, no feeds |
| `menu_icon` | `dashicons-welcome-learn-more`, `menu_position` 5 |
| `capability_type` | `post` |
| `supports` | title, editor, excerpt, thumbnail, custom-fields, page-attributes, revisions |
| `taxonomies` | `course-category` |
| `delete_with_user` | **false** |

**Native fields used:** title (Persian course name), editor content (long description / intro),
excerpt (card/marketing summary), featured image, `menu_order` (display priority).

### Course post meta ✅ (`class-course-meta.php`)

Every key: `single => true`, `show_in_rest => false`,
`auth_callback => current_user_can('edit_post', $object_id)`. Meta-box save
(`class-meta-box.php`) additionally enforces nonce + `edit_post` + autosave + post-type guards and
re-runs the same sanitizers.

| Meta key | Type | Default | Sanitizer | Meaning |
|---|---|---|---|---|
| `_course_english_name` | string | `''` | `sanitize_text_field` | English / standard code (e.g. `CCNA 200-301`). Shown as an LTR uppercase badge. Also feeds `hedayati_course_monogram()` (first word, up to 4 chars). |
| `_course_teacher` | string | `''` | `sanitize_text_field` | Display teacher name. **Will become a fallback** once Course Runs exist. |
| `_course_duration` | string | `''` | `sanitize_text_field` | Display duration (e.g. "۴۸ ساعت (۱۲ جلسه)"). |
| `_course_next_start_date` | string | `''` | `sanitize_iso_date` | **Strict Gregorian `YYYY-MM-DD`**; regex + `checkdate()`; invalid → `''`. Rendered inside `<time datetime dir="ltr">`. Fallback once Course Runs exist. |
| `_course_level` | string | `''` | `sanitize_text_field` | Course level text. |
| `_course_prerequisites` | string | `''` | `sanitize_textarea_field` | Entry prerequisites (multi-line). |
| `_course_price` | string | `''` | `sanitize_text_field` | **Display string only** (e.g. "۴٬۵۰۰٬۰۰۰ تومان"). Empty ⇒ price not shown. Future: integer **rial** when payment is integrated. |
| `_course_registration_state` | string | `soon` | `sanitize_registration_state` | Allowlist `open` / `closed` / `soon`; anything else → `soon`. |
| `_course_is_featured` | boolean | `false` | `rest_sanitize_boolean` | Homepage featured flag. Featured query matches stored value `'1'`. |
| `_course_syllabus` | array\<string\> | `[]` | `sanitize_string_array` | Repeatable key syllabus/module items. Tags stripped, empties dropped, re-indexed. |
| `_course_target_audience` | array\<string\> | `[]` | `sanitize_string_array` | Repeatable "who is this for" items. |
| `_course_learning_outcomes` | array\<string\> | `[]` | `sanitize_string_array` | Repeatable post-course skills/outcomes. |

---

## Taxonomy — `course-category` ✅

Registered in `class-taxonomies.php`: hierarchical, public, `show_in_rest` true,
`show_admin_column` true, rewrite `/course-category/...` (`with_front` false, hierarchical URLs).

**Cards and archives display the term `name` (human-readable), never the URL-encoded slug.**

### Term meta ✅ (`class-term-meta.php`)

| Meta key | Type | Default | Sanitizer | Meaning |
|---|---|---|---|---|
| `course_cat_english` | string | `''` | `sanitize_text_field` | English label shown beside the Persian name (e.g. `NETWORK & IT`). |
| `course_cat_icon` | string | `''` | `sanitize_icon` (strip tags → `sanitize_text_field` → `mb_substr 0,8`) | Plain-text symbol/character. **No HTML/SVG/JS.** Category strip falls back to the first character of the name. |
| `course_cat_order` | integer | `0` | `absint` | Display order, ascending. **Known issue:** default `0` means unordered terms sort first. |

`show_in_rest => false` for all term meta. Save requires nonce + `manage_categories`.

Ordering (`Hedayati_Query::get_nav_categories()`): fetch top-level terms (`parent => 0`,
`hide_empty => false`), then **sort in PHP** by `course_cat_order` asc, then `name` asc (WordPress
`get_terms` cannot order by arbitrary term meta).

---

## Institute settings ✅ (`class-settings.php`)

Single option `hedayati_institute_settings` (array), option group `hedayati_institute`, page
`Settings → Hedayati` (`manage_options`).

| Field | Sanitizer | Rendered in |
|---|---|---|
| `phone_consult` | `sanitize_phone` (keep `\d \s + - ( ) . # ,`) | Footer, CTA band, single-course hero/sidebar |
| `phone_tabriz` | `sanitize_phone` | Footer |
| `phone_tehran` | `sanitize_phone` | Footer |
| `address_tabriz` | `sanitize_textarea_field` | Footer (`<address>`, `nl2br`) |

Accessors: `Hedayati_Settings::get($key)` (string, `''` if unset/inactive) and
`Hedayati_Settings::tel_uri($key)` → `hedayati_phone_to_tel_uri()`: preserve a leading `+`, strip
all other non-digits, `''` if nothing dialable.

---

## Custom table — `{prefix}hedayati_user_phones` ✅

Created by migration `2.0.0` (`class-db-schema.php::migrate_2_0_0` via `dbDelta`, charset/collation
from `$wpdb->get_charset_collate()`).

| Column | Definition | Notes |
|---|---|---|
| `id` | `bigint(20) unsigned NOT NULL AUTO_INCREMENT` | `PRIMARY KEY` |
| `user_id` | `bigint(20) unsigned NOT NULL` | `UNIQUE KEY uq_user_id` — one phone per user |
| `phone_e164` | `varchar(20) NOT NULL` | `UNIQUE KEY uq_phone_e164` — one user per phone |
| `is_verified` | `tinyint(1) NOT NULL DEFAULT 0` | `KEY idx_is_verified` |
| `verified_at` | `datetime DEFAULT NULL` | set on verify, cleared on number change |
| `created_at` | `datetime NOT NULL` | UTC (`current_time('mysql', true)`) |
| `updated_at` | `datetime NOT NULL` | UTC |

The two `UNIQUE` constraints make concurrent duplicate assignment safe at the DB level; the service
converts a lost-write race into a `phone_already_exists` error.

### Lifecycle rules (`class-user-phone-service.php`)

- **Assign:** normalize → if user already has a row, delegate to update → app pre-check
  `is_phone_available` → `INSERT`; on `false` return, re-check availability → `phone_already_exists`
  or `db_insert_failed`.
- **Update:** normalize → no existing row ⇒ assign → **unchanged number ⇒ no-op (state
  preserved)** → changed number ⇒ `UPDATE` setting `phone_e164`, `is_verified = 0`,
  `verified_at = NULL`, `updated_at = now`. Changing the number **always** clears verification.
- **Verify:** only if a row exists → `is_verified = 1`, `verified_at`, `updated_at`.
- **Delete:** on the `deleted_user` action → `DELETE` the row.

`phone_e164` is the only phone identity of record. Phone is **not** stored in usermeta (usermeta
cannot enforce uniqueness).

---

## `wp_options` markers written by the plugin

| Option | Written by | Meaning |
|---|---|---|
| `hedayati_institute_settings` | Settings API | Institute contact data |
| `hedayati_core_db_version` | `Hedayati_DB_Schema` | Installed schema version; advanced **only** after a migration verifies success. Target `2.0.0`. |
| `hedayati_core_roles_version` | `Hedayati_Roles` | Installed roles/caps version. Target `2.0.0`. |
| `hedayati_core_managed_capabilities` | `Hedayati_Roles` | Array of the caps this plugin currently manages — used to safely remove only its own obsolete caps on upgrade. |
| `hedayati_db_migration_lock` | `Hedayati_DB_Schema` | Transient-style concurrency lock (unix time); 60s stale-recovery; deleted after migration. |

Rate-limit transients: `hd_rl_ip_<sha256[:24]>` and `hd_rl_id_<sha256[:24]>`, TTL = lockout
seconds (default 900).

---

## Normalization rules

### Persian / Arabic-Indic numerals → ASCII

- **Required** wherever a field is canonical/searchable. Backend normalization is **authoritative**
  so admin/API/import paths cannot bypass it. Frontend conversion is optional UX only.
- **Implemented:** phone input (`Hedayati_Phone::DIGIT_MAP`, maps `۰-۹` U+06F0–U+06F9 and `٠-٩`
  U+0660–U+0669 to `0-9`); and, from Phase 2B, the shared `Hedayati_Text::digits_to_ascii()` used
  by `Hedayati_Academic_Validation` for every Course Run / Session numeric or date field
  (capacity, tuition rial, session number, ISO dates, session datetimes). `Hedayati_Phone` keeps
  its own inline map (verified Phase 2A code, left untouched); all new code uses `Hedayati_Text`.
- **Planned:** national ID and any other searchable numeric identifier — each needs its own
  explicit field-specific rule. **Do not** apply a blind site-wide digit conversion to prose.
- Persian/Arabic **display** of numerals is a UI choice; stored/searchable values stay ASCII.

### Iranian mobile phone → canonical E.164 (`Hedayati_Phone`) ✅

- **Canonical form:** exactly `+989XXXXXXXXX` — regex `^\+989[0-9]{9}$`.
- **Allowed input characters** (before stripping): ASCII/Persian/Arabic digits, `+`, space, `-`,
  `(`, `)`, `.`. Anything else (letters, markup, `_`, etc.) → **rejected** (`WP_Error`), not
  stripped.
- **`+` rules:** at most one, only at index 0.
- **Accepted equivalent inputs** (all resolve to one canonical value / one account / one
  rate-limit bucket):

  | Input shape | Example | → |
  |---|---|---|
  | `09XXXXXXXXX` (11) | `09141234567` | `+989141234567` |
  | `9XXXXXXXXX` (10) | `9141234567` | `+989141234567` |
  | `+989XXXXXXXXX` (13) | `+989141234567` | unchanged |
  | `00989XXXXXXXXX` (14) | `00989141234567` | `+989141234567` |
  | `989XXXXXXXXX` (12) | `989141234567` | `+989141234567` |
  | Persian/Arabic digits | `۰۹۱۴۱۲۳۴۵۶۷` | `+989141234567` |
  | with separators | `0914 123 4567`, `0914-123-4567` | `+989141234567` |

- **Rejected:** embedded letters, `<script>`, underscores, multiple/misplaced `+`, non-mobile
  Iranian landlines, wrong lengths, non-Iranian numbers — as errors, never coerced into a
  valid-looking value.
- **Display** (`format_display`): `national` `09141234567` · `spaced` `0914 123 4567` ·
  `international` `+98 914 123 4567`.
- Institute contact numbers (`Hedayati_Settings::sanitize_phone`) use a **looser** rule (they are
  display strings, possibly landlines with extensions) — not the strict mobile normalizer.

### Dates

- Course dates: strict Gregorian ISO `YYYY-MM-DD`, `checkdate()`-validated, machine-sortable.
- ⬜ Future sessions: canonical `starts_at` / `ends_at` datetimes.
- ⬜ Shamsi/Jalali is an input/display layer only — never a stored value.

### Rate-limit identifier canonicalization (`Hedayati_Rate_Limiter`) ✅

`looks_like_iranian_phone()` ⇒ normalize to E.164; otherwise `trim` + `strtolower`. Equivalent
phone formats therefore share one counter.

---

## Relationships

### Current ✅

- `course` —(many-to-many)— `course-category` term. First term is treated as the "primary"
  category for badges/breadcrumbs (template convention).
- `course` —(1:1, optional)— featured image (attachment).
- `wp_users` row —(1:1, optional)— `hedayati_user_phones` row (`user_id` UNIQUE).
- `role` —(assigns)— capabilities (`hedayati_*`).

### Phase 2B — Academic Operations ✅ (implemented in the repository; staging acceptance pending)

- `Teacher` CPT (`teacher`) —(0:1)— `wp_users` via `_hedayati_teacher_user_id` (public instructor
  identity, optionally linked to an account; 1:1 enforced in the save handler). Not publicly
  queryable yet (D30).
- `course` post —(1:many)— `Course Run` row (`hedayati_course_runs.course_id`). The run is the
  operational source of truth for teacher(s), dates, schedule, tuition, capacity and registration
  state; the `_course_*` meta stays as a display fallback only.
- `Course Run` —(1:many)— `Session` (`hedayati_sessions`, `UNIQUE(run_id, session_number)`,
  canonical `starts_at` / `ends_at` datetimes).
- `Course Run` —(many:many, roled)— staff (`hedayati_run_staff`, `staff_role` ∈
  primary_instructor / additional_instructor / assistant). Instructor rows reference a `Teacher`
  profile (`teacher_id`); assistant rows reference a `wp_users` id (`user_id`). One
  `primary_instructor` per run (service-enforced).
- `Course Run` —(1:many)— `Enrollment` (`hedayati_enrollments`, `UNIQUE(run_id, user_id)`) —(many:1)—
  student (`wp_users`).
- `Session` —(1:many)— `Attendance` (`hedayati_attendance`, `UNIQUE(session_id, enrollment_id)`)
  —(many:1)— `Enrollment`. Every write checks that the enrollment and session share one run.

See "Custom tables — Phase 2B" below for columns. All services are in
`plugin/hedayati-core/includes/class-*-service.php`; the admin UI is
`class-academic-admin.php` (menu «عملیات آموزشی», capability `hedayati_manage_course_runs`).

### Planned ⬜ (Phase 2C — approved model, no code)

- `student` —(1:1)— verification record (protected national-ID representation, review state,
  reviewer, timestamps, notes) — separate from role and from phone verification.
- `student` —(1:many)— private `Document` (abstract `storage_backend` + `storage_key`, MIME/size
  allowlist, generated names, `archive_reference` / `archived_at` / `deleted_at` lifecycle).
- Append-only audit-log entries for upload/access/review/deletion/archive and verification actions
  (metadata only — never document contents). **Must be excluded from every Phase 2B deletion
  cascade** once it exists (D16 / D31).

---

## Custom tables — Phase 2B ✅

Created by migration `2.1.0` (`class-db-schema.php::migrate_2_1_0` via `dbDelta`; charset/collation
from `get_charset_collate()`; InnoDB where supported). All addressed via
`Hedayati_DB_Schema::get_table_*()` — never a literal `wp_`. Business-state columns are `varchar`
validated by `Hedayati_Academic_Validation`, **not** MySQL ENUMs. No DB-level foreign keys;
integrity is service-enforced + cleanup hooks (D29).

### `{prefix}hedayati_course_runs`

| Column | Definition | Notes |
|---|---|---|
| `id` | `bigint unsigned AI` | PK |
| `course_id` | `bigint unsigned NOT NULL` | `KEY idx_course_id`; references `wp_posts.ID` of a `course` |
| `label` | `varchar(190) NOT NULL DEFAULT ''` | staff-set cohort label; empty ⇒ UI falls back to course title |
| `run_status` | `varchar(20) NOT NULL DEFAULT 'draft'` | `KEY`; draft/scheduled/in_progress/completed/cancelled |
| `registration_status` | `varchar(20) NOT NULL DEFAULT 'closed'` | `KEY`; closed/open/soon (safe default = closed) |
| `start_date` / `end_date` | `date NULL` | strict Gregorian; end ≥ start enforced |
| `schedule_text` | `varchar(255) NOT NULL DEFAULT ''` | free text |
| `capacity` | `int unsigned NULL` | **NULL = unknown**, never 0/20 |
| `tuition_rial` | `bigint unsigned NULL` | **NULL = unknown**; integer rial |
| `notes` | `text NULL` | internal |
| `created_at` / `updated_at` | `datetime NOT NULL` | UTC |

### `{prefix}hedayati_run_staff`

| Column | Definition | Notes |
|---|---|---|
| `id` | `bigint unsigned AI` | PK |
| `run_id` | `bigint unsigned NOT NULL` | `KEY` |
| `staff_role` | `varchar(30) NOT NULL` | primary_instructor / additional_instructor / assistant |
| `teacher_id` | `bigint unsigned NULL` | `KEY`; set for instructor roles (references a `teacher` post) |
| `user_id` | `bigint unsigned NULL` | `KEY`; set for assistant role (references `wp_users.ID`) |
| `created_at` / `updated_at` | `datetime NOT NULL` | |

Uniqueness ((run, person, role) once; one primary instructor per run) is enforced in
`Hedayati_Run_Staff_Service` — the nullable ref columns can't carry a meaningful SQL UNIQUE.

### `{prefix}hedayati_sessions`

| Column | Definition | Notes |
|---|---|---|
| `id` | `bigint unsigned AI` | PK |
| `run_id` | `bigint unsigned NOT NULL` | `KEY` |
| `session_number` | `int unsigned NOT NULL` | **`UNIQUE KEY uq_run_session (run_id, session_number)`** |
| `starts_at` | `datetime NOT NULL` | canonical; `KEY idx_starts_at` |
| `ends_at` | `datetime NULL` | optional; must be > `starts_at` when set |
| `topic` | `varchar(190) NOT NULL DEFAULT ''` | |
| `status` | `varchar(20) NOT NULL DEFAULT 'scheduled'` | scheduled/held/cancelled |
| `created_at` / `updated_at` | `datetime NOT NULL` | |

### `{prefix}hedayati_enrollments`

| Column | Definition | Notes |
|---|---|---|
| `id` | `bigint unsigned AI` | PK |
| `run_id` | `bigint unsigned NOT NULL` | `KEY` |
| `user_id` | `bigint unsigned NOT NULL` | `KEY`; student (`wp_users.ID`) |
| — | | **`UNIQUE KEY uq_run_user (run_id, user_id)`** |
| `status` | `varchar(20) NOT NULL DEFAULT 'active'` | `KEY`; active/withdrawn/completed/cancelled |
| `enrolled_at` | `datetime NOT NULL` | |
| `created_at` / `updated_at` | `datetime NOT NULL` | |

### `{prefix}hedayati_attendance`

| Column | Definition | Notes |
|---|---|---|
| `id` | `bigint unsigned AI` | PK |
| `session_id` | `bigint unsigned NOT NULL` | `KEY` |
| `enrollment_id` | `bigint unsigned NOT NULL` | `KEY` |
| — | | **`UNIQUE KEY uq_session_enrollment (session_id, enrollment_id)`** |
| `status` | `varchar(20) NOT NULL` | present/absent/late/excused (no implicit default) |
| `note` | `varchar(255) NOT NULL DEFAULT ''` | |
| `recorded_by` | `bigint unsigned NULL` | acting user; nulled (row kept) when that user is deleted |
| `recorded_at` | `datetime NOT NULL` | |
| `created_at` / `updated_at` | `datetime NOT NULL` | |

### `wp_options` markers added by Phase 2B

`hedayati_core_db_version` advances to `2.1.0`; `hedayati_core_roles_version` advances to `2.1.0`;
`hedayati_core_managed_capabilities` grows to **22** entries (adds `hedayati_manage_teachers`).
