# Dr. Hedayati WordPress Rebuild — Complete Project Handoff

**Document date:** 2026-09-02  
**Audience:** Claude Code and future developers working directly in the repository  
**Authoritative architecture:** WordPress + custom `hedayati` theme + custom `hedayati-core` plugin  
**Current staging site:** `mystik.ir`  
**Future production site:** `drhedayati.com`

> This document reconstructs the project from the available conversation history and the latest inspectable `hedayati-core` ZIP. It is documentation only; no production or repository implementation was performed while preparing it. Where conversation summaries and source inspection differ, inspected source and the latest confirmed staging result take precedence. The latest theme ZIP was not available as a directly inspectable file in this handoff workspace, so theme details are reconstructed from prior code reviews and implementation reports and must be rechecked against the repository.

## Status legend

- **IMPLEMENTED** — present in the latest inspected code or confirmed deployed.
- **PARTIAL** — some foundation exists, but required UI, integration, or acceptance testing remains.
- **PLANNED** — approved current direction, not yet implemented.
- **SUPERSEDED** — historical work/decision that is no longer the active architecture.
- **OPTION / UNDECIDED** — discussed but not approved as a requirement.

# 1. PROJECT OVERVIEW

`drhedayati.com` is the public and future operational website for **مجتمع آموزشی دکتر هدایتی**, a Persian-language computer and technology education institute—not a medical practice. The existing business covers a broad course catalog including networking, security, programming and web, mobile, data/ML, financial markets, modeling/rendering, graphics and content creation, ICDL, children’s computer education, and accounting.

The rebuild has two goals:

1. Replace the dated, constrained public website with a modern, elegant, easy-to-navigate Persian/RTL site that feels professionally designed and remains editable by institute staff.
2. Gradually add a secure operational layer for real accounts, phone login, students, verification, private documents, teachers, course runs, sessions, enrollments, staff panels, and auditability.

Primary audiences are prospective students and visitors, enrolled students, teachers, teacher assistants, reception staff, institute managers, and the technical WordPress administrator.

The business requirement is not merely a visual redesign. The finished system should let the institute manage public content and operational education data without depending on a developer for ordinary edits, while protecting private student information and keeping the current production business available during the rebuild.

# 2. CURRENT PROJECT STATUS

## 2.1 Authoritative current state

The project is now a **custom WordPress implementation**. Work on the former React/Express/Prisma/PostgreSQL application is frozen and retained only as design/domain reference.

On `mystik.ir` staging:

- **IMPLEMENTED/DEPLOYED:** custom `hedayati` theme.
- **IMPLEMENTED/DEPLOYED:** custom `hedayati-core` plugin.
- **IMPLEMENTED/VERIFIED:** Course CPT, course categories, course authoring meta box, featured courses, public course archive, individual course pages, a populated CCNA example, homepage, light/dark frontend, Persian RTL behavior, institute settings, and permalink routing.
- **IMPLEMENTED/VERIFIED:** public homepage, CCNA page, and course pages continued working after the Phase 2A plugin deployment.
- **IMPLEMENTED/VERIFIED:** Phase 2A migration runs from an admin request and creates the dynamically prefixed `hedayati_user_phones` table. On this staging database the WordPress prefix is `vShPz25x_`, so the observed table is `vShPz25x_hedayati_user_phones`, not `wp_hedayati_user_phones`.
- **IMPLEMENTED/VERIFIED:** Hedayati database/role options are written after visiting WordPress admin. Expected options include `hedayati_core_db_version`, `hedayati_core_roles_version`, and `hedayati_core_managed_capabilities`.
- **IMPLEMENTED IN CODE:** plugin version `1.1.0`; database schema and role version `2.0.0`.

## 2.2 Phase 2A acceptance boundary

The latest accepted Phase 2A ZIP was inspected previously and reported as:

- PHP tests: **78 passed, 0 failed**.
- Node/static verification: **74 passed, 0 failed**.
- PHP syntax lint: reported clean for all plugin PHP files.

During this handoff, the latest ZIP’s Node/static suite was rerun and again produced **74 passed, 0 failed**. PHP was not installed in the handoff environment, so the previously reported 78/0 PHP result could not be independently rerun here.

Phase 2A is **not fully integration-accepted yet**. The database migration and public non-regression are confirmed, but the following staging tests remain:

- create a disposable `student` test account;
- normal username/password login;
- phone/password login;
- equivalent `0914…`, `+98914…`, `0098914…`, Persian-digit, and Arabic-digit forms resolving to one account;
- unknown phone and wrong password returning privacy-safe generic errors;
- 5-attempt identifier and 30-attempt IP rate limits through the real WordPress filter chain;
- database UNIQUE behavior and friendly duplicate-phone errors;
- role/capability presence and least-privilege behavior;
- changing a number resetting verification;
- `deleted_user` cleanup of the phone row.

## 2.3 What is not built

No production UI currently exists for login by phone, student profiles, document submission, verification workflow, Course Runs, sessions, enrollments, attendance, teacher/TA portals, reception tools, manager dashboards, audit logs, SMS, online payment, or automatic file transfer. Those are planned phases.

# 3. ARCHITECTURE HISTORY

## 3.1 Original public-site investigation

The live `drhedayati.com` was identified as an older custom ASP.NET/ASP.NET MVC-style application, based on server structure such as `App_Data`, `bin`, `Content`, `Views`, and `Scripts`, plus MSSQL. Its limited editor was a custom panel, not WordPress. The initial rule was to protect the business first: take full Plesk backups including files and databases and download an independent copy before any destructive change.

## 3.2 React/Vite design prototype

Google AI Studio and Antigravity were used to create multiple React/Vite visual concepts. The prototype contained polished public pages and mock admin/student panels, but operational data was mock/localStorage data and there was no real identity, authorization, or database layer.

The selected direction became the **Concept C / Navigator / Precision-family** design. That prototype remains useful as a visual reference under `reference-react/`, but it is not the production runtime.

## 3.3 Superseded Express/Prisma/PostgreSQL plan

Before WordPress became an explicit institute requirement, the plan was to keep React/Vite, add Express, Prisma, PostgreSQL, authentication, RBAC, student profiles, private documents, and audit logging. A substantial Prisma schema and migration discussion occurred, including Course vs CourseRun, teacher/TA assignments, normalization, private storage, and auditability.

The institute then stated that the whole system should be WordPress so staff could edit it easily. Continuing the Node backend and later converting it would have created duplicate work and an avoidable migration. Development therefore pivoted immediately.

**SUPERSEDED:** Express server, Prisma runtime/schema as production authority, PostgreSQL for this rebuild, custom React SPA panels as production panels, Argon2/application-managed password storage, and `dev.drhedayati.com` as the current active staging location.

**STILL RELEVANT DOMAIN KNOWLEDGE:** Course vs Course Run separation, relational operational records, least privilege, server-side authorization, private files outside public web root, normalization, audit logs, nullable unknown capacity/tuition, safe deletion rules, and phased implementation.

## 3.4 Current WordPress architecture

The current production direction is:

```text
WordPress core
├── users, passwords, sessions, email, content primitives
├── custom Hedayati theme (presentation)
└── Hedayati Core plugin (domain behavior and persistent data)
    ├── Course CPT and course-category taxonomy
    ├── course metadata/settings/query helpers
    ├── roles and granular capabilities
    ├── phone normalization/authentication/rate limiting
    ├── versioned database migrations
    └── future operational custom tables/services
```

WordPress authentication primitives remain authoritative. Business behavior belongs in the plugin so it survives a theme change. Theme code owns presentation and templates.

# 4. CURRENT WORDPRESS ARCHITECTURE

## 4.1 Repository convention

The intended repository root is `C:\Projects\drhedayati-wordpress` with:

```text
drhedayati-wordpress/
├── .gitignore
├── docs/
├── plugin/
│   └── hedayati-core/
├── reference-react/
└── theme/
    └── hedayati/
```

`reference-react/` is a design reference and must not be casually edited or treated as production code.

## 4.2 `hedayati-core` plugin — verified latest ZIP

```text
hedayati-core/
├── hedayati-core.php
├── assets/
│   ├── css/admin.css
│   └── js/admin.js
├── includes/
│   ├── class-auth.php
│   ├── class-course-meta.php
│   ├── class-db-schema.php
│   ├── class-meta-box.php
│   ├── class-phone.php
│   ├── class-post-types.php
│   ├── class-query-helpers.php
│   ├── class-rate-limiter.php
│   ├── class-roles.php
│   ├── class-settings.php
│   ├── class-taxonomies.php
│   ├── class-term-meta.php
│   └── class-user-phone-service.php
└── tests/
    ├── test-phase2a.php
    └── verify-phase2a.js
```

Responsibilities:

- `hedayati-core.php`: constants/bootstrap, includes, WordPress hooks, activation/deactivation, admin assets, shared phone-to-`tel:` helper. Requires WordPress 6.6+ and PHP 8.3; current plugin version is 1.1.0.
- `class-post-types.php`: public `course` CPT, archive `/courses/`, single rewrite `/course/...`, block editor, title/editor/excerpt/thumbnail/custom fields/page attributes/revisions.
- `class-taxonomies.php`: hierarchical public `course-category` taxonomy with `/course-category/...` rewrite.
- `class-course-meta.php`: registered course post meta, allowlists, sanitizers, ISO/Gregorian validation, post edit authorization.
- `class-meta-box.php`: Persian course editing UI, repeaters, nonce/capability/autosave/revision checks, sanitized save, `wp_posts.menu_order` editing.
- `class-query-helpers.php`: featured, archive, category-navigation, and related-course queries with deterministic ordering.
- `class-settings.php`: Settings → Hedayati contact/business settings using the WordPress Settings API.
- `class-term-meta.php`: course-category English label, plain-text symbol/icon, and display order.
- `class-phone.php`: strict Iranian phone transliteration, validation, E.164 normalization, phone-likeness detection, and display formatting.
- `class-db-schema.php`: ordered/idempotent migrations, version option, atomic option lock, table verification.
- `class-user-phone-service.php`: lookup, assignment, update, uniqueness/race handling, verification, and deletion cleanup.
- `class-roles.php`: custom roles, 21 granular capabilities, role versioning, safe cleanup of obsolete managed capabilities.
- `class-rate-limiter.php`: canonicalized identifier/IP transient buckets.
- `class-auth.php`: phone adapter after core username auth, late rate-limit enforcement, single authoritative failure counter path, successful identifier-bucket cleanup.
- tests: pure-PHP and static/Node coverage; not substitutes for WordPress integration tests.

## 4.3 Theme — reconstructed, verify in repository

Known files from reviewed builds include:

```text
theme/hedayati/
├── style.css
├── theme.json
├── functions.php
├── header.php
├── footer.php
├── front-page.php
├── archive-course.php
├── taxonomy-course-category.php
├── single-course.php
├── archive.php
├── singular.php
├── index.php
├── 404.php
├── inc/menu-fallbacks.php
├── assets/css/main.css
├── assets/js/main.js
└── template-parts/
    ├── course-card.php
    ├── hero-navigator.php
    ├── category-strip.php
    ├── impact-section.php
    └── cta-band.php
```

The theme is a classic/PHP custom theme with `theme.json`, not a React runtime and not a block-theme-only architecture. The dedicated taxonomy template delegates to the course browsing layout. Contact data is read from the plugin settings API. `main.js` manages responsive navigation and theme behavior; dark mode should respect system preference until the user makes an explicit choice.

# 5. COMPLETE FUNCTIONAL REQUIREMENTS

| Area | Requirement | Status |
|---|---|---|
| Public | Persian-first, RTL, modern institute homepage | IMPLEMENTED |
| Public | Light and dark themes | IMPLEMENTED; full-page regression QA still required |
| Public | Browse courses and hierarchical categories | IMPLEMENTED |
| Public | Course detail with structured metadata/content | IMPLEMENTED |
| Public | Featured courses editable by staff; up to 8 | IMPLEMENTED |
| Public | Human-readable category labels, not URL-encoded slugs | IMPLEMENTED/FIXED |
| Public | Professional custom scrollbar | IMPLEMENTED in reported theme pass; verify repository |
| Public | Blog/articles and old-site content inventory | PLANNED |
| Public | About, Contact, consultation pages | PLANNED; some theme links may already assume routes |
| Public | Search across relevant public content | PLANNED; exact UX not finalized |
| Authentication | Username + password OR Iranian phone + password | PARTIAL: backend implemented, staging integration pending, no custom UI |
| Authentication | No Google login | CURRENT REQUIREMENT |
| Authentication | SMS not required for password login | CURRENT REQUIREMENT |
| Authentication | Future OTP, recovery, notifications through provider abstraction | PLANNED; provider/API unknown |
| Students | WordPress user-based student account | PARTIAL foundation |
| Students | Profile: phone, email, address, national ID, expandable fields | PLANNED except core email/phone service foundation |
| Students | Independent verification state | PLANNED; not a role |
| Students | Upload national ID card, birth certificate, other requested documents | PLANNED |
| Students | See enrolled courses/runs and sessions | PLANNED |
| Staff | Reception lookup/basic edits/enrollment operations | PLANNED; role/caps exist |
| Staff | Teacher assigned runs, rosters, sessions, attendance | PLANNED; role/caps exist |
| Staff | TA only assigned runs/rosters; no attendance by default | PLANNED; role/caps implemented |
| Management | Courses, runs, assignments, enrollments, verification, documents, settings, audit logs | PARTIAL: courses/settings work; remaining modules planned |
| Operations | Course Runs separate from catalog Course | PLANNED/APPROVED |
| Operations | Sessions and enrollment per Course Run | PLANNED/APPROVED |
| Documents | Protected, authorization-controlled delivery | PLANNED/APPROVED |
| Documents | Approx. 48-hour transfer to institute local storage | PLANNED workflow; automation details undecided |
| Media | Possible separate host for guides/videos | OPTION/FUTURE |
| Payments | Online payment gateway | OPTION/FUTURE, not currently required |

# 6. COURSE SYSTEM

## 6.1 Current catalog model

The `course` CPT is the permanent catalog/marketing identity, e.g. “CCNA.” It is public, queryable, REST-enabled for editor support, non-hierarchical, revisioned, and retains content independently of users. The archive slug is `/courses/`; single courses use `/course/{slug}`.

The hierarchical `course-category` taxonomy is public and has its own admin column/navigation/rewrite. Term metadata:

- `course_cat_english` — English label.
- `course_cat_icon` — plain-text symbol/icon; avoid fragile external/icon dependencies.
- `course_cat_order` — integer display order. Unset/default `0` terms may appear first; decide whether this remains desirable.

## 6.2 Exact course fields

WordPress-native fields:

- title — Persian course name;
- editor content — introduction/long description;
- excerpt — short marketing summary/card text;
- featured image;
- category terms;
- `menu_order` — explicit numeric display priority, edited by the custom meta box.

Custom post meta:

| Meta key | Meaning | Storage/validation |
|---|---|---|
| `_course_english_name` | English/technical name | sanitized string |
| `_course_teacher` | current catalog/display teacher text | sanitized string; future fallback once run assignments exist |
| `_course_duration` | display duration | sanitized string |
| `_course_next_start_date` | next start date | strict valid Gregorian `YYYY-MM-DD` |
| `_course_level` | course level | sanitized string |
| `_course_prerequisites` | prerequisites | sanitized textarea |
| `_course_price` | display price | string today; future canonical run tuition is integer rial |
| `_course_registration_state` | registration display state | `open`, `closed`, or `soon`; invalid values fall back to `soon` |
| `_course_is_featured` | homepage featured flag | boolean |
| `_course_syllabus` | repeatable syllabus/module strings | sanitized non-empty array |
| `_course_target_audience` | repeatable audience descriptions | sanitized non-empty array |
| `_course_learning_outcomes` | repeatable outcomes | sanitized non-empty array |

Meta is registered with `show_in_rest => false` in the present phase and authorization based on `edit_post` for the specific course. Saves require a nonce and capability check.

## 6.3 Query/display behavior

- Featured query returns published featured courses, default cap 8, ordered by `menu_order ASC`, then title.
- Public archive browsing uses stable ordering and supports category filtering.
- Related courses require shared categories. A previously found bug that returned arbitrary courses for an uncategorized course was corrected: no category should mean no related-course result.
- Cards display the taxonomy term’s human-readable `name`; the encoded slug must never be displayed.
- Homepage card grid is intended to look deliberate with 1–8 items: capped card widths and centered group for one/two/three cards, rather than one card stretching or sitting at the RTL edge. Large layouts target four cards per row, then two, then one responsively.
- Empty structured sections should not render blank decorative boxes.

## 6.4 Current example data

A full CCNA staging example was entered to exercise the field set. It includes Persian long-form description, excerpt, structured syllabus, audience, outcomes, and course attributes. Treat it as test/reference content until institute staff approve final marketing copy.

## 6.5 Approved operational model

After Phase 2B:

- `Course` remains catalog/marketing content.
- `Course Run` becomes the operational source of truth for actual teacher(s), start/end, schedule, tuition, capacity, and registration state.
- Existing `_course_teacher`, `_course_next_start_date`, `_course_price`, and `_course_registration_state` remain backward-compatible fallback/display values during transition; staff should not permanently maintain two competing sources of truth.

Approved Course Run principles:

- nullable capacity and tuition; unknown is not `20` and not free/`0`;
- tuition stored as integer **rial**, displayed as toman where appropriate;
- separate `run_status` (`draft`, `scheduled`, `in_progress`, `completed`, `cancelled`) from `registration_status` (`closed`, `open`, `soon`);
- business states stored as validated strings, not MySQL ENUMs;
- sessions use canonical `starts_at`/`ends_at` datetimes and `UNIQUE(run_id, session_number)`;
- Teacher CPT represents public instructor identity and may link to a WP user;
- run staff assignment supports primary instructor, additional instructor, and TA;
- instructor assignments require a teacher profile; TA assignments require a WP staff user and do not require a public Teacher CPT;
- avoid duplicated teacher-user and teacher-profile fields that can drift.

# 7. USERS, LOGIN AND STUDENT SYSTEM

## 7.1 Identity authority

WordPress `wp_users` is authoritative for accounts, password hashes, sessions, usernames, and email. Do not implement independent passwords or custom hashing. The actual table prefix is dynamic; never assume `wp_`.

Phone identity is deliberately not authoritative usermeta. Phase 2A creates `${wpdb->prefix}hedayati_user_phones`:

```text
id            bigint unsigned primary key auto-increment
user_id       bigint unsigned not null, UNIQUE
phone_e164    varchar(20) not null, UNIQUE
is_verified   tinyint(1) default 0, indexed
verified_at   datetime nullable
created_at    datetime not null
updated_at    datetime not null
```

The uniqueness constraints make simultaneous duplicate assignment safe at the database level. The service converts a lost write race into a `phone_already_exists`-style error where appropriate. Changing the phone always clears verification; assigning the same unchanged phone preserves its state. Deleting a real WP user registers cleanup of the phone row.

## 7.2 Authentication flow implemented in code

- Core username/email authentication runs normally.
- Phone adapter runs on `authenticate` priority 30, after WordPress username/password callbacks.
- A phone-like identifier is normalized, resolved to a WP user, then authenticated with `wp_authenticate_username_password()` using that user’s real `user_login`.
- Unknown phone and wrong password should return the same generic invalid-credentials error.
- Final rate-limit enforcement runs at priority 90 and can override an otherwise successful result if a bucket is locked.
- `wp_login_failed` is the single authoritative failure increment path to prevent double counting.
- Success clears identifier counters for the canonical username and registered phone, but deliberately does **not** clear the shared IP bucket.
- Defaults are 5 failed attempts per identifier, 30 per IP, 900-second window/lockout; configuration is filterable.

## 7.3 Roles and capabilities

Current custom roles:

- `student` — own portal/profile/enrollments/document upload.
- `teacher_assistant` — assigned runs and assigned roster only.
- `teacher` — assigned runs/roster, assigned sessions, attendance.
- `reception` — student lookup, create/basic-edit enrollments, basic profile view, initiate verification.
- `hedayati_manager` — institute operations: courses, runs, staff, verification, private documents, audit logs, enrollments, settings.
- native `administrator` — technical/system owner and all current Hedayati capabilities.

There is no custom `super_admin` role. WordPress already reserves that concept for Multisite. The roles manager tracks the previous managed-capability list in `hedayati_core_managed_capabilities`, removes obsolete Hedayati capabilities safely, and never removes unrelated core/third-party capabilities.

Roles alone are insufficient for teacher/TA scope. Future services must also verify assignment/ownership on every protected action.

## 7.4 Student verification and profile

Verification is independent from role and phone verification. Approved future identity/profile states are conceptually `unverified`, `pending`, `verified`, and `rejected`/`requires_correction` as needed. No policy has been approved that verification automatically enables certificates, accredited exams, or any other benefit; those unlock rules require institute input.

Planned storage split:

- `wp_users`: username, password hash, email, display name.
- usermeta: address and ordinary extensible profile fields where appropriate.
- phone table: canonical phone and phone verification.
- future verification table: protected national ID representation, identity-review state, reviewer/timestamps/notes.
- future document table: private file metadata and lifecycle.

Identity fields must be normalized server-side before validation/search/storage. Search UI and indexes are not implemented yet.

# 8. DATA NORMALIZATION

The project requires Persian digits (`۰۱۲۳۴۵۶۷۸۹`) and Arabic-Indic digits (`٠١٢٣٤٥٦٧٨٩`) to become ASCII digits (`0123456789`) wherever a field is meant to be canonical/searchable. Frontend conversion may improve UX, but backend normalization is authoritative so API/admin/import paths cannot bypass it.

Phone canonical format is exactly:

```text
+989XXXXXXXXX
```

Accepted equivalent inputs include:

```text
09XXXXXXXXX
9XXXXXXXXX
+989XXXXXXXXX
00989XXXXXXXXX
989XXXXXXXXX
Persian/Arabic digit equivalents
approved formatting: spaces, hyphens, parentheses, dots
```

Unexpected letters, markup, underscores, misplaced/multiple `+`, non-mobile Iranian landlines, wrong lengths, and non-Iranian numbers are rejected rather than stripped into valid-looking values. Canonical regex: `^\+989[0-9]{9}$`.

Equivalent phone forms share one rate-limit identifier. Non-phone usernames are trimmed and lowercased/canonicalized for the rate-limit bucket. Future national ID and other searchable numeric identifiers must follow similarly explicit field-specific normalization; do not apply a blind site-wide digit conversion to prose.

# 9. DATE AND LOCALIZATION REQUIREMENTS

- Site language and primary content: Persian.
- Layout: native RTL at document, component, and responsive levels.
- Mixed Persian/English technical strings (`CCNA`, `IPv4`, commands, course codes) need deliberate bidi treatment; do not reverse or mangle them.
- Canonical dates/datetimes are Gregorian, machine-sortable database values. Existing course dates use validated `YYYY-MM-DD`; future sessions should use canonical datetimes.
- Shamsi/Jalali is a later input/display layer, not database storage.
- Persian/Arabic digit display is a UI choice; canonical stored/searchable values remain ASCII where appropriate.
- Mobile navigation, ordering, alignment, icon placement, form fields, and focus behavior must be tested in RTL—not merely mirrored by setting `dir=rtl`.

# 10. COMPLETE DESIGN SYSTEM

## 10.1 Selected direction

The active visual source is **Concept C / Navigator / NavigatorHome**, evolved from the preferred “Precision” family. It is customer-oriented and helps visitors quickly answer “What do you want to learn?” The Persian idea **«چارچوب»** was also valued as a conceptual motif: structure, framed learning paths, ordered grids, and a dependable educational framework. It may inform copy/visual language, but Concept C/Navigator is the selected concrete reference.

## 10.2 Brand and visual character

- Brand DNA: Dr. Hedayati red, white/warm white, black/charcoal; based on existing institute/Instagram identity.
- Goal: premium, restrained, modern technology institute—not childish, goofy, overly graphic, or generic dashboard UI.
- Red is controlled for emphasis and calls to action; avoid eye-straining saturation across large areas.
- Light and dark modes must both be fully designed, not mechanically inverted.
- Geometry may use frames, lines, grids, and strong composition, but usability and content clarity win over decoration.

## 10.3 Typography

Approved primary typeface: **Vazirmatn**. The AI Studio reference loaded it, while an earlier WordPress build merely named it in the stack without actually shipping it, causing fallback to Tahoma/other local fonts.

Approved production direction is locally self-hosted WOFF2 files—no Google Fonts/CDN dependency—with `font-display: swap`. Intended weights:

- 400 body/content;
- 500 secondary emphasis;
- 600 navigation/buttons/UI;
- 700 card and section headings;
- 800 major headings/hero;
- avoid indiscriminate 900.

Keep monospace treatment only for genuinely technical/code-like Latin content. **Local font loading was planned but not verified implemented**; inspect the repository before claiming it is complete.

## 10.4 Components and interaction

- Cards: clean, confident, capped widths, balanced whitespace, restrained borders/shadows; avoid “admin dashboard” styling on marketing pages.
- Buttons: clear primary/secondary hierarchy, visible hover/focus/disabled states, sufficient targets.
- Motion: subtle and functional; respect `prefers-reduced-motion`.
- Focus: visible keyboard focus; skip link targets the real `#site-main` and main elements support focus.
- Navigation: semantic lists/links, responsive menu, no fake ARIA menubar behavior.
- Logo: use the real WordPress Custom Logo with controlled sizing and CSS glow/wrapper; do not bake the glow irreversibly into the image. A fallback “H” was temporary.
- Scrollbar: thin, rounded, low contrast at rest, stronger on hover, coherent in light/dark; Firefox fallback and default behavior on touch platforms.

## 10.5 Homepage structure

The exact current template must be verified, but the approved/reported structure is:

1. Header with real institute logo, primary navigation, light/dark toggle, responsive mobile control.
2. Navigator-style hero focused on selecting a learning direction/course rather than the rejected slogan “آموزش تخصصی IT، شفاف و کاربردی.”
3. Course-category navigation strip using editable Persian name, English label, plain-text symbol, and order.
4. Featured courses, editable from Course records, max 8, deliberately centered/capped for sparse sets and 4→2→1 grid behavior.
5. Stronger “چرا مجتمع دکتر هدایتی؟” / impact/value section; all claims must be verified rather than copied from prototypes.
6. Focused CTA/consultation band using editable institute contact settings.
7. Footer with logo, navigation/contact data, and future social/business information.

Previously hardcoded claims such as “بیش از دو دهه تجربه,” “مدرک معتبر” for every course, “گواهینامه‌های رسمی و قابل استعلام,” and “مشاوره رایگان” were removed/neutralized unless independently approved by the institute.

## 10.6 Rejected alternatives

- Editorial Redline: premium Swiss/editorial concept; not selected.
- Geometric Identity: strongest direct Instagram/angular influence; not selected.
- Concept A “چارچوب/Framework” and Concept B “محور/Axis”: discussed Precision-family variants; not the active implementation.
- Old prototype visual directions before Precision/C were superseded.

# 11. PAGE INVENTORY

| Page/template | Expected behavior | Status |
|---|---|---|
| Homepage (`front-page.php`) | Navigator hero, categories, featured courses, value section, CTA | IMPLEMENTED; verify latest layout/content |
| Course archive (`archive-course.php`) | browse/filter courses, responsive cards, empty states | IMPLEMENTED |
| Course category (`taxonomy-course-category.php`) | same course browser scoped to term | IMPLEMENTED/FIXED |
| Single course (`single-course.php`) | hero/summary, attributes, intro, syllabus, audience, outcomes, CTA, related courses | IMPLEMENTED |
| Generic singular/archive/index | safe fallback rendering | IMPLEMENTED |
| 404 | branded Persian not-found page | IMPLEMENTED |
| Login | username or phone + password | PLANNED UI; backend partial |
| Student profile/dashboard | profile, verification, documents, enrollments/sessions | PLANNED |
| Teacher/TA portal | only assigned runs/rosters; teacher session/attendance functions | PLANNED |
| Reception panel | lookup, limited profile/enrollment operations | PLANNED |
| Manager/admin panel | operational modules and audit visibility | PLANNED |
| Teacher directory/profile | public Teacher CPT with optional WP linkage | PLANNED Phase 2B/public-content work |
| About | editable institutional content | PLANNED |
| Contact/consultation | locations, phones, form/action | PLANNED; settings foundation exists |
| Blog/articles/categories | inventory/migrate relevant live content and preserve SEO | PLANNED |
| Old specialty pages, exams, certificates, organization info | inventory live site before cutover; migrate/redirect as approved | PLANNED/UNDECIDED per item |

# 12. RESPONSIVE DESIGN

- Desktop course grids target four cards per row where space allows; tablet two; mobile one.
- Sparse featured sets stay centered and capped rather than stretching.
- Wide desktop single-course layout should use available space appropriately; earlier implementation widened the content/sidebar composition after review.
- Mobile header/navigation must be keyboard accessible and RTL-correct.
- Typography scales down without making Persian text cramped; body line height should remain generous (roughly 1.8 was discussed).
- Preserve logical reading/tab order instead of relying only on CSS visual reversal.
- Long technical English labels, Persian prose, prices, dates, and phone numbers must be tested for overflow/bidi behavior.
- Test both themes at common mobile/tablet/desktop widths. Exact breakpoint numbers must be taken from the actual theme CSS, not invented here.

# 13. ADMIN REQUIREMENTS

## Currently available

- Standard WordPress editing for pages/posts/media/menus/logo.
- Course CPT and hierarchical categories.
- Course meta box with structured fields/repeaters and display priority.
- Category English label, symbol, and order.
- Featured course checkbox and ordering.
- Settings → Hedayati for consultation, Tabriz, Tehran phones, and Tabriz address.
- Native Administrator technical access.

## Needs development

- test-user/phone management UI or controlled workflow;
- login UI;
- student search/profile/verification/document review;
- Teacher CPT and account linkage;
- Course Runs, staff assignments, sessions, enrollments;
- scoped teacher/TA/reception/manager interfaces;
- audit log viewer;
- protected document download/delete/archive controls;
- Shamsi date input/display;
- broader homepage/footer/navigation/content settings where staff editing is required.

## Future optional

- SMS configuration and templates;
- payment gateway/accounting integration;
- media/guide/video host integration;
- automated offsite transfer scheduler;
- richer reporting and bulk imports after institute requirements are known.

# 14. STORAGE AND UPLOAD STRATEGY

Sensitive student documents must never be normal public Media Library URLs.

Preferred architecture:

- file bytes outside `public_html` if ParsPack permits PHP access;
- verified protected directory inside the web root only as fallback;
- application-controlled streaming/download after capability plus ownership/scope checks;
- allowlisted MIME types/extensions, size limits, generated storage names, no executable uploads;
- document records store abstract `storage_backend` and `storage_key`, not a permanent public path;
- lifecycle fields include `archive_reference`, `archived_at`, and `deleted_at` as appropriate;
- audit upload/access/review/deletion/archive actions without logging document contents.

The business intends to move sensitive documents from website storage to institute-controlled local storage approximately every 48 hours. This is a desired workflow, not a completed automation or a fully specified retention policy. After transfer, online bytes may be removed while minimal metadata, archive reference, and audit history remain.

A separate media host for public guides/videos was mentioned as a possible future direction. It is not approved as current architecture and should not be conflated with private identity-document storage.

# 15. HOSTING, STAGING AND DEPLOYMENT

## Current arrangement

- Hosting provider for staging: ParsPack WordPress hosting/cPanel.
- Staging: `mystik.ir`, fresh WordPress, PHP changed from 8.1 to 8.3.
- Production: existing `drhedayati.com` remains live on its older Plesk/ASP.NET/MSSQL system until the rebuild is accepted.
- Earlier `dev.drhedayati.com`/Plesk experiments are no longer the current active staging plan.
- LiteSpeed caching may be active; purge after relevant theme/plugin deployments when stale output is suspected.

## Deployment practice learned

- Build separate `hedayati.zip` and `hedayati-core.zip` with the top-level folder immediately containing `style.css` or `hedayati-core.php`.
- PowerShell `Compress-Archive` caused hosting extraction/recognition trouble despite apparently correct listings; `tar -a -c -f` became the reliable packaging method.
- Avoid nested folders such as `hedayati-core-1/hedayati-core/hedayati-core.php`.
- For updates, replacing only the exact theme or plugin folder via cPanel was reliable.
- Replacing active plugin files does not fire activation hooks. Pending migrations also run cheaply on `admin_init`; visiting WordPress Dashboard/Plugins triggered Phase 2A initialization.
- Do not edit production database/options manually merely to hide a migration problem.
- Keep the local Git repository as source of truth; mirror any emergency server-side CSS edit back into it.

## Cutover expectations

Before switching `drhedayati.com`:

- take fresh downloadable backups of the old production files/database and the new staging site;
- inventory old pages, URLs, courses, articles, images, forms, exam/certificate functions, contact details, and SEO metadata;
- prepare migration mapping and redirects;
- complete role/security/privacy/performance/accessibility QA;
- agree on DNS, email, downtime/rollback, cache/TLS, analytics/search-console procedures;
- perform cutover only with explicit owner approval and a rollback path.

Never commit hosting credentials, database passwords, API keys, WordPress salts, encryption keys, or personal student data.

# 16. SECURITY REQUIREMENTS

## Explicit/current requirements

- Use WordPress password and session primitives; never custom password hashing.
- Enforce authorization server-side; hiding buttons is not security.
- Use granular capabilities plus object ownership/assignment scope.
- Use nonces/CSRF protection for state-changing admin/frontend actions.
- Validate and sanitize input; escape output according to context.
- Use prepared SQL and dynamic `$wpdb->prefix`.
- Keep phone identity database-unique.
- Apply privacy-safe authentication errors and rate limiting.
- Store private documents outside public access and serve only after authorization.
- Keep secrets outside Git.
- Preserve sensitive academic/audit history; avoid unsafe cascade deletion.

## Approved future security design

- Dedicated `HEDAYATI_DATA_ENCRYPTION_KEY` in `wp-config.php` or server configuration, never tied directly to a rotatable general WordPress salt.
- Support key versioning/rotation.
- Separate purpose-specific material for reversible national-ID encryption and duplicate-detection keyed HMAC.
- Application-level append-only audit logs; do not call a normal database table “immutable.”
- Define retention/privacy policy for IP/user-agent audit data.

## Recommendations requiring implementation review

- defense-in-depth upload scanning where hosting permits;
- secure response headers, HTTPS-only cookies and downloads;
- explicit data export/deletion/retention procedures aligned with institute/legal requirements;
- monitoring and tested restore procedures.

# 17. SEO, ACCESSIBILITY AND PERFORMANCE

## Requirements supported by project history

- Preserve worthwhile old content, URLs, course information, images, and SEO value; do not blindly start with empty URLs.
- Build redirects for changed production paths.
- Semantic navigation; no inappropriate ARIA application/menubar patterns.
- Working skip link to actual main content; visible keyboard focus.
- Responsive and readable Persian typography with sufficient contrast in both themes.
- Local font hosting to avoid external dependency and availability issues.
- Avoid fake/unverified marketing claims.

## Project-level recommendations, not yet separately approved features

- unique titles/meta descriptions and canonical URLs;
- Open Graph/social metadata and structured data where accurate;
- XML sitemap and Search Console migration checks;
- compressed responsive images, lazy loading below fold, minimized assets, cache strategy;
- WCAG-oriented keyboard, contrast, labels, error messages, reduced motion, and screen-reader testing;
- Lighthouse/Web Vitals baseline before launch.

# 18. CURRENT REPOSITORY / FILE STRUCTURE

The intended structure is shown in Sections 4.1–4.3. The latest inspectable artifact in this handoff is the `hedayati-core` plugin ZIP listed in Section 4.2. Its structure and key Phase 2A implementation were verified directly.

The theme structure is reconstructed from prior inspections and implementation reports. Before modifying it, Claude Code must run `git status`, list repository files, inspect the actual theme/plugin versions, and compare their version headers/content with this document. The earlier repo also contained `implementation_plan.md`, `task.md`, `walkthrough.md`, and `docs/` at various stages; generated walkthroughs are not authoritative unless reconciled with current code.

Expected Git branch was `main` after the WordPress pivot. The former React backend work used `feature/backend-auth` in a different `drhedayati-v2` repository and must not be mistaken for current production work.

# 19. COMPLETED WORK

- [x] Existing site/hosting investigated; old app identified as non-WordPress ASP.NET/MSSQL style.
- [x] Backup-first strategy established; old production kept untouched.
- [x] Multiple Persian RTL visual concepts created and reviewed.
- [x] Concept C/Navigator selected as production visual reference.
- [x] WordPress architecture chosen at institute request.
- [x] New `drhedayati-wordpress` repo structure established with `reference-react/`, `theme/`, and `plugin/`.
- [x] Custom theme and Hedayati Core plugin created.
- [x] Course CPT, course-category taxonomy, term metadata, course metadata, settings, query helpers, and templates implemented.
- [x] Theme/plugin packaging and nested-directory deployment problems diagnosed.
- [x] PHP 8.3 enabled on staging.
- [x] Permalink 404 issue resolved by refreshing permalinks.
- [x] Custom logo rendering/glow issue addressed in CSS.
- [x] Course authoring meta box and CCNA test course deployed.
- [x] Encoded category slug display corrected to term name.
- [x] Related-course no-category bug and empty-section rendering corrected.
- [x] Public layout/responsive polish pass deployed.
- [x] Phase 2 architecture revised with WordPress-specific tables/roles/security decisions.
- [x] Phase 2A code completed: phone normalization, unique phone table, roles/caps, authentication adapter, rate limiting, migrations, user cleanup.
- [x] Latest Phase 2A static verification: 74/74.
- [x] Previously reported Phase 2A PHP suite: 78/78.
- [x] Phase 2A deployed without homepage/course/CCNA regression.
- [x] Admin-triggered migration confirmed; prefixed phone table and version options now exist.

# 20. OPEN WORK / BACKLOG

## P0 — essential/current blockers

1. Perform the complete Phase 2A staging integration test matrix with a disposable student; document results and clean up test data intentionally.
2. Pull/inspect the real current repository and reconcile it with this handoff, especially theme version, local Vazirmatn assets, Git status, and whether server-only edits exist.
3. Commit/tag the exact deployed Phase 2A artifact if not already committed; ensure local repository equals staging code.

## P1 — required for launch

1. Phase 2B academic operations: Teacher CPT, Course Runs, staff assignments, sessions, enrollments, migrations, services, tests.
2. Phase 2C student identity/security: profile storage, verification workflow, dedicated encryption/HMAC design, private documents, audit log, retention/deletion behavior.
3. Phase 2D interfaces: login, student, teacher/TA, reception, manager/admin.
4. Complete public content: teachers, About, Contact/consultation, navigation/footer, homepage management, remaining old-site pages.
5. Shamsi input/display and consistent numeric/bidi handling across all new interfaces.
6. Full old-site content/URL/SEO inventory and migration/redirect plan.
7. Production hardening, accessibility, performance, backups/restore drill, security review, role matrix tests, privacy/retention decisions.
8. Final desktop/tablet/mobile and light/dark QA, followed by controlled `mystik.ir` → `drhedayati.com` cutover.

## P2 — important enhancements

- self-host and tune Vazirmatn if still missing;
- improved editor UX so course fields are not awkwardly below a large Gutenberg canvas;
- operational reporting/search/bulk workflows after staff usage is understood;
- SMS integration once provider/API details exist;
- automated approximately 48-hour private-document transfer and reconciliation;
- SEO structured data/social previews/analytics migration.

## P3 — future/optional

- payment gateway and accounting/refund workflows;
- separate public media host for guides/videos;
- expanded TA privileges only after institute approval;
- richer learning/exam/certificate capabilities after requirements are verified.

# 21. KNOWN BUGS / RISKS / TECHNICAL DEBT

- Phase 2A real WordPress integration remains incomplete despite passing isolated/static tests.
- Latest theme artifact was not directly available during this handoff; reconstructed claims may lag the repo.
- Vazirmatn was declared but not loaded in an earlier theme build; implementation status is unknown.
- Category order defaults to `0`, so unordered categories may float to the front.
- Some theme links may point to not-yet-created `/about/`, `/contact/`, `/consult/` pages.
- CSS verification in one polish pass was only brace balancing, not full standards/stylelint validation.
- Migration lock uses an atomic option and 60-second stale timeout. For the small Phase 2A table it was accepted, but long future migrations may need ownership tokens/stronger locking.
- `admin_init` migration fallback means file replacement requires an admin request; deployments need an explicit post-update migration check.
- Current `_course_price` is a display string; future run tuition must avoid ambiguous conversion and use integer rial.
- Current course teacher/start/price/registration meta will become fallbacks after Course Runs; dual-source drift must be prevented.
- No approved institute policy yet for verification unlocks, mandatory national-ID documents, retention, cancellation/refunds, or expanded TA powers.
- Sensitive-document offsite transfer is a concept without a complete protocol for acknowledgements, failure/retry, deletion timing, and restore.
- Old production URL/content inventory is not complete; cutover before inventory risks lost SEO or business functions.
- Server-side hotfixes could diverge from Git if not reconciled.
- Direct cPanel folder replacement is manual and error-prone; exact backups and artifact verification are essential.

Resolved historical bugs should not be reintroduced: nested ZIP folders; stale PHP version; permalink 404; encoded taxonomy slug display; incorrect taxonomy template; nonexistent Customizer contact controls; fake category ordering; invalid calendar dates; duplicate `dir=rtl`; broken skip target; ARIA menubar misuse; nested excerpt paragraphs; missing logo sizing; dark preference incorrectly persisted as user choice; arbitrary related courses; blank course sections; permissive phone stripping; double failure counting; early auth filter errors overwritten; shared IP bucket cleared on success; missing deletion-hook initialization; unverified migration version advancement; and future-unsafe capability cleanup.

# 22. IMPORTANT HISTORICAL DECISIONS

1. **Backup before rebuild.** The current site supports a real business; production must stay recoverable.
2. **Rebuild rather than patch the legacy application.** The old system’s architecture/editor was too constrained, but useful content/URLs should be migrated.
3. **Use WordPress because the institute requested maintainability.** This outweighed finishing a technically valid custom Node stack.
4. **Separate theme and plugin.** Presentation belongs in `hedayati`; persistent course/domain behavior belongs in `hedayati-core`.
5. **Retain WordPress users.** Avoid a parallel authentication database and use native password/session behavior.
6. **Use a dedicated phone table.** `usermeta` cannot provide the required database-level uniqueness guarantee.
7. **Separate Course from Course Run.** Catalog marketing data and operational cohorts have different lifecycles.
8. **Use custom tables for relational operations.** Runs/sessions/enrollments/documents/audits should not be forced into postmeta for convenience.
9. **Use least privilege.** Hedayati Manager is operations; native Administrator is technical; TA has no attendance by default.
10. **Store canonical Gregorian/ASCII data, localize input/display.** This preserves sorting, indexing, searching, and integrations.
11. **Protect private documents outside the public web root where possible.** URL obscurity or Media Library privacy is insufficient.
12. **Build in small phases.** Identity foundation before academic operations, then sensitive identity/documents, then interfaces.
13. **Keep SMS optional.** Provider uncertainty must not block username/phone + password login.
14. **Preserve working Course fields.** Migrations must be backward compatible; Phase 2B should supersede operational meaning gradually.

# 23. REJECTED OR SUPERSEDED IDEAS

- Completing the React/Express/Prisma/PostgreSQL application and converting later — SUPERSEDED.
- React/Vite prototype as production runtime — SUPERSEDED; visual reference only.
- Google login — explicitly not wanted.
- Separate custom password/auth database — rejected.
- Authoritative phone stored only in usermeta — rejected due to missing DB uniqueness.
- Custom WordPress role named `super_admin` — rejected; conflicts with WordPress terminology.
- Using native `administrator` for ordinary institute operations — rejected; use `hedayati_manager`.
- TA attendance permission by default — rejected pending explicit approval.
- Requiring every TA to have a public Teacher CPT — rejected.
- Course post as both catalog and every operational cohort — rejected.
- Fake Course Run defaults (`capacity=20`, `tuition=0`) — rejected.
- One combined run/registration status — rejected.
- MySQL ENUM for evolving business states — rejected.
- Direct use of `SECURE_AUTH_KEY` as permanent student-data key — rejected.
- Public Media Library URLs for identity documents — rejected.
- Claim that verification automatically enables certificates/accredited exams — rejected as invented policy.
- Calling ordinary audit records “immutable” — rejected; use application-level append-only.
- External Google Fonts in production — rejected in favor of self-hosted Vazirmatn.
- Hardcoded unverified marketing claims — rejected.
- `Compress-Archive` as preferred release packaging for this host — superseded by `tar -a` after extraction issues.

# 24. NON-NEGOTIABLE PROJECT RULES

1. Inspect current code, Git status/diff, versions, and relevant tests before changing anything.
2. Preserve user/unrelated changes and existing working course/public functionality.
3. Treat WordPress architecture as authoritative; do not revive Express/Prisma/PostgreSQL without an explicit new decision.
4. Do not modify `reference-react/` during production implementation unless explicitly requested; use it as visual reference.
5. Keep persistent business logic/data in the plugin, presentation in the theme.
6. Maintain Persian-first RTL, mixed-script correctness, responsive behavior, and both themes.
7. Use WordPress-native authentication/password/session APIs and capability checks.
8. Enforce authorization server-side with ownership/assignment scope, nonces, prepared SQL, validation, sanitization, and contextual escaping.
9. Never hardcode `wp_`, collation, domains, credentials, or secrets.
10. Use versioned, idempotent, verified migrations; never mark a failed migration successful.
11. Do not manually patch database markers to conceal migration failures.
12. Do not expose private student files via public URLs or commit personal data.
13. Do not invent institute facts, policies, prices, capacities, certifications, or verification benefits.
14. Keep canonical phone/date/money rules explicit and tested.
15. Prefer minimal dependencies and WordPress primitives where they meet requirements.
16. Do not make destructive production changes or cut over DNS without explicit approval, verified targets, backups, and rollback.
17. Keep local Git as source of truth; reconcile every server-side edit.
18. After each phase, run proportional tests and distinguish unit/static results from real staging integration.

# 25. RECOMMENDED NEXT IMPLEMENTATION STEPS

1. **Reconcile source of truth.** Open the real `drhedayati-wordpress` repo; inspect status, recent commits, theme/plugin headers, deployed artifact checksums if possible, and server-only edits. Confirm Vazirmatn status.
2. **Finish Phase 2A acceptance.** Create a disposable student and execute the staging matrix listed in Section 2.2. Record exact results; do not test destructive cases against the administrator account.
3. **Close Phase 2A.** Fix only defects found by integration tests, rerun PHP/static checks, redeploy, verify migration/non-regression, commit/tag accepted state.
4. **Design Phase 2B migration in code context.** Re-read the approved Course Run rules, then implement only Teacher CPT, runs, assignments, sessions, enrollments, services, capabilities/scope, migrations, and tests. Do not start documents simultaneously.
5. **Staging-test Phase 2B** with realistic course/run/teacher/TA/enrollment fixtures and lifecycle cases.
6. **Implement Phase 2C** after institute decisions on verification/document requirements and retention. Establish encryption secrets outside Git before storing real national IDs.
7. **Build Phase 2D interfaces** on proven backend services, not mock data.
8. **Complete public content/localization** including teacher pages, About/Contact, Shamsi layer, local fonts, and navigation/footer.
9. **Inventory/migrate old production content and URLs**, then harden and execute full QA.
10. **Plan and approve cutover** with backups, redirects, DNS/email/TLS/cache/rollback checklist.

# 26. HANDOFF TO CLAUDE CODE

You are inheriting a live-staged WordPress rebuild for a real Persian technology institute. The authoritative system is the custom `hedayati` WordPress theme plus `hedayati-core` plugin. The React project is a visual/domain reference. Express/Prisma/PostgreSQL is historical and must not be resumed accidentally.

Treat the checked-out repository and its Git history as source of truth, then use this handoff to explain intent and decision history. Inspect first:

1. `git status`, branch, log, and diff;
2. plugin/theme version headers and bootstrap files;
3. `hedayati-core.php`, migrations, phone/auth/rate-limit/roles services;
4. Course CPT/meta/query helpers and theme course templates;
5. actual theme fonts/CSS/JS and `reference-react/` only for comparison;
6. existing test commands and deployment documentation.

Do not assume that a passing Node mirror proves WordPress behavior, that `wp_` is the prefix, that the latest theme ZIP was captured here, that verification unlocks any particular benefit, that SMS/payment is required, or that historical React backend schema is current.

Before changing code, state the exact phase/scope, inspect existing implementation, identify backward-compatibility and migration implications, and preserve unrelated work. The immediate task is Phase 2A staging integration—not Phase 2B expansion—unless the user explicitly changes that priority.

# CONSISTENCY AUDIT

## Audit 1 — Runtime architecture

**OLD DECISION:** Keep React/Vite and add Express, Prisma, PostgreSQL, and custom backend authentication.  
**CURRENT DECISION:** WordPress core + custom theme + Hedayati Core plugin; WP users/passwords; MySQL custom plugin tables where justified.  
**REASON:** The institute explicitly requires WordPress editability. Converting a completed custom app later would duplicate work.

## Audit 2 — Staging location

**OLD DECISION:** Build locally and deploy to `dev.drhedayati.com` on the existing Plesk hosting.  
**CURRENT DECISION:** Build/test WordPress on ParsPack at `mystik.ir`; leave `drhedayati.com` untouched until cutover.  
**REASON:** The original hosting showed Windows/ASP.NET/MSSQL constraints and the separate ParsPack WordPress environment became available.

## Audit 3 — Production site technology

**OLD ASSUMPTION:** The existing panel might be WordPress.  
**CURRENT UNDERSTANDING:** The live site appears to be a custom ASP.NET/MVC application using MSSQL.  
**REASON:** Plesk filesystem/database evidence contradicted the initial assumption.

## Audit 4 — Phone storage

**OLD OPTION:** Store normalized phone in `wp_usermeta` and enforce uniqueness in PHP.  
**CURRENT DECISION:** Dedicated `${prefix}hedayati_user_phones` table with UNIQUE `user_id` and `phone_e164`.  
**REASON:** Database-level uniqueness is required to prevent registration races and support login identity reliably.

## Audit 5 — Staff roles

**OLD LANGUAGE:** Student, Teacher, TA, Reception, Administrator, Super Administrator; possible custom `super_admin`.  
**CURRENT DECISION:** `student`, `teacher`, `teacher_assistant`, `reception`, `hedayati_manager`, plus native WordPress `administrator`.  
**REASON:** WordPress reserves Super Admin for Multisite; institute operations and technical control require a clean privilege boundary.

## Audit 6 — TA permissions/profile

**OLD OPTION:** TA may record attendance and all run staff assignments may require Teacher CPT.  
**CURRENT DECISION:** TA receives assigned run/roster visibility only; no attendance by default; TA needs a WP user assignment but not a public Teacher CPT.  
**REASON:** Least privilege and avoidance of fake public instructor profiles.

## Audit 7 — Course operational data

**OLD IMPLEMENTATION:** Course meta stores teacher, next start, price, and registration state.  
**CURRENT DECISION:** Preserve those fields now; after Phase 2B, Course Run is operational source of truth and Course meta becomes fallback/catalog display.  
**REASON:** Backward compatibility without permanent dual data entry.

## Audit 8 — Dates

**OLD/PROTOTYPE PRACTICE:** Persian-looking date strings could be treated as stored values.  
**CURRENT DECISION:** Store Gregorian ISO dates/datetimes; render/accept Shamsi later at the UI boundary.  
**REASON:** Reliable sorting, validation, querying, reminders, and integration.

## Audit 9 — Tuition

**OLD OPTION:** Course Run tuition default `0`; existing Course price is a display string.  
**CURRENT DECISION:** Future run tuition is nullable integer rial; unknown is not free. Existing string remains until migrated/fallbacked.  
**REASON:** Avoid invented financial meaning and toman/rial ambiguity.

## Audit 10 — Private storage

**OLD FALLBACK:** Protected folder under `wp-content/uploads`.  
**CURRENT DECISION:** Prefer outside web root; protected web-root storage only after verification as fallback; abstract backend/key metadata supports offsite archive.  
**REASON:** A configuration failure must not expose identity scans, and the institute wants periodic offsite transfer.

## Audit 11 — Encryption key

**OLD OPTION:** Derive national-ID encryption from `SECURE_AUTH_KEY`.  
**CURRENT DECISION:** Dedicated `HEDAYATI_DATA_ENCRYPTION_KEY` outside Git with key versioning and separate HMAC purpose.  
**REASON:** Regenerating WordPress salts must not make business records unreadable.

## Audit 12 — Design selection

**OLD OPTIONS:** Editorial Redline, Geometric Identity, Framework/چارچوب, Axis/محور, Navigator/مسیر and earlier concepts.  
**CURRENT DECISION:** Concept C/NavigatorHome, evolved from Precision, is the implementation reference; framework motifs may influence it.  
**REASON:** It best matched the desired customer journey and owner feedback while retaining the institute’s red/white/charcoal identity.

## Audit 13 — Typography

**OLD OBSERVATION:** Theme listed Vazirmatn in CSS but rendered differently from AI Studio.  
**CURRENT DECISION:** Keep Vazirmatn and self-host approved WOFF2 weights; do not switch typeface merely to compensate for missing files.  
**REASON:** Vazirmatn was the liked prototype font and fits Persian/English technical content. Implementation remains to be verified.

## Audit 14 — Phase 2A completion wording

**POTENTIALLY CONFLICTING CLAIM:** “Phase 2A is complete/deployed.”  
**CURRENT PRECISE STATUS:** Code is implemented and deployed; isolated/static tests pass; migration and public non-regression pass; real authentication/role/uniqueness/rate-limit/deletion integration acceptance remains open.  
**REASON:** Deployment success is not equivalent to complete behavior verification.

## Audit 15 — Database prefix/table name

**OLD EXAMPLE:** `wp_hedayati_user_phones`.  
**CURRENT FACT:** The staging prefix is `vShPz25x_`, producing `vShPz25x_hedayati_user_phones`; code uses `$wpdb->prefix`.  
**REASON:** `wp_` was illustrative only and must never be hardcoded.

## Audit conclusion

No active requirement in this handoff depends on the superseded Node runtime. Current implementation statuses deliberately separate code presence, staging deployment, unit/static verification, and real WordPress integration acceptance. Items that could not be verified from an artifact—especially the latest theme and local font files—are explicitly marked for repository inspection rather than presented as fact.
