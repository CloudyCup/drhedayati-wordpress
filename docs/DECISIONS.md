# DECISIONS.md

Architectural and product decision log. Newest decisions can be appended at the end. Each entry:
what was decided, why, and what it replaced. Historical context is reconstructed from
`docs/HANDOFF_LEGACY.md` and verified against the repository.

> **The single most important decision for future contributors:** this project is a **WordPress
> theme + plugin**. The earlier React/Vite + Express/Prisma/PostgreSQL full-stack app is
> **abandoned**. Do not partially or fully reintroduce it. `reference-react/` is visual reference
> only.

---

## D1 — Back up before rebuilding; keep the legacy site live

**Decided:** Take full downloadable backups (files + database) of the legacy production site before
any change, and keep `drhedayati.com` running on its existing stack until an approved cutover.
**Why:** The site supports a real, operating business. Production must stay recoverable and
available throughout the rebuild.

## D2 — Rebuild, don't patch the legacy application

**Decided:** Build a new site rather than extend the legacy custom **ASP.NET MVC + MSSQL**
application (identified from Plesk filesystem/DB evidence: `App_Data`, `bin`, `Content`, `Views`,
`Scripts`).
**Why:** The legacy architecture and its custom editor were too constrained. Worthwhile content,
URLs, and SEO value will be migrated forward instead.

## D3 — Use WordPress (institute requirement)

**Decided:** Standard WordPress + a custom theme + a custom plugin.
**Why:** The institute explicitly requires that staff can edit the whole site themselves without a
developer. This outweighed finishing a technically valid custom stack.
**Replaced:** A custom React SPA as the production runtime.

## D4 — Separate theme (`hedayati`) and plugin (`hedayati-core`)

**Decided:** Presentation and templates in the theme; all persistent domain behavior and data
(CPT, taxonomy, meta, settings, identity, roles, migrations, future operational tables) in the
plugin.
**Why:** Business data and behavior must survive a theme change. The theme reads plugin data only
through stable APIs (`Hedayati_Query::*`, `Hedayati_Settings::*`) and degrades gracefully when the
plugin is inactive.

## D5 — Abandon the Express / Prisma / PostgreSQL backend plan

**Decided:** No Node/Express API server, no Prisma, no PostgreSQL. Use WordPress core +
`register_post_meta` + custom `$wpdb` tables via `dbDelta`, with a versioned migration framework in
`class-db-schema.php`.
**Why:** Once WordPress became a hard requirement (D3), continuing the Node backend and converting
it later would have meant duplicate work and an avoidable migration. Development pivoted
immediately.
**Still valid from that era:** Course vs Course Run separation, relational operational records,
least privilege, server-side authorization, private files outside the web root, normalization,
audit logs, nullable unknown capacity/tuition, safe deletion rules, phased delivery.

## D6 — WordPress is the only identity authority

**Decided:** Use `wp_users`, WordPress password hashing, and WordPress session cookies. No parallel
authentication database, no application-managed hashing (Argon2 etc.).
**Why:** One identity authority; native session/security behavior; no custom crypto to maintain.

## D7 — Dedicated phone-identity table, not usermeta

**Decided:** Store the canonical phone in `{prefix}hedayati_user_phones` with DB-level
`UNIQUE(user_id)` and `UNIQUE(phone_e164)`.
**Why:** `wp_usermeta` cannot guarantee uniqueness against concurrent registration races. A
database constraint can; the service converts a lost race into a `phone_already_exists` error.

## D8 — Canonical Iranian phone format `+989XXXXXXXXX`, strict normalization

**Decided:** One canonical E.164 form (`^\+989[0-9]{9}$`). Accept `09…`, `9…`, `+98…`, `0098…`,
`98…`, Persian/Arabic digits, and common separators — all resolving to the same value / account /
rate-limit bucket. **Reject** malformed input (letters, markup, underscores, bad `+`, landlines,
wrong lengths) rather than stripping it into a valid-looking value.
**Why:** Reliable login identity, safe uniqueness, and predictable rate limiting depend on one
unambiguous representation.

## D9 — Store canonical Gregorian / ASCII data; localize at the UI

**Decided:** Database values are Gregorian ISO dates/datetimes and ASCII digits. Shamsi/Jalali and
Persian/Arabic digits are an input/display layer only. Normalization happens **server-side** so
admin/API/import paths can't bypass it. No blind site-wide digit conversion of prose — each field
gets an explicit rule.
**Why:** Preserves sorting, indexing, searching, validation, reminders, and third-party
integration.

## D10 — Custom roles with least privilege; no custom `super_admin`

**Decided:** `student`, `teacher`, `teacher_assistant`, `reception`, `hedayati_manager`, plus
native `administrator`. 21 granular `hedayati_*` capabilities (raised to 22 by D28 —
`hedayati_manage_teachers`). `hedayati_manager` runs institute operations but has **no**
`manage_options`; `administrator` is the technical/system owner.
**Why:** WordPress reserves "Super Admin" for Multisite. Institute operations and technical
control need a clean boundary. Roles are necessary but not sufficient — services must also check
assignment/ownership scope.
**Replaced:** Earlier "Student / Teacher / TA / Reception / Administrator / Super Administrator"
language with a possible custom `super_admin`.

## D11 — TA gets visibility only; no attendance by default

**Decided:** `teacher_assistant` sees assigned runs and rosters only. Attendance/session
management stays with `teacher`. A TA needs a WP staff user but **not** a public Teacher CPT.
**Why:** Least privilege; avoid fabricated public instructor profiles. Expanded TA powers require
explicit institute approval.

## D12 — Course vs Course Run separation

**Decided:** `Course` (CPT) is the permanent catalog/marketing identity. A future `Course Run` is
the operational source of truth for actual teacher(s), start/end, schedule, tuition, capacity, and
registration state. Current `_course_teacher` / `_course_next_start_date` / `_course_price` /
`_course_registration_state` become backward-compatible fallbacks once runs exist — no permanent
dual data entry.
**Why:** Catalog content and operational cohorts have different lifecycles and owners.

## D13 — Course Run money & status modeling (approved, not built)

**Decided:** Nullable capacity and tuition (unknown ≠ `20`, unknown ≠ free `0`). Tuition stored as
**integer rial**, displayed as toman where appropriate. Separate `run_status`
(draft/scheduled/in_progress/completed/cancelled) from `registration_status`
(closed/open/soon). Business states are validated strings, **not** MySQL ENUMs.
**Why:** Avoid inventing financial meaning, avoid toman/rial ambiguity, and keep evolving business
states migratable.

## D14 — Private documents outside the public web root

**Decided:** Store sensitive student document bytes outside `public_html` where the host permits;
a verified protected web-root directory only as a fallback. Serve only via application-controlled
streaming after capability **and** ownership/scope checks. Records hold an abstract
`storage_backend` + `storage_key`, never a permanent public path. **Never** a Media Library URL.
**Why:** A single misconfiguration must not expose identity scans; the institute also wants
periodic offsite transfer.

## D15 — Dedicated encryption key for national-ID data

**Decided:** A purpose-specific `HEDAYATI_DATA_ENCRYPTION_KEY` in `wp-config.php` / server config
(outside Git), with key versioning/rotation. Reversible national-ID encryption and keyed-HMAC
duplicate detection use **separate** material. Do not derive either from `SECURE_AUTH_KEY` or any
rotatable WordPress salt.
**Why:** Rotating WordPress salts must never make business records unreadable.

## D16 — Audit logs are application-level append-only

**Decided:** Audit trail implemented at the application level as append-only records (not called
"immutable"). Log upload/access/review/deletion/archive and verification actions — metadata only,
never document contents. Retention/privacy policy for IP/UA data is required (institute decision
pending).
**Why:** Honest terminology; a normal DB table is not truly immutable. Sensitive academic/audit
history must be preserved and not cascade-deleted.

## D17 — Design direction: Concept C / "Navigator"

**Decided:** `NavigatorHome` (Concept C, evolved from the "Precision" family) is the implementation
reference, retaining the institute's red/white/charcoal identity. The «چارچوب» (framework) motif
informs visual language.
**Why:** Best matched the desired customer journey ("what do you want to learn?") and owner
feedback.
**Replaced / rejected:** Editorial Redline, Geometric Identity, Concept A "چارچوب/Framework",
Concept B "محور/Axis", and pre-Precision prototypes.

## D18 — Typeface: Vazirmatn, self-hosted

**Decided:** Keep **Vazirmatn** as the primary typeface, self-hosted as WOFF2 (weights
400/500/600/700/800), `font-display: swap`. **No Google Fonts / CDN.**
**Why:** Vazirmatn was the liked prototype font and suits mixed Persian/English technical content;
external font dependencies are an availability and privacy risk.
**Status:** Not yet shipped — no font files in the repo, `functions.php` does not enqueue a font,
the site currently renders in the system-font fallback.

## D19 — No fabricated marketing claims

**Decided:** Remove/withhold unverified claims — "بیش از دو دهه تجربه", "مدرک معتبر" for every
course, "گواهینامه‌های رسمی و قابل استعلام", "مشاوره رایگان", prototype statistics — until the
institute confirms them. The homepage impact section intentionally ships without stat numbers.
**Why:** Accuracy and legal safety; do not invent institute facts.

## D20 — No Google / social login

**Decided:** Authentication is username **or** Iranian phone + password only.
**Why:** Explicit institute requirement.

## D21 — SMS is optional and must not block login

**Decided:** Password login works without SMS. OTP/notifications are a future feature behind a
provider abstraction; the provider is not chosen.
**Why:** Provider uncertainty must not block core authentication.

## D22 — Staging on `mystik.ir` (ParsPack), not `dev.drhedayati.com` (Plesk)

**Decided:** Build and test on a fresh WordPress install at `mystik.ir` on ParsPack hosting; PHP
raised to 8.3.
**Why:** The legacy Plesk hosting showed Windows/ASP.NET/MSSQL constraints; a clean WordPress
environment became available.
**Replaced:** Earlier `dev.drhedayati.com` / Plesk staging experiments.

## D23 — Package releases with `tar -a`, not `Compress-Archive`

**Decided:** Build `hedayati.zip` / `hedayati-core.zip` with `tar -a -c -f`, entry file at the ZIP
root, no nested wrappers.
**Why:** PowerShell `Compress-Archive` produced archives this host mis-extracted / failed to
recognize despite correct-looking listings.

## D24 — Migrations: versioned, idempotent, verified; never fake success

**Decided:** Ordered migrations, atomic option lock with stale recovery, `admin_init` trigger, and
the stored `hedayati_core_db_version` advances **only** after `SHOW TABLES LIKE` verifies the
result. Never hand-patch DB version markers on the server to hide a failed migration.
**Why:** A failed migration marked "done" corrupts the upgrade path silently.

## D25 — Keep local Git as the source of truth

**Decided:** The repository is authoritative. Any emergency server-side edit must be mirrored back
into Git.
**Why:** Manual cPanel deployment makes server/repo drift easy; drift breaks every future deploy
and review.

---

## Decisions recorded during the Claude Code documentation migration (2026-09-02)

## D26 — Permanent documentation structure

**Decided:** Adopt the `AGENTS.md` + `CLAUDE.md` + `README.md` + `docs/*` set defined in
`AGENTS.md` as the canonical project documentation. `docs/HANDOFF_LEGACY.md` is frozen as the
historical source; when it conflicts with these files, the code wins for "what exists" and the
handoff wins for "what was intended".
**Why:** Establish an accurate, maintained source of truth for the migration into Claude Code and
future agents, distinct from the reconstructed historical handoff.

## D27 — `package-plugin/` and root build artifacts are not sources

**Decided:** Treat `package-plugin/hedayati-core/` as a stale pre-Phase-2A copy, and the root
`hedayati-core.zip` and root `drhedayati-wordpress` file as accidental artifacts. Build only from
`plugin/hedayati-core/` and `theme/hedayati/`. Removal is recommended but deferred pending owner
sign-off (this documentation task was scoped "do not delete files").
**Why:** Prevent future contributors or agents from editing or shipping the wrong plugin copy.

---

## Decisions recorded during Phase 2B implementation (2026-09-02)

## D28 — `hedayati_manage_teachers` is a distinct capability

**Decided:** Phase 2B adds a 22nd managed capability, `hedayati_manage_teachers`, granted to
`hedayati_manager` and native `administrator`. The `teacher` CPT maps all of its meta-capabilities
to it. Roles schema version → `2.1.0`.
**Why:** The Phase 2A set has `hedayati_manage_courses` (catalog), `hedayati_manage_course_runs`
and `hedayati_assign_staff`, but nothing teacher-specific. Folding teacher administration into
`hedayati_manage_courses` would couple two lifecycles that may later need different delegation
(e.g. an HR/coordination role that curates instructor profiles but not the course catalogue).
A dedicated capability keeps that option open and matches the "granular, least-privilege" model.
**Replaced:** the implicit assumption (Phase 2A acceptance) that the managed-capability count is
permanently 21. It is now 22; the future-safe sync in `class-roles.php` adds it without touching
any existing assignment.

## D29 — Academic-operations data lives in five custom relational tables, not CPTs/postmeta

**Decided:** `hedayati_course_runs`, `hedayati_run_staff`, `hedayati_sessions`,
`hedayati_enrollments`, `hedayati_attendance` — created by migration `2.1.0`, dynamic-prefixed,
InnoDB/utf8mb4, prepared SQL only. Business states (`run_status`, `registration_status`, session
/ enrollment / attendance status, staff role) are validated `varchar` columns, **not** MySQL
ENUMs. Capacity and tuition are **nullable** (`NULL` = unknown; never a fabricated `0` or `20`).
Tuition is integer **rial**. No database-level foreign keys — referential integrity is enforced
in the service layer plus `deleted_user` / `before_delete_post` cleanup hooks (cross-engine and
legacy-core safety).
**Why:** Direct application of D5 / D12 / D13 and handoff Audit 8 — relational operational records
must not be forced into postmeta, evolving business states must stay migratable, and unknown money
/ capacity must not be invented.
**The `Course` CPT stays authoritative for catalogue identity.** The existing `_course_teacher` /
`_course_next_start_date` / `_course_price` / `_course_registration_state` meta remain as display
fallbacks; they are **not** written from the run layer and no dual data entry is introduced.

## D30 — Teacher CPT is not publicly queryable in Phase 2B

**Decided:** Register `teacher` with `public => false` / `publicly_queryable => false` /
`rewrite => false`. It is an admin-managed record only for now.
**Why:** "Public instructor identity" (a teacher directory / profile pages) is Phase 2D / P1.4
public-content work. Shipping a routable but half-designed public CPT now would expose incomplete
profiles and lock in URLs before the directory is designed. Flipping it public later is a
one-line, reversible change.

## D31 — Course/run deletion cascades operational records; trashing does not

**Decided:** Permanently deleting a `course` deletes its runs and cascades to sessions,
enrollments, attendance and staff rows. Deleting a run cascades the same way. Deleting a WP user
deletes their enrollments (+ that student's attendance) and their TA staff rows, unlinks their
Teacher profile, and nulls `attendance.recorded_by`. **Trashing** a course/run does nothing
(recoverable).
**Why:** Mirrors the Phase 2A `deleted_user` → phone-row cleanup. Orphaned operational rows are
worse than lost ones here (there is no student-facing history in 2B yet). **Caveat for Phase 2C:**
once an append-only audit log exists it must be excluded from every cascade (D16 — academic /
audit history is preserved, not cascade-deleted).

## D32 — Phase 2C: only the mailing-address profile slice was built

**Decided:** implement `Hedayati_Student_Profile` — `hedayati_address` / `hedayati_city` /
`hedayati_postal_code` in `wp_usermeta` (per ROADMAP P1.2), with an extensible field registry
(`hedayati_student_profile_fields` filter), server-side sanitization, Iranian postal-code
digit-normalization + 10-digit validation, and admin fields on the WordPress user-edit screen.
Self-edit gated on `hedayati_edit_own_profile`; other-user access on
`hedayati_view_student_profiles_basic` + core `edit_user`.
**Explicitly deferred** (see `docs/OPEN_QUESTIONS.md` Q10–Q13): national ID (needs the D15
encryption key + HMAC secret provisioned outside Git), the verification state machine (reset rules
+ benefit linkage undecided), private documents (storage location + retention undecided), and the
audit log's IP/UA fields (retention policy undecided).
**Why:** the address is the one part of the profile with no policy landmine; building it now
establishes the usermeta pattern and consumes two previously-unused capabilities without guessing
at any of the unresolved sensitive-data questions.

## D33 — Audit log: metadata-only, append-only, NO ip/user-agent

**Decided:** implement `Hedayati_Audit_Log` + migration `2.2.0` (`{prefix}hedayati_audit_log`).
Columns: `id`, `actor_id`, `action`, `object_type`, `object_id`, `note`, `created_at` — and
**nothing else**. No `ip`, no `user_agent`, no request body, no serialized `context`/`meta` column,
no `updated_at`. `actor_id` / `object_id` are `NOT NULL DEFAULT 0` (0 = system / not-applicable
sentinel). `note` is a bounded `varchar(255)`, PII-free (safe enums like status/role names and
internal record IDs only — never a name, phone, national ID or document reference).
"Append-only" is enforced at the **API** level: `Hedayati_Audit_Log` exposes `record()` (INSERT)
plus read helpers, and **no** update/delete/purge method. The MySQL table is not claimed to be
immutable (per D16's terminology rule). The table is **excluded from every domain deletion
cascade** (D31): audit history outlives the run / enrollment / user it references.
`record()` is called only on the **success** path of each Phase 2B mutation, has a re-entrancy
guard, and fires no WordPress hooks (raw `$wpdb->insert`), so it cannot recurse.
Read access is gated on `hedayati_view_audit_logs`; a minimal read-only viewer ships under
«عملیات آموزشی → گزارش رویدادها». A richer viewer (export, date range, actor search) is Phase 2D
and was **not** invented.
**Why:** D16 approved this subsystem's shape. The *only* unresolved part is the retention/privacy
policy for IP/user-agent data (`docs/OPEN_QUESTIONS.md` Q13) — so those fields simply do not exist
yet. Everything else is safe, and it makes Phase 2B operations auditable now.

## D34 — Teacher CPT is not `show_in_rest` (reinforces D30)

**Decided:** `show_in_rest => false` on the `teacher` CPT (uses the classic editor).
**Why:** a `show_in_rest` CPT serves its **published** posts to anyone via
`/wp-json/wp/v2/<type>` regardless of `public` / `publicly_queryable`
(`WP_REST_Posts_Controller::check_read_permission()` returns true for any `publish`ed post of a
rest-enabled type). That would have leaked teacher names / bios / photos before the Phase 2D
public directory is designed — exactly what D30 set out to avoid. Flipping this back on is part of
the Phase 2D directory work, alongside a deliberate public read design.
