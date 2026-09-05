# SECURITY.md

Project-specific security constraints and sensitive-data handling. **No credentials, secrets, keys,
salts, or real student data belong in this file or anywhere in Git.**

This is an education institute handling real student personal data (names, phone numbers, national
IDs, identity documents). The bar is: a single misconfiguration must not expose identity scans, and
authorization must never depend on hidden UI.

---

## Identity & authentication

- **WordPress is the only identity authority.** Use `wp_users`, WordPress password hashing, and
  WordPress session cookies. **Never** implement a custom password store or custom hashing, and
  never add Google/social login.
- **Dual login** (`class-auth.php`): username/email **or** Iranian mobile + password. The phone
  adapter resolves the number to a real `user_login` and delegates to
  `wp_authenticate_username_password()` — it never verifies passwords itself.
- **No user enumeration:** unknown phone and wrong password both return the *same* generic
  `invalid_credentials` error (`Hedayati_Auth::get_generic_invalid_credentials_error()`).
- **Rate limiting** (`class-rate-limiter.php`, enforced at `authenticate` priority 90):
  - Defaults: **5** failed attempts per identifier, **30** per IP, **900s** window/lockout.
    Filterable via `hedayati_rate_limit_config`.
  - Equivalent phone formats canonicalize to **one** identifier bucket; usernames are trimmed +
    lowercased. Transient keys are truncated SHA-256 (no raw identifier stored).
  - `wp_login_failed` is the **single** place failures are counted (no double counting).
  - On success, the identifier bucket (username + registered phone) is cleared; the **shared IP
    bucket is deliberately NOT cleared** (protects against brute force from shared networks/CGNAT)
    — it expires naturally.
  - Client IP comes from `REMOTE_ADDR` only, `filter_var`-validated. No `X-Forwarded-For` trust.
- **Phone verification lifecycle:** changing a user's number **always** resets `is_verified` /
  `verified_at`; re-assigning the identical number preserves state.
- **No public self-registration** — `Hedayati_Auth_UI` forces `option_users_can_register => false`
  regardless of the stored option. Student accounts are **staff-created only** (Phase 3, D41):
  `reception` / `hedayati_manager` / `administrator` via `Hedayati_Staff_Portal`, gated on
  `hedayati_create_students`.
- **Temporary passwords & forced first-login change** (`Hedayati_Account_Security`, D41):
  - On creation the plugin generates a strong random password
    (`wp_generate_password( 18, true, true )`). WordPress hashes it in `wp_insert_user()`; the
    **plaintext is never persisted** — it lives only in a 45-second single-use transient shown to
    the creating staff member once and deleted on first render.
  - The account carries a boolean `hedayati_must_change_password` usermeta marker (value `'1'` —
    *never* a password). `intercept()` (hooked `template_redirect`, priority 1) redirects a flagged
    user to a mandatory themed password-change screen on every front-end request; no portal/panel
    screen is reachable until the change succeeds.
  - `handle_change()` (`admin_post_hedayati_account_set_password`): nonce + marker gate; min 12
    chars; confirmation must match; must not equal the login or email. `wp_set_password()` then
    clears the marker then re-issues the session. Validation failure is PRG (transient + redirect)
    — no partial render, no uncatchable `exit`.
  - Audit: `account.created` + `account.password_changed` — actor explicit, **never** the password
    or any PII in the note.
  - No email/SMS delivery in Phase 3 (owner decision) — in-person handoff.

---

## Authorization

- **Server-side only.** Hiding a button or menu item is not a control. Every state-changing or
  data-exposing action must check a capability, and — for operational data — also verify
  **object ownership / assignment scope** (a teacher may only touch *their* runs; a student only
  *their* documents). Roles alone are necessary but not sufficient.
- **Roles** (`class-roles.php`) — least privilege:

  | Role | Has | Explicitly lacks |
  |---|---|---|
  | `student` | view own portal, edit own profile, view own enrollments, upload own documents | everything else |
  | `teacher_assistant` | view assigned runs, view assigned roster | attendance, session management, student PII beyond roster |
  | `teacher` | + manage assigned sessions, record attendance | student management, verification, settings |
  | `reception` | lookup students, create/basic-edit enrollments, basic profile view, initiate verification, staff-assisted national-ID/document intake | `manage_options`, `delete_users`, `edit_theme_options`, `edit_user`, course/run management, verify students (decrypt), view/download private documents, audit logs |
  | `hedayati_manager` | operational: manage courses, teachers, runs, staff assignment, enrollments, verify students, view private documents, view audit logs, manage settings | `manage_options` (no WordPress technical administration) |
  | `administrator` (native) | all of the above **plus** WordPress technical administration | — |

- **No custom `super_admin`** (WordPress reserves that for Multisite). Technical control =
  `administrator`; institute operations = `hedayati_manager`.
- **23 granular `hedayati_*` capabilities** (21 from Phase 2A + `hedayati_manage_teachers` from
  Phase 2B (D28) + `hedayati_upload_student_documents` from Phase 2C (D40), roles schema `2.2.0`).
  - **Consumed by Phase 2B** (`class-academic-admin.php`): `hedayati_manage_course_runs` (screen
    access + run/session CRUD), `hedayati_manage_teachers` (Teacher CPT), `hedayati_assign_staff`
    (run staff), `hedayati_create_enrollments` / `hedayati_manage_enrollments` (enrollments),
    `hedayati_record_attendance` (attendance writes). Each state-changing request checks the
    capability **and** a per-run access scope (`Hedayati_Run_Staff_Service::user_is_staff_on_run()`
    for non-managers) — roles alone are not sufficient.
  - **Consumed by the Phase 2C foundation** (`class-student-profile.php`):
    `hedayati_edit_own_profile` (a user editing their own address fields) and
    `hedayati_view_student_profiles_basic` (staff viewing/editing another user's fields, in
    addition to core `edit_user`).
  - **Consumed by Phase 2C identity/verification/documents** (`class-student-admin.php`,
    `class-verification-service.php`, `class-document-service.php`):
    `hedayati_initiate_verification` (reception/manager — start a review), `hedayati_verify_students`
    (manager/admin only — approve/reject **and** the one path that may decrypt a national ID, checked
    both in the controller and, deliberately, inside `Hedayati_Verification_Service` itself — D36),
    `hedayati_view_private_documents` (manager/admin only — download/archive/purge documents),
    `hedayati_upload_student_documents` (reception/manager only — staff-assisted national-ID
    intake + document upload on behalf of a student, plus a target-must-hold-`student`-role scope
    check; deliberately **not** `edit_user` — D40). `hedayati_upload_own_documents` /
    `hedayati_view_own_portal` are wired into the service authorization *contracts* (ready for a
    Phase 2D portal caller) but have no reachable caller yet — Phase 2C ships no student-facing UI.
  - **Still defined but not yet consumed:** the teacher/TA `view_assigned_*` caps and
    `hedayati_view_own_enrollments` await the scoped teacher/TA/student portals (Phase 2D). When
    building those, add the ownership/scope check alongside.
- **Academic-operations authorization boundary:** the service classes
  (`Hedayati_*_Service`, `Hedayati_Audit_Log`) are a capability-agnostic data layer — exactly like
  `Hedayati_User_Phone_Service` in Phase 2A. Every capability and nonce check lives in the caller
  (`class-academic-admin.php` / `class-student-admin.php` today; `class-student-portal.php` —
  Phase 2D, `feature/phase-2d-account-shell`, not merged — for the front-end self-service caller).
  `Hedayati_Student_Portal` additionally derives the owner from `get_current_user_id()` only, never
  a request parameter, and performs its own explicit ownership check
  (`$doc['user_id'] === get_current_user_id()`) before every document action — it deliberately does
  not reuse `Hedayati_Student_Admin::require_student_scope()`, which is correct only for
  reception/manager's intentionally unscoped mandate (`docs/PHASE_2D_PLANNING.md` §9). A future
  REST/AJAX/CLI caller MUST repeat those checks. `Hedayati_Audit_Log::current_user_can_view()`
  (→ `hedayati_view_audit_logs`) is provided for read callers; the shipped viewer calls it.
  **`Hedayati_Verification_Service::get_national_id_decrypted()` is the one deliberate exception**
  to this boundary (D36): it enforces `hedayati_verify_students` inside the service itself, in
  addition to the controller — defense in depth for the single highest-risk read in the plugin.
  Do not "fix" this back to the general capability-agnostic pattern.
- **Capability sync is future-safe:** on version bump the plugin removes only capabilities it
  previously managed (tracked in `hedayati_core_managed_capabilities`) — never core or
  third-party caps.
- CPT `course` uses `capability_type => 'post'`; course meta `auth_callback` is
  `current_user_can('edit_post', $object_id)`.

---

## Input / output / database

- **Nonces** on every custom state-changing action: course meta box
  (`hedayati_course_meta_save`), term meta (`hedayati_term_meta_save`), Teacher meta box
  (`hedayati_teacher_meta_save`), and every academic-operations action routed through
  `admin-post.php` (`hedayati_run_save`, `hedayati_staff_assign`, `hedayati_session_save`,
  `hedayati_enroll_add`, `hedayati_attendance_save`, … — each with its own nonce + capability
  check + per-run scope check in `Hedayati_Academic_Admin::verify()` / `require_run_scope()`); the
  Settings API handles its own nonces.
- **Sanitize on input** with context-appropriate callbacks (`sanitize_text_field`,
  `sanitize_textarea_field`, `absint`, `rest_sanitize_boolean`, custom allowlist/date/array/icon
  sanitizers). Meta sanitizers run both at `register_post_meta` level and again in the meta-box
  save.
- **Escape on output** by context: `esc_html`, `esc_attr`, `esc_url`, `esc_textarea`,
  `nl2br(esc_html(...))` for stored multi-line text. The only raw echo of markup is the curated
  inline-SVG icon map in `hero-navigator.php` (static strings defined in the file, not user input).
- **All SQL is prepared** (`$wpdb->prepare`) and uses **`$wpdb->prefix`** — never a literal `wp_`.
  Table name from `Hedayati_DB_Schema::get_table_user_phones()`.
- **Schema changes** go through the versioned migration framework only: idempotent, ordered,
  atomic-locked, and the stored version advances **only after `SHOW TABLES LIKE` verifies success**.
  Never mark a failed migration successful; never hand-edit `hedayati_core_db_version` to hide a
  problem.
- Phone table uniqueness is enforced at the **database** level (`UNIQUE(user_id)`,
  `UNIQUE(phone_e164)`), not just in PHP. Phase 2B adds DB-level `UNIQUE` on
  `hedayati_sessions(run_id, session_number)`, `hedayati_enrollments(run_id, user_id)` and
  `hedayati_attendance(session_id, enrollment_id)`; run-staff uniqueness is service-enforced only
  (nullable ref columns). Attendance writes additionally verify the enrollment and session belong
  to the same run (IDOR guard).

---

## Sensitive data — current

- `hedayati_user_phones` holds real phone numbers. Access it only through
  `Hedayati_User_Phone_Service` (prepared statements, normalization, race handling).
- Phase 2C stores a student **mailing address** (`hedayati_address` / `hedayati_city` /
  `hedayati_postal_code`) in `wp_usermeta` — standard-sensitivity contact PII, `show_in_rest`
  false, capability-gated read/write via `Hedayati_Student_Profile`.
- **National ID** (`hedayati_student_verification.national_id_enc`, D36): encrypted at rest
  (AES-256-GCM via `Hedayati_Crypto`), with a separate keyed-HMAC fingerprint
  (`national_id_hmac`) for DB-level duplicate detection. `HEDAYATI_DATA_ENCRYPTION_KEY` /
  `HEDAYATI_DATA_HMAC_KEY` must each be a base64 string decoding to exactly 32 raw bytes, defined
  in `wp-config.php` / server config **outside Git**; a missing or malformed key means the feature
  fails closed — no plaintext fallback exists anywhere. **Only** `hedayati_verify_students` may
  decrypt a value, through the one narrow "نمایش شناسه ملی" admin action (POST-only, nonced,
  no-store headers, audited without the value) — students, reception, teachers and TAs can never
  see a decrypted national ID, including their own.
- **Verification** (`hedayati_student_verification.status`, D37): `unverified` / `pending` /
  `verified` / `rejected` with an **enforced** transition table (`Hedayati_Verification_Service`)
  — `initiate()`/`approve()`/`reject()` refuse illegal transitions rather than allowing any value
  at any time. A legal first/last-name change resets a `verified` record; phone/address/email
  changes do not.
- **Private documents** (`hedayati_documents`, D38): bytes never touch this table or any public
  path — `Hedayati_Document_Storage` resolves an outside-webroot root (required on
  staging/production; the protected `wp-content` fallback is local/Docker-CI only), validates
  real content (not client-declared MIME) against a PDF/JPEG/PNG allowlist, and canonicalizes +
  containment-checks every storage-key lookup before touching the filesystem. Retention is
  manual-only: a staff "archived" confirmation, a computed 7-day purge-eligibility window, and an
  explicit staff purge action — never a cron job.
- Deleting a WordPress user triggers `delete_phone` cleanup plus the Phase 2B cleanup hooks
  (enrollments + that student's attendance deleted; TA staff rows removed; Teacher profile
  unlinked; `attendance.recorded_by` nulled). Preserve these hooks.
- `format-detection: telephone=no` is set; phone links are explicit `tel:` from
  `Hedayati_Settings::tel_uri()`.
- Never log phone numbers, and never place them (or any identifier) in URLs/query strings.
- **Audit log** (`hedayati_audit_log`, D33): metadata only — actor id, dotted action, object
  type/id, a short PII-free `note`, UTC timestamp. **No ip, no user-agent, ever** (D39 — a
  permanent decision, not a policy pending resolution), no `updated_at`, no serialized context.
  `note` may contain safe enums (status / role names) and internal record ids (`user #45`,
  `run #12`) for the authorized reader (`hedayati_view_audit_logs`) — **never** a name, phone,
  national ID, or document reference/filename. `Hedayati_Audit_Log` has no update/delete method
  (append-only at the API); the table is excluded from every deletion cascade so history survives
  the objects it references.
- **`teacher` CPT is `show_in_rest => false`** (D34) — a rest-enabled CPT leaks its published
  posts via `/wp-json` regardless of `public`/`publicly_queryable`.

---

## Secrets & repository hygiene

- Never commit: hosting/cPanel credentials, database passwords, API keys, WordPress salts,
  `HEDAYATI_DATA_ENCRYPTION_KEY` / `HEDAYATI_DATA_HMAC_KEY` or any encryption/HMAC material, real
  private student documents, `.env` files, or real student personal data. The only exception is
  the throwaway, committed, test-only key pair in `docker/docker-compose.yml` — scoped explicitly
  to the disposable Docker-CI containers, never usable against a real database (see D36).
- `.gitignore` already excludes `.env*` (except `.env.example`), `node_modules/`, `vendor/`,
  `*.zip`, `*.rar`, build dirs, `wp-content/uploads/`, `*.log`.
- `reference-react/AUDIT_NOTES.md` records that the prototype's `.env` (dev credentials) and git
  history were stripped before import — keep it that way.
- Mirror any emergency server-side edit back into Git; the local repo is the source of truth.

---

## Transport / hosting (recommended, verify at deploy)

- HTTPS only; secure + `HttpOnly` cookies; downloads over HTTPS.
- Secure response headers.
- Defense-in-depth upload scanning where the host permits.
- Tested backup/restore procedure before cutover.
- LiteSpeed cache: never cache authenticated/account pages; purge after theme/plugin deploys.
