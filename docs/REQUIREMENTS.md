# REQUIREMENTS.md

Canonical product requirements for the Dr. Hedayati WordPress rebuild, front end and back end.
Derived from `docs/HANDOFF_LEGACY.md` (user intent and historical decisions) reconciled with the
current repository. Status reflects the repository as of 2026-09-02.

**Status legend:** ✅ implemented · 🟡 partial · ⬜ planned · ❓ needs institute decision

---

## 1. Platform & architecture

| # | Requirement | Status |
|---|---|---|
| 1.1 | Built on standard WordPress (≥ 6.6, PHP ≥ 8.3) so staff can edit content without a developer | ✅ |
| 1.2 | Presentation in a custom `hedayati` theme; persistent business logic and data in a `hedayati-core` plugin (survives a theme change) | ✅ |
| 1.3 | WordPress users/passwords/sessions remain the single identity authority — no parallel auth store, no custom hashing | ✅ |
| 1.4 | No React/Vite SPA runtime, no Express/Prisma/PostgreSQL (superseded — see `docs/DECISIONS.md`) | ✅ (not present) |
| 1.5 | Minimal dependencies; WordPress primitives and vanilla PHP/CSS/JS preferred; theme ships no JS libraries | ✅ |
| 1.6 | Never hardcode the `wp_` table prefix, collation, or domain | ✅ |

## 2. Public site — content & pages

| # | Requirement | Status |
|---|---|---|
| 2.1 | Persian-first, natively RTL at document, component, and responsive levels | ✅ |
| 2.2 | Modern, premium institute homepage — "Navigator" direction, helps a visitor answer "what do you want to learn?" | ✅ |
| 2.3 | Light and dark themes, both fully designed (not mechanically inverted); system preference until explicit user choice; no flash of wrong theme | ✅ |
| 2.4 | Browse all courses; filter by hierarchical category | ✅ |
| 2.5 | Per-category archive pages using the human-readable term name (never the encoded slug) | ✅ |
| 2.6 | Course detail page with structured metadata + long-form content, syllabus, audience, outcomes, prerequisites, related courses | ✅ |
| 2.7 | Featured courses on the homepage, staff-selectable, max 8, deliberate layout for sparse sets | ✅ |
| 2.8 | Branded Persian 404 page | ✅ |
| 2.9 | Professional thin custom scrollbar, coherent in both themes, sane touch/Firefox fallback | ✅ |
| 2.10 | About page (editable institutional content) | ⬜ |
| 2.11 | Contact page (locations, phones, map/address) | ⬜ (settings data exists; page does not) |
| 2.12 | Consultation request page + submission handling | ⬜ / ❓ (UX and handler undecided) |
| 2.13 | Blog / articles; inventory and migrate relevant legacy content, preserve SEO | ⬜ |
| 2.14 | Site search across relevant public content | ⬜ / ❓ (exact UX not finalized) |
| 2.15 | Real WordPress Custom Logo with controlled sizing / CSS glow (not baked into the image) | ✅ (support + fallback) / ❓ (real asset upload unverified) |
| 2.16 | Homepage impact/value section — claims must be institute-verified, no fabricated marketing | 🟡 (section exists; stat numbers withheld pending data + mechanism) |

## 3. Design system

| # | Requirement | Status |
|---|---|---|
| 3.1 | Brand palette: Dr. Hedayati red `#c52232`, white/warm white, black/charcoal; red controlled for emphasis/CTAs | ✅ |
| 3.2 | Primary typeface **Vazirmatn**, self-hosted WOFF2, `font-display: swap`, weights 400/500/600/700/800; **no Google Fonts / CDN** | 🟡 (stack references it; files not shipped; falls back to system fonts) |
| 3.3 | Monospace only for genuinely technical Latin/code content | ✅ |
| 3.4 | Cards, buttons with clear primary/secondary hierarchy and visible hover/focus/disabled states | ✅ |
| 3.5 | Subtle, functional motion; respect `prefers-reduced-motion` | ✅ |
| 3.6 | Visible keyboard focus; working skip link to `#site-main`; semantic nav (no ARIA menubar) | ✅ |
| 3.7 | Design tokens centralized (`theme.json` + CSS custom properties) | ✅ |

## 4. Course catalog (data & authoring)

| # | Requirement | Status |
|---|---|---|
| 4.1 | `course` CPT — permanent catalog/marketing identity, public, revisioned, block editor | ✅ |
| 4.2 | Hierarchical `course-category` taxonomy with English label, plain-text icon, integer display order | ✅ |
| 4.3 | Per-course fields: English name, teacher (display), duration, next start date, level, prerequisites, price (display), registration state, featured flag, syllabus[], target audience[], learning outcomes[] | ✅ |
| 4.4 | Dates stored as strict Gregorian `YYYY-MM-DD`; invalid calendar dates rejected | ✅ |
| 4.5 | Registration state validated against `open`/`closed`/`soon`; invalid → `soon` | ✅ |
| 4.6 | Staff authoring UI in Persian with nonce + capability + autosave guards; accessible repeaters | ✅ |
| 4.7 | Explicit numeric display priority (`menu_order`) editable by staff | ✅ |
| 4.8 | Related courses require a shared category; an uncategorized course yields no related results | ✅ |
| 4.9 | Empty structured sections must not render blank decorative boxes | ✅ |
| 4.10 | Course meta not exposed via REST in the current phase (`show_in_rest` false) | ✅ |
| 4.11 | Future: migrate `_course_price` to integer rial when payment is integrated | ⬜ |

## 5. Institute settings

| # | Requirement | Status |
|---|---|---|
| 5.1 | Settings → Hedayati page (Settings API, nonce-protected) for consultation phone, Tabriz phone, Tehran phone, Tabriz address | ✅ |
| 5.2 | Contact data read by theme via a stable accessor; footer/CTA render only configured values | ✅ |
| 5.3 | `tel:` URIs derived safely (preserve leading `+`, strip non-digits) | ✅ |
| 5.4 | Broader editable content (homepage copy, nav, footer, social, map) | ⬜ |

## 6. Identity & authentication (back end)

| # | Requirement | Status |
|---|---|---|
| 6.1 | Log in with username **or** Iranian mobile number, plus password | ✅ (backend) |
| 6.2 | No Google/social login | ✅ |
| 6.3 | SMS **not** required for password login | ✅ |
| 6.4 | Iranian phone normalization to canonical E.164 `+989XXXXXXXXX`; accept `09…`, `9…`, `+98…`, `0098…`, `98…`, Persian & Arabic-Indic digits, common separators; reject (not strip) malformed input | ✅ |
| 6.5 | Phone identity in a dedicated table with DB-level `UNIQUE(user_id)` and `UNIQUE(phone_e164)` — not usermeta | ✅ |
| 6.6 | Changing a phone number always resets verification; re-assigning the same number preserves it | ✅ |
| 6.7 | Unknown phone and wrong password return the same generic error (no user enumeration) | ✅ |
| 6.8 | Rate limiting: per-identifier (default 5) and per-IP (default 30), 900s window, filterable; equivalent phone forms share one bucket; successful login clears identifier bucket but not the shared IP bucket | ✅ |
| 6.9 | Single authoritative failure-count path (`wp_login_failed`), no double counting | ✅ |
| 6.10 | Deleting a WordPress user cleans up the phone row | ✅ |
| 6.11 | Branded login / registration / password-reset UI | ⬜ |
| 6.12 | Future OTP, account recovery, notifications via a provider abstraction | ⬜ / ❓ (provider unknown) |

## 7. Roles & authorization

| # | Requirement | Status |
|---|---|---|
| 7.1 | Custom roles: `student`, `teacher`, `teacher_assistant`, `reception`, `hedayati_manager`; native `administrator` for technical/system ownership | ✅ |
| 7.2 | No custom `super_admin` role (WordPress reserves it for Multisite) | ✅ |
| 7.3 | Granular capabilities (22 `hedayati_*`), least privilege — TA has no attendance by default; reception/manager lack `manage_options` | ✅ (21 in 2A + `hedayati_manage_teachers` in 2B) |
| 7.4 | Versioned, future-safe capability sync; remove only own obsolete caps, never core/third-party | ✅ (schema `2.1.0`) |
| 7.5 | Roles are necessary but not sufficient — every protected operational action must also verify assignment/ownership scope | 🟡 (enforced for Phase 2B academic-ops admin via `Hedayati_Run_Staff_Service::user_is_staff_on_run()` + `require_run_scope()`; Phase 2C/2D surfaces still to come) |

## 8. Students, profiles, verification, documents

| # | Requirement | Status |
|---|---|---|
| 8.1 | WordPress user-based student account | 🟡 (role + phone identity + address profile fields) |
| 8.2 | Profile: phone, email, address, national ID, extensible fields; identity fields normalized server-side before validation/search/storage | 🟡 (address/city/postal in usermeta with an extensible registry + server-side normalization; national ID **blocked** on the D15 encryption key — `docs/OPEN_QUESTIONS.md` Q10) |
| 8.3 | Verification state independent of role and of phone verification; conceptual states unverified/pending/verified/rejected | ⬜ (blocked — reset rules + benefit linkage undecided, Q11) |
| 8.4 | Upload national ID card, birth certificate, other requested documents | ⬜ |
| 8.5 | Student sees enrolled courses/runs and sessions | ⬜ |
| 8.6 | No approved policy that verification unlocks certificates/exams/benefits — requires institute input | ❓ |

## 9. Academic operations (Phase 2B — backend + admin implemented; staging acceptance pending)

Repository implementation is complete on `feature/phase-2b-academic-operations`. Behavioural
acceptance on staging (`docs/PHASE_2B_ACCEPTANCE.md`) is a **pre-merge gate** and is NOT RUN.

| # | Requirement | Status |
|---|---|---|
| 9.1 | `Course Run` separate from catalog `Course` — operational source of truth for teacher(s), start/end, schedule, tuition, capacity, registration state | ✅ repo (`hedayati_course_runs` + `Hedayati_Course_Run_Service`) |
| 9.2 | Nullable capacity and tuition (unknown ≠ 20, unknown ≠ free); tuition stored as integer **rial**, displayed as toman where appropriate | ✅ repo (NULL columns; `parse_optional_nonneg_int`) — toman display is a Phase 2D UI concern |
| 9.3 | Separate `run_status` (draft/scheduled/in_progress/completed/cancelled) from `registration_status` (closed/open/soon); stored as validated strings, not MySQL ENUMs | ✅ repo (`Hedayati_Academic_Validation`) |
| 9.4 | Sessions with canonical `starts_at`/`ends_at` datetimes and `UNIQUE(run_id, session_number)` | ✅ repo (`hedayati_sessions`, `uq_run_session`) |
| 9.5 | Teacher CPT for public instructor identity, optionally linked to a WP user | ✅ repo (`teacher` CPT + `_hedayati_teacher_user_id` 1:1) — not publicly routed and `show_in_rest => false` until the Phase 2D directory (D30/D34) |
| 9.6 | Run staff assignment: primary instructor, additional instructor, TA; instructors need a teacher profile, TAs need a WP staff user but not a public Teacher CPT | ✅ repo (`Hedayati_Run_Staff_Service` enforces the asymmetry) |
| 9.7 | Existing `_course_teacher` / `_course_next_start_date` / `_course_price` / `_course_registration_state` become backward-compatible fallbacks; no permanent dual source of truth | ✅ repo (run layer never writes the meta; theme fallback wiring is Phase 2D) |
| 9.8 | Enrollments per Course Run; attendance per session | ✅ repo (`hedayati_enrollments` `uq_run_user`; `hedayati_attendance` `uq_session_enrollment` + same-run guard) |

## 10. Staff interfaces (Phase 2D — not built)

| # | Requirement | Status |
|---|---|---|
| 10.1 | Reception: student lookup, create/basic-edit enrollments, basic profile view, initiate verification | ⬜ |
| 10.2 | Teacher: assigned runs, rosters, sessions, attendance | ⬜ |
| 10.3 | TA: assigned runs and rosters only; no attendance by default | ⬜ |
| 10.4 | Manager: courses, runs, assignments, enrollments, verification, private documents, settings, audit logs | 🟡 (courses/settings + Phase 2B: teachers, runs, staff, sessions, enrollments via «عملیات آموزشی»; verification/documents/audit still Phase 2C) |
| 10.5 | Audit-log viewer | 🟡 (minimal read-only viewer under «عملیات آموزشی → گزارش رویدادها», `hedayati_view_audit_logs`, filter + paginate; richer UX — export, date range, actor search — is Phase 2D) |

## 11. Data, localization, dates

| # | Requirement | Status |
|---|---|---|
| 11.1 | Canonical stored dates/datetimes are Gregorian, machine-sortable | ✅ (course dates) |
| 11.2 | Shamsi/Jalali is an input/display layer only, never storage | 🟡 (`Hedayati_Jalali` conversion helper + Shamsi shown alongside Gregorian in the Phase 2B admin; storage unchanged. Shamsi input fields + public-site rendering still to come) |
| 11.3 | Persian (`۰-۹`) and Arabic-Indic (`٠-٩`) digits normalized to ASCII wherever a field is canonical/searchable; backend normalization is authoritative | 🟡 (phone; Phase 2B run/session numeric + date fields; Phase 2C postal code — all via `Hedayati_Text`. National ID pending Q10) |
| 11.4 | Field-specific normalization — no blind site-wide digit conversion of prose | ✅ (principle honored) |
| 11.5 | Mixed Persian/English technical strings get deliberate bidi treatment | ✅ |

## 12. Security (see `docs/SECURITY.md`)

| # | Requirement | Status |
|---|---|---|
| 12.1 | WordPress password/session primitives only | ✅ |
| 12.2 | Server-side authorization (capability + object ownership/scope); hiding UI is not security | 🟡 (Phase 2B academic-ops admin enforces capability + per-run scope server-side; Phase 2C/2D surfaces pending) |
| 12.3 | Nonces/CSRF on every state-changing admin/frontend action | ✅ (all current actions incl. every `admin-post.php` academic-ops handler) |
| 12.4 | Validate & sanitize input; escape output per context | ✅ |
| 12.5 | Prepared SQL, dynamic `$wpdb->prefix` | ✅ |
| 12.6 | Phone identity DB-unique | ✅ |
| 12.7 | Privacy-safe auth errors + rate limiting | ✅ |
| 12.8 | Private documents stored outside public access, served only after authorization | ⬜ |
| 12.9 | Dedicated `HEDAYATI_DATA_ENCRYPTION_KEY` (not a rotatable WP salt), key versioning, separate HMAC for duplicate detection | ⬜ |
| 12.10 | Application-level append-only audit logs; retention/privacy policy for IP/UA data | 🟡 (metadata-only append-only log built — `hedayati_audit_log`, migration 2.2.0, wired into every Phase 2B mutation, read-only viewer; **IP/UA fields intentionally omitted** pending the retention policy — Q13) |
| 12.11 | Secrets never in Git; no personal student data in Git | ✅ |

## 13. SEO, accessibility, performance

| # | Requirement | Status |
|---|---|---|
| 13.1 | Preserve worthwhile legacy content, URLs, images, SEO value; build redirects for changed paths | ⬜ |
| 13.2 | Semantic navigation, working skip link, visible focus, no ARIA menubar misuse | ✅ |
| 13.3 | Readable Persian typography, sufficient contrast in both themes, generous line height | ✅ |
| 13.4 | Local font hosting (no external dependency) | ⬜ |
| 13.5 | Unique titles/meta descriptions, canonical URLs, Open Graph, structured data where accurate, XML sitemap | ⬜ |
| 13.6 | Responsive images, lazy loading below the fold, minimized assets, cache strategy | 🟡 (image sizes registered; rest pending) |
| 13.7 | Lighthouse / Web Vitals baseline before launch | ⬜ |

## 14. Deployment & operations (see `docs/DEPLOYMENT.md`)

| # | Requirement | Status |
|---|---|---|
| 14.1 | Build separate `hedayati.zip` / `hedayati-core.zip` with `style.css` / `hedayati-core.php` at the top level; use `tar -a`, not `Compress-Archive` | ✅ (documented practice) |
| 14.2 | Migrations run idempotently; also on `admin_init`; every deploy needs an explicit post-deploy migration check | ✅ / 🟡 (manual check) |
| 14.3 | Never manually patch DB version markers to hide a migration problem | ✅ (rule) |
| 14.4 | Keep local Git as source of truth; reconcile any emergency server-side edit | ⬜ / ❓ (possible drift) |
| 14.5 | Cutover only with backups, URL/content inventory, redirect map, QA, and explicit owner approval + rollback path | ⬜ |
