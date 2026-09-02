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
  | `reception` | lookup students, create/basic-edit enrollments, basic profile view, initiate verification | `manage_options`, `delete_users`, `edit_theme_options`, course/run management, verify students, private documents, audit logs |
  | `hedayati_manager` | operational: manage courses, teachers, runs, staff assignment, enrollments, verify students, view private documents, view audit logs, manage settings | `manage_options` (no WordPress technical administration) |
  | `administrator` (native) | all of the above **plus** WordPress technical administration | — |

- **No custom `super_admin`** (WordPress reserves that for Multisite). Technical control =
  `administrator`; institute operations = `hedayati_manager`.
- **22 granular `hedayati_*` capabilities** (21 from Phase 2A + `hedayati_manage_teachers` added
  by Phase 2B, roles schema `2.1.0` — D28).
  - **Consumed by Phase 2B** (`class-academic-admin.php`): `hedayati_manage_course_runs` (screen
    access + run/session CRUD), `hedayati_manage_teachers` (Teacher CPT), `hedayati_assign_staff`
    (run staff), `hedayati_create_enrollments` / `hedayati_manage_enrollments` (enrollments),
    `hedayati_record_attendance` (attendance writes). Each state-changing request checks the
    capability **and** a per-run access scope (`Hedayati_Run_Staff_Service::user_is_staff_on_run()`
    for non-managers) — roles alone are not sufficient.
  - **Still defined but not yet consumed:** `hedayati_verify_students`,
    `hedayati_view_private_documents`, `hedayati_view_audit_logs`, `hedayati_initiate_verification`,
    `hedayati_view_own_*` / `hedayati_upload_own_documents`, and the teacher/TA `view_assigned_*`
    caps (the scoped teacher/TA/student portals are Phase 2D). When building those, add the
    ownership/scope check alongside.
- **Academic-operations authorization boundary:** the service classes
  (`Hedayati_*_Service`) are a capability-agnostic data layer — exactly like
  `Hedayati_User_Phone_Service` in Phase 2A. Every capability and nonce check lives in the caller
  (`class-academic-admin.php` today; the Phase 2D portals later). A future REST/AJAX/CLI caller
  MUST repeat those checks.
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
- Deleting a WordPress user triggers `delete_phone` cleanup. Preserve this hook.
- `format-detection: telephone=no` is set; phone links are explicit `tel:` from
  `Hedayati_Settings::tel_uri()`.
- Never log phone numbers, and never place them (or any identifier) in URLs/query strings.

## Sensitive data — planned (Phase 2C, not yet built)

- **Private student documents** (national ID card, birth certificate, etc.):
  - Bytes stored **outside** `public_html` where the host allows PHP access; a verified protected
    directory inside the web root only as a fallback. **Never** a normal Media Library URL.
  - Application-controlled streaming/download **after** capability **and** ownership/scope checks.
  - MIME/extension allowlist, size limits, generated storage names, no executable uploads.
  - Records store an abstract `storage_backend` + `storage_key` (not a permanent public path),
    plus `archive_reference` / `archived_at` / `deleted_at` lifecycle fields.
- **National ID:** reversible encryption with a dedicated **`HEDAYATI_DATA_ENCRYPTION_KEY`** kept
  in `wp-config.php` / server config (outside Git), with key **versioning/rotation** support.
  Duplicate detection uses a **separate keyed HMAC**, not the encryption key. Do **not** derive
  either from `SECURE_AUTH_KEY` or any rotatable WordPress salt (rotating salts must not make
  business records unreadable).
- **Verification** is an independent state (unverified/pending/verified/rejected), separate from
  role and from phone verification. No approved policy yet links verification to any benefit
  (certificates, exams) — do not assume one.
- **Audit logging:** application-level, append-only (do not call a normal DB table "immutable").
  Log upload/access/review/deletion/archive and verification actions — metadata only, never
  document contents. A retention/privacy policy for IP/user-agent audit data is **required but not
  yet decided**.
- The institute intends to move sensitive documents to local storage roughly every 48 hours; after
  transfer the online bytes may be removed while minimal metadata + archive reference + audit
  history remain. Protocol (acknowledgement, retry, deletion timing, restore) is **not specified**.

---

## Secrets & repository hygiene

- Never commit: hosting/cPanel credentials, database passwords, API keys, WordPress salts,
  `HEDAYATI_DATA_ENCRYPTION_KEY` or any encryption/HMAC material, `.env` files, or real student
  personal data.
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
