# Phase 2A — Staging Acceptance Test Plan & Log (`mystik.ir`)

**Goal:** establish a *verified* Phase 2A baseline before any Phase 2B work.
**Scope:** the `hedayati-core` plugin identity foundation + `hedayati` theme, as deployed to staging.
**Constraints:** no Phase 2B, no automatic deploy, no destructive DB change, no production
(`drhedayati.com`) contact.
**Repo reference point:** `main` @ `6436446` — plugin `1.1.0`, theme `1.0.0`, DB & roles schema
`2.0.0`. (Plugin/theme application source is unchanged since `37afe2b`; commits after it are
docs-only.)
**Note on execution:** Claude cannot log into wp-admin or the hosting panel (entering a password is
a prohibited action). For every authenticated step the **operator drives**; Claude supplies exact
clicks / SQL / snippets and interprets the output pasted back. "Browser" below means *the
operator's authenticated browser*.

---

## Test log

| Test | Area | Category | Status | Date | Notes |
|---|---|---|---|---|---|
| T1.1 | Active plugin version | 1 read-only | ✅ PASS | 2026-09-02 | Hedayati Core **1.1.0**, active — matches repo `HEDAYATI_CORE_VERSION` |
| T1.2 | Active theme version | 1 read-only | ✅ PASS | 2026-09-02 | Hedayati theme **1.0.0**, active — matches repo `style.css` / `HEDAYATI_VERSION` |
| T1.3 | Staging code vs repository | 1 read-only | ✅ PASS | 2026-09-02 | deployed plugin (18 files) + theme (23 files) byte-identical to `main` @ `6436446` after CRLF/LF normalization; 0 different, 0 staging-only, 0 repo-only, 0 junk |
| T1.4 | Custom roles visible in UI | 1 read-only | ⬜ not started | — | |
| T1.5 | Existing admin can still log in / keeps `manage_options` | 1 read-only | ⬜ not started | — | |
| T1.6 | Verification-flag implementation (doc) | 1 read-only | ⬜ not started | — | conclusion recorded below; confirm vs T1.3 files |
| T2.1–T2.9 | Disposable test user | 2 | ⬜ blocked | — | needs operator go-ahead (N3) |
| T3.1–T3.16 | Database / phone provisioning | 3 | ⬜ blocked | — | needs DB access (N4) + WP-CLI or harness (N5) |
| T4.1–T4.8 | Potentially destructive | 4 | ⛔ hold | — | explicit per-test approval required |

**Baseline status: NOT YET ESTABLISHED.** Code/version identity and the code-match check are
confirmed (T1.1–T1.3); all runtime verification (Categories 2–4) remains outstanding.

---

## 0. Findings from code inspection that shape this plan

1. **Nothing in the shipped code writes to `{prefix}hedayati_user_phones` from the browser.**
   `Hedayati_User_Phone_Service::assign_phone() / update_phone() / verify_phone()` have **zero
   callers** anywhere in the plugin (no admin screen, no REST route, no WP-CLI command, no AJAX
   handler, no shortcode, no registration hook). The only automatic writer is the `deleted_user`
   hook → `delete_phone()`.
   → **Consequence:** phone-login, uniqueness, phone-rate-limit, phone-lifecycle, and
   verification-flag tests **cannot be run through wp-admin alone**. They need either direct DB
   access or a temporary code path (`wp shell` / `wp eval`, or a short-lived admin-only
   must-use-plugin harness). This is the single biggest gating dependency.

2. **Migrations and role sync run on `admin_init`** (`Hedayati_DB_Schema::maybe_migrate`,
   `Hedayati_Roles::maybe_sync_roles`), version-gated. "Trigger the migration" = "log into
   wp-admin as any administrator and load a dashboard page." Replacing plugin files does *not* fire
   the activation hook.

3. **Rate limiter is transient-backed**, keys `_transient_hd_rl_ip_<sha256[:24]>` and
   `_transient_hd_rl_id_<sha256[:24]>`, TTL = `lockout_seconds` (default **900s / 15 min**).
   Lockout state clears by waiting out the TTL or deleting those transients.

4. **Client IP = `$_SERVER['REMOTE_ADDR']` only** (`Hedayati_Rate_Limiter::get_client_ip`), no
   `X-Forwarded-For`. On shared hosting / behind LiteSpeed, many testers may present one
   `REMOTE_ADDR`; the **30-per-IP** bucket can trip during a long session and lock the whole
   office. Plan IP-bucket tests carefully (Category 4).

5. **Generic-error behavior is phone-path only.** `Hedayati_Auth::authenticate_phone` returns the
   identical `invalid_credentials` WP_Error for (a) un-normalizable phone, (b) phone not found,
   (c) correct phone + wrong password. A **non-phone** unknown username still returns WordPress's
   native `invalid_username` / `incorrect_password`. This matches the handoff ("unknown *phone* and
   wrong password return the same generic error") but should be recorded explicitly as the
   as-built behavior.

6. **The rate-limit filter at priority 90 runs for every login**, phone or username — so username
   logins are rate-limited too.

7. **No git remote is configured** on the repo, and there are **no release tags**. "Does staging
   match the repo?" can only be answered by file comparison (T1.3) or by checksums computed on the
   server.

---

## 1. Access / information needed

| # | Item | Needed for | Status |
|---|---|---|---|
| N1 | Staging `wp-admin` URL + an operator who can log in as administrator | almost everything | ✅ have operator |
| N2 | Read access to the deployed theme + plugin files (cPanel File Manager / SFTP / a zip), **or** ability to run a checksum command on the server | T1.3 | ✅ received (2 ZIPs, 2026-09-02) |
| N3 | Go-ahead to create **one** disposable `student` user (Claude supplies username/email) | Category 2 | ⬜ pending |
| N4 | Staging **database** read access (phpMyAdmin / Adminer / `wp db query`), or willingness to run ~10 `SELECT` / `SHOW` statements Claude provides and paste results | Category 3 | ⬜ pending |
| N5 | **One** of: (a) WP-CLI access (`wp shell` / `wp eval`), or (b) approval to install a temporary, admin-only, nonce-protected **must-use plugin harness** (Claude writes it; it only calls the existing `Hedayati_User_Phone_Service` methods; deleted afterward) | Category 3 phone tests | ⬜ pending |
| N6 | Confirmation of the **actual table prefix** on staging (from N4) | all SQL | ⬜ pending |
| N7 | Whether a persistent object cache (LiteSpeed / Redis) is active for `wp_options` / transients | E2, E4 accuracy | ⬜ pending |
| N8 | Confirmation that staging has **no real users / no live traffic** during testing | Category 4, IP-bucket safety | ⬜ pending |
| N9 | A **fresh full staging backup** (files + DB) before Category 3 writes; mandatory before any Category 4 test | Categories 3–4 | ⬜ pending |
| N10 | Explicit per-test written approval for anything in Category 4 | Category 4 | ⬜ pending |

---

## 2. Test categories

- **Category 1 — SAFE READ-ONLY.** No writes. No test user. No rollback needed.
- **Category 2 — SAFE WITH A DISPOSABLE TEST USER.** One throwaway user + login-attempt side
  effects (rate-limit transients). Fully reversible.
- **Category 3 — DATABASE-ACCESS REQUIRED.** DB reads, and for phone provisioning either WP-CLI or
  a temporary harness (N5). Writes limited to test rows + transients + the temporary harness file;
  all cleaned up.
- **Category 4 — POTENTIALLY DESTRUCTIVE — DO NOT RUN WITHOUT EXPLICIT APPROVAL.**

Risk levels: **None / Very low / Low / Medium / High.**

---

## CATEGORY 1 — SAFE READ-ONLY

### T1.1 — Active plugin version  ✅ PASS (2026-09-02)
- **Purpose:** confirm the deployed `hedayati-core` is `1.1.0` (the Phase 2A build).
- **Steps:** wp-admin → Plugins → "Hedayati Core" → read version; also `hedayati-core.php` header
  if file access is available.
- **Expected:** `Version: 1.1.0`, `HEDAYATI_CORE_VERSION` = `1.1.0`, plugin **Active**.
- **Browser:** yes. **DB:** no. **Risk:** None. **Cleanup:** none.
- **Result:** PASS — Hedayati Core 1.1.0, active. Matches repo.

### T1.2 — Active theme version  ✅ PASS (2026-09-02)
- **Purpose:** confirm the deployed `hedayati` theme is `1.0.0` and active.
- **Steps:** wp-admin → Appearance → Themes → "Hedayati" → Theme Details; or Tools → Site Health →
  Info → Theme. Read `style.css` header if file access is available.
- **Expected:** `Version: 1.0.0`, active theme = `hedayati`.
- **Browser:** yes. **DB:** no. **Risk:** None. **Cleanup:** none.
- **Result:** PASS — Hedayati theme 1.0.0, active. Matches repo.

### T1.3 — Staging code vs repository  ✅ PASS (2026-09-02)
- **Purpose:** verify the deployed theme + plugin match the authoritative repository source
  (no server-only hotfixes — the handoff flags this risk).
- **Baseline used:** repository `main` @ `6436446aca63c2a537a076d04c632111a25afcbd`
  (working tree clean for `plugin/` and `theme/`).
- **Staging inputs:** two ZIPs the operator downloaded from `mystik.ir` on 2026-09-02, holding
  `wp-content/plugins/hedayati-core/` and `wp-content/themes/hedayati/`.
- **How the comparison was performed:**
  1. Archives identified by **content, not filename**: the plugin ZIP contains
     `hedayati-core/hedayati-core.php` + the `includes/class-*.php` set (incl. `class-auth`,
     `class-phone`, `class-roles`, `class-db-schema`, `class-user-phone-service`,
     `class-rate-limiter`); the theme ZIP contains `hedayati/style.css` (theme header) +
     `theme.json` + templates.
  2. Both ZIPs extracted to a **temporary scratch directory** — never over the repository; the
     downloaded archives were not modified.
  3. Pristine repository copies obtained with `git archive HEAD plugin/hedayati-core` /
     `git archive HEAD theme/hedayati`.
  4. Compared with `diff -ru --strip-trailing-cr` (line-ending normalized) and, independently,
     by per-file SHA-256 after stripping `\r`.
  5. File inventories compared with `comm` to find staging-only / repo-only entries; a junk scan
     (`.DS_Store`, `Thumbs.db`, `*~`, `*.bak`, `*.swp`, `*.log`, `debug*`, `*.zip`, `__MACOSX`)
     was run over the extracted staging trees.
- **Result:**
  - **Plugin:** 18 files each side. 18 identical, 0 different, 0 staging-only, 0 repo-only.
  - **Theme:** 23 files each side. 23 identical, 0 different, 0 staging-only, 0 repo-only.
  - **Junk / non-source:** none inside either deployed directory.
  - Deployed plugin header = `Version: 1.1.0` / `HEDAYATI_CORE_VERSION 1.1.0` /
    `CURRENT_DB_VERSION 2.0.0` / `ROLES_VERSION 2.0.0`; deployed theme = `Version: 1.0.0` /
    `HEDAYATI_VERSION 1.0.0` — matching T1.1 / T1.2.
  - Only raw-byte difference: line endings — staging files are **LF**, the repo working copy /
    `git archive` output on the Windows test machine is **CRLF** (`core.autocrlf`); committed
    blobs are LF. Cosmetic, normalized away as instructed.
- **Verdict: PASS** — the application source deployed on `mystik.ir` matches the authoritative
  repository at `6436446`, aside from line-ending representation.
- **Browser:** n/a (operator downloaded; comparison done locally). **DB:** no. **Risk:** None
  (read-only; archives left untouched).
- **Cleanup:** temporary extraction directory removed after comparison. The operator's
  `staging-export/` copies were left as-is (git-ignored via `*.zip`).

### T1.4 — Custom roles appear in the UI  ⬜
- **Purpose:** first-pass confirmation that role registration ran.
- **Steps:** wp-admin → Users → Add New → open the **Role** dropdown; and Users → All Users →
  "Change role to…" dropdown.
- **Expected:** lists **دانشجو (student)**, **استادیار / پشتیبان آموزشی (teacher_assistant)**,
  **مدرس (teacher)**, **پذیرش و ثبت‌نام (reception)**, **مدیر آموزش مجتمع (hedayati_manager)** plus
  WordPress defaults.
- **Browser:** yes. **DB:** no. **Risk:** None. **Cleanup:** none (do not add a user here).

### T1.5 — Existing administrator can still log in and reach admin  ⬜
- **Purpose:** confirm the auth filter chain (`authenticate` @30 + @90) has not broken normal
  admin login or capabilities.
- **Steps:** operator logs into wp-admin with the existing admin account; confirms Dashboard,
  Settings, Plugins, Users, and Settings → Hedayati all load.
- **Expected:** normal login; `manage_options` intact; no PHP notice/error in the admin footer or
  `debug.log`.
- **Browser:** yes. **DB:** no. **Risk:** Very low (a fumbled password contributes to the rate
  limiter — notes 3/4). **Cleanup:** none if login succeeds first try; else transient cleanup
  (T3.9) or a 15-min wait.

### T1.6 — Document verification-flag implementation (code review)  ⬜
- **Purpose:** record exactly what "verification" exists in Phase 2A.
- **Steps:** none on staging — restated here for the baseline record; confirm against the deployed
  files in T1.3.
- **Result (as built in `1.1.0`):**
  - **Implemented:** `is_verified TINYINT(1) DEFAULT 0` and `verified_at DATETIME NULL` columns;
    `KEY idx_is_verified`; `Hedayati_User_Phone_Service::verify_phone($user_id, $verified_at=null)`
    (sets `is_verified=1`, `verified_at`, only if a row exists); `assign_phone()` optional
    `$is_verified` initial-state param; **automatic reset to unverified whenever the phone number
    changes** in `update_phone()`.
  - **Not implemented / planned:** any *trigger* for `verify_phone()` — no OTP, no SMS, no admin
    "mark verified" control, no reception "initiate verification" flow; no UI surfacing
    verification status; no feature gated on it. Capabilities `hedayati_initiate_verification` and
    `hedayati_verify_students` exist but nothing consumes them. Student *identity* verification
    (national ID, documents, review states) is entirely Phase 2C.
- **Browser:** n/a. **DB:** no. **Risk:** None.

---

## CATEGORY 2 — SAFE WITH A DISPOSABLE TEST USER

> Provision once: **T2.1**. Tear down at the end: **T2.9**. Browser-only and reversible.
> Login-failure tests write rate-limit transients that self-expire in 15 minutes (or delete via
> T3.9).

### T2.1 — Create the disposable test user
- **Purpose:** provide a safe subject for auth / role / lifecycle tests.
- **Steps:** wp-admin → Users → Add New. Username `qa_phase2a`, email a mailbox you control, known
  strong password, role **student**. Record the user ID.
- **Expected:** user created; appears as دانشجو.
- **Browser:** yes. **DB:** no. **Risk:** Very low. **Cleanup:** T2.9.

### T2.2 — Normal username + password login (test user)
- **Purpose:** username path works for a non-admin custom role.
- **Steps:** log out; at `wp-login.php` enter `qa_phase2a` + correct password.
- **Expected:** login succeeds (student has `read` only).
- **Browser:** yes. **DB:** no. **Risk:** Very low. **Cleanup:** log out.

### T2.3 — Wrong password → error + failure recorded
- **Purpose:** confirm failure messaging and single-count behavior.
- **Steps:** enter `qa_phase2a` + wrong password, once.
- **Expected:** rejected with WordPress core `incorrect_password` (username path). One failure
  recorded (verify in T3.7).
- **Browser:** yes. **DB:** for the counter, yes (T3.7). **Risk:** Low (rate-limit buckets).
- **Cleanup:** T3.9 or wait 15 min. Keep total failures < 5 if you still need to log this user in.

### T2.4 — Unknown username (non-phone) behavior
- **Purpose:** document the as-built behavior for a non-phone unknown identifier.
- **Steps:** enter `no_such_user_xyz` + any password, once.
- **Expected:** WordPress native `invalid_username` (**not** genericized — expected, note 5).
- **Browser:** yes. **Risk:** Low. **Cleanup:** as T2.3.

### T2.5 — Identifier lockout at the 5th failure (username path)
- **Purpose:** confirm per-identifier threshold and message.
- **Steps:** from **one** browser, submit wrong-password logins for `qa_phase2a` **5–6 times**.
- **Expected:** attempts 1–4 normal rejection; attempt ≥ 5 → Persian rate-limit error
  ("تعداد تلاش‌های ناموفق بیش از حد مجاز است…", code `too_many_retries`), holding even if the
  **correct** password is then supplied (priority-90 override).
- **Browser:** yes. **DB:** optional (T3.7). **Risk:** Low–Medium — identifier now locked, and
  5 added to the shared IP bucket (of 30). Don't repeat across many identifiers in one session.
- **Cleanup:** delete the `hd_rl_id_*` + `hd_rl_ip_*` transients (T3.9) or wait 15 min; confirm
  recovery by logging in.

### T2.6 — Successful login clears the identifier bucket (observable)
- **Purpose:** confirm `wp_login` → `on_login_success` resets the identifier counter.
- **Steps:** cause **3** wrong-password failures for `qa_phase2a`, then log in correctly, then
  cause 3 more failures.
- **Expected:** the second batch does **not** trigger lockout (counter reset to 0). Precise
  confirmation in T3.8.
- **Browser:** yes. **DB:** optional. **Risk:** Low. **Cleanup:** T3.9 / wait.

### T2.7 — Role/capability spot-check via the UI
- **Purpose:** sanity-check least privilege for each custom role from the UI.
- **Steps:** for each role, set `qa_phase2a`'s role, log in as that user in a private window,
  observe the admin menu; also visit `wp-admin/options-general.php` directly as `reception` and
  as `hedayati_manager`.
- **Expected:** none of the custom roles see Settings / Plugins / Users management / Appearance;
  direct visit to `options-general.php` → "Sorry, you are not allowed to access this page." Full
  capability verification is T3.5–T3.6; matrix in Appendix A.
- **Browser:** yes. **DB:** no. **Risk:** Very low. **Cleanup:** set role back to `student`.

### T2.8 — Delete the test user (UI) — lifecycle, UI half
- **Purpose:** confirm deletion works and (with T3.10 / T3.15) that the phone row is cleaned up.
- **Steps:** Users → All Users → `qa_phase2a` → Delete → "Delete all content" → Confirm. Run
  **after** a phone has been assigned (T3.10) if doing Category 3, so the cleanup hook runs on a
  real row.
- **Expected:** user removed; no PHP error.
- **Browser:** yes. **DB:** to verify row removal, yes (T3.15 step 5). **Risk:** Low (disposable
  user only). **Cleanup:** this *is* cleanup; follow with T3.16 if Category 3 ran.

### T2.9 — Category 2 teardown
- **Steps:** ensure `qa_phase2a` deleted; delete any `hd_rl_*` transients (T3.9) or confirm 15+
  minutes elapsed; confirm the real admin can still log in.
- **Risk:** None.

---

## CATEGORY 3 — DATABASE-ACCESS REQUIRED

> Needs N4 (DB access) and, for phone provisioning, N5 (WP-CLI **or** the temporary harness). Take
> backup N9 first. All writes here are test rows / transients / the temporary harness file —
> enumerated for cleanup in T3.16. Substitute the site's real prefix for `P_` in every statement.

### T3.1 — Migration version options
- **Purpose:** confirm the Phase 2A migration recorded success.
- **Steps:**
  `SELECT option_name, option_value FROM P_options WHERE option_name IN
  ('hedayati_core_db_version','hedayati_core_roles_version','hedayati_core_managed_capabilities',
  'hedayati_institute_settings','hedayati_db_migration_lock');`
- **Expected:** `hedayati_core_db_version` = `2.0.0`; `hedayati_core_roles_version` = `2.0.0`;
  `hedayati_core_managed_capabilities` = serialized array of **21** names (Appendix A);
  `hedayati_institute_settings` present; **`hedayati_db_migration_lock` absent** (a lingering lock
  ⇒ crashed / mid-migration ⇒ finding).
- **Browser:** via phpMyAdmin/Adminer. **DB:** yes. **Risk:** None. **Cleanup:** none.

### T3.2 — Phone table exists, under the real prefix
- **Steps:** `SHOW TABLES LIKE '%hedayati\\_user\\_phones';` and `SHOW TABLES LIKE 'wp\\_%';`
- **Expected:** exactly one `<prefix>hedayati_user_phones`; no `wp_*` tables unless `wp_` really is
  the prefix.
- **DB:** yes. **Risk:** None. **Cleanup:** none.

### T3.3 — Phone table schema & constraints
- **Steps:** `SHOW CREATE TABLE P_hedayati_user_phones;` and `SHOW INDEX FROM P_hedayati_user_phones;`
- **Expected (must match `class-db-schema.php::migrate_2_0_0`):**
  - `id` BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT, PRIMARY KEY
  - `user_id` BIGINT(20) UNSIGNED NOT NULL — **UNIQUE KEY `uq_user_id`**
  - `phone_e164` VARCHAR(20) NOT NULL — **UNIQUE KEY `uq_phone_e164`**
  - `is_verified` TINYINT(1) NOT NULL DEFAULT 0 — **KEY `idx_is_verified`**
  - `verified_at` DATETIME NULL
  - `created_at` DATETIME NOT NULL
  - `updated_at` DATETIME NOT NULL
  - table charset/collation = DB default from `get_charset_collate()` (typically `utf8mb4`).
- **DB:** yes. **Risk:** None. **Cleanup:** none.

### T3.4 — No `wp_` assumption anywhere in the deployed code
- **Steps:** on the server or the T1.3 download, search the plugin for string literals used as
  table names; confirm `Hedayati_DB_Schema::get_table_user_phones()` returns
  `$wpdb->prefix . 'hedayati_user_phones'`. `grep -rn "'wp_" wp-content/plugins/hedayati-core --include='*.php'`.
- **Expected:** no hardcoded prefixes; only WordPress function/class names contain `wp_` / `WP_`.
- **DB:** no. **Risk:** None. **Cleanup:** none.

### T3.5 — Full role → capability audit
- **Steps:** `SELECT option_value FROM P_options WHERE option_name = 'P_user_roles';` (the option
  name itself is prefixed), deserialize, compare to Appendix A. Or WP-CLI: `wp role list`, then
  `wp cap list student` / `teacher_assistant` / `teacher` / `reception` / `hedayati_manager`.
- **Expected:** exact match to Appendix A; every custom role also has `read`; no custom role has
  `manage_options`, `edit_theme_options`, `delete_users`, `activate_plugins`, `edit_users`.
- **DB / WP-CLI.** **Risk:** None. **Cleanup:** none.

### T3.6 — Administrator retains full access + gains all Hedayati caps
- **Steps:** `wp cap list administrator` or inspect `P_user_roles`.
- **Expected:** `manage_options`, `activate_plugins`, `edit_users`, `delete_users`,
  `edit_theme_options`, `manage_categories`, **and** all 21 `hedayati_*` capabilities present;
  no native capability removed.
- **DB / WP-CLI.** **Risk:** None. **Cleanup:** none.

### T3.7 — Failure counter increments by exactly 1 per attempt (no double count)
- **Steps:** (1) clear `hd_rl_*` transients (T3.9); (2) one wrong-password login for `qa_phase2a`
  (username path); (3) `SELECT option_name, option_value FROM P_options WHERE option_name LIKE
  '\\_transient\\_hd\\_rl\\_%';`; (4) one wrong-password login using a **phone** identifier assigned
  to `qa_phase2a` (needs T3.10); re-check transients.
- **Expected:** after (2), canonical-username bucket = **1**, IP bucket = **1** (not 2). After (4),
  canonical-phone bucket = 1, IP bucket = 2. No bucket jumps by 2 on one attempt.
- **Browser + DB.** **Risk:** Low. **Cleanup:** T3.9.

### T3.8 — Successful login clears the identifier bucket but not the IP bucket
- **Steps:** from zero, 3 wrong-password failures for `qa_phase2a`; inspect (id = 3, ip = 3); log
  in correctly; re-inspect.
- **Expected:** `hd_rl_id_*` for that identifier **deleted**; `hd_rl_ip_*` **remains at 3**. If a
  phone is assigned, the `hd_rl_id_*` for the phone's canonical form is also deleted.
- **Browser + DB.** **Risk:** Low. **Cleanup:** T3.9.

### T3.9 — (utility) Clear rate-limit transients
- **Steps:** `DELETE FROM P_options WHERE option_name LIKE '\\_transient\\_hd\\_rl\\_%' OR
  option_name LIKE '\\_transient\\_timeout\\_hd\\_rl\\_%';` or WP-CLI
  `wp transient delete --all` (staging only).
- **Risk:** Very low (staging transients only; not persistent data). **Cleanup:** n/a.

### T3.10 — Provision & inspect a phone for the test user  ⟶ needs N5
- **Steps (WP-CLI):**
  `wp eval 'var_export( Hedayati_User_Phone_Service::assign_phone( <ID>, "0912XXXXXXX" ) );'`
  then `wp eval 'var_export( Hedayati_User_Phone_Service::get_phone_record_by_user( <ID> ) );'`
  (or the harness buttons). Use a number that is **not a real person's** (a disposable / test SIM).
- **Expected:** `assign_phone` → `true`; record shows `phone_e164 = +989XXXXXXXXX` (canonical),
  `is_verified = false`, `verified_at = null`, `created_at`/`updated_at` set (UTC).
- **DB + code path.** **Risk:** Low (one test row). **Cleanup:** T3.16 / T3.15 step 5.

### T3.11 — Phone-login: all accepted input formats
- **Steps:** with the phone from T3.10 assigned and the correct password, try each identifier form
  (these should all *succeed*):
  national `09123456789`, no-zero `9123456789`, E.164 `+989123456789`, `00989123456789`,
  no-plus `989123456789`, Persian `۰۹۱۲۳۴۵۶۷۸۹`, Arabic-Indic `٠٩١٢٣٤٥٦٧٨٩`, separators
  `0912 345 6789` / `0912-345-6789` / `(0912) 345.6789`.
- **Expected:** every form logs in as `qa_phase2a`.
- **Browser (+ T3.9 between if you approach 5 failures).** **Risk:** Low. **Cleanup:** log out;
  T3.9.

### T3.12 — Phone-login: rejection & generic-error behavior
- **Steps:** attempt (expect failure): correct phone + **wrong** password; a valid-format but
  **unassigned** phone (`+989000000000`) + any password; malformed `0912abc4567`, `0912<script>`,
  `0912_3456789`, `++989123456789`, `0912+3456789`, Tehran landline `02112345678`, too-short
  `0912345`, non-Iranian `+14155552671`.
- **Expected:** every attempt fails with the **identical** Persian generic message
  ("نام کاربری/شماره موبایل یا رمز عبور اشتباه است", code `invalid_credentials`) — no distinction
  between "no such number" and "wrong password"; malformed inputs never partially match.
- **Browser.** **Risk:** Low–Medium — generates IP-bucket failures; keep the total well under 30;
  5 wrong-password attempts on the *correct* phone will lock that identifier. **Cleanup:** T3.9.

### T3.13 — Phone uniqueness (service level + DB level)
- **Steps:** (1) create `qa_phase2a_b` (student); (2)
  `wp eval 'var_export( Hedayati_User_Phone_Service::assign_phone( <ID_B>, "+98 912 345 6789" ) );'`
  — a different input format of the number already on user A; (3) attempt a raw insert:
  `INSERT INTO P_hedayati_user_phones (user_id,phone_e164,is_verified,created_at,updated_at)
  VALUES (<ID_B>,'+989123456789',0,UTC_TIMESTAMP(),UTC_TIMESTAMP());`
- **Expected:** (2) → `WP_Error` code `phone_already_exists`; (3) → MySQL **duplicate key** error on
  `uq_phone_e164`. User B ends up with **no** row.
- **DB + code path.** **Risk:** Low — the raw INSERT is *expected to fail* and writes nothing. Do
  **not** use `ON DUPLICATE KEY UPDATE`. **Cleanup:** delete `qa_phase2a_b`; T3.16.

### T3.14 — Equivalent formats normalize identically
- **Steps:** `wp eval 'echo Hedayati_Phone::normalize("<vector>"), "\n";'` for
  `09123456789`, `9123456789`, `+989123456789`, `00989123456789`, `989123456789`, `۰۹۱۲۳۴۵۶۷۸۹`,
  `٠٩١٢٣٤٥٦٧٨٩`, `0912 345 6789`; and
  `wp eval 'var_export( (bool) Hedayati_User_Phone_Service::find_user_by_phone("<vector>") );'`.
- **Expected:** every `normalize()` prints exactly `+989123456789`; every `find_user_by_phone()`
  returns user A; invalid vectors return a `WP_Error`.
- **Code path only.** **Risk:** None (pure function). **Cleanup:** none.

### T3.15 — Phone lifecycle & verification-flag transitions
- **Steps (WP-CLI on user A):**
  1. row exists from T3.10 (`is_verified=0`).
  2. `wp eval 'var_export( Hedayati_User_Phone_Service::verify_phone( <ID> ) );'` → re-read record.
  3. `wp eval 'var_export( Hedayati_User_Phone_Service::update_phone( <ID>, "09120000000" ) );'`
     (different number) → re-read.
  4. `wp eval 'var_export( Hedayati_User_Phone_Service::update_phone( <ID>, "+989120000000" ) );'`
     (same number, different format) → re-read.
  5. Delete user A via wp-admin (T2.8) → `SELECT COUNT(*) FROM P_hedayati_user_phones WHERE user_id = <ID>;`
- **Expected:**
  - (2) → `true`; `is_verified = true`, `verified_at` set, `updated_at` bumped.
  - (3) number changed → `is_verified` back to `false`, `verified_at` `null`, `phone_e164` =
    `+989120000000`, `updated_at` bumped.
  - (4) same normalized number → `true`, **row unchanged** (verification state preserved,
    `updated_at` not bumped).
  - (5) → `COUNT(*) = 0` (the `deleted_user` hook removed the row).
- **DB + code path.** **Risk:** Low. **Cleanup:** T3.16.

### T3.16 — Category 3 teardown
- **Steps:** delete disposable users `qa_phase2a`, `qa_phase2a_b`; `SELECT * FROM
  P_hedayati_user_phones;` and delete any remaining test rows; T3.9 transient cleanup; **remove the
  temporary harness mu-plugin** if used; confirm `SELECT COUNT(*) FROM P_hedayati_user_phones;` is
  back to its pre-test value (expected 0 on a fresh Phase 2A staging site); confirm admin login.
- **Risk:** None.

---

## CATEGORY 4 — POTENTIALLY DESTRUCTIVE — DO NOT RUN WITHOUT EXPLICIT APPROVAL

Each requires: fresh full backup (N9), a maintenance window, no live traffic (N8), and written
per-test approval (N10).

| Test | What | Risk | Why deferred / cleanup |
|---|---|---|---|
| **T4.1** | Reset `hedayati_core_db_version` and re-trigger `migrate()` on the populated table | Medium | Baseline only needs T3.1 (migration already succeeded). Cleanup: restore version = `2.0.0`; restore from backup if `dbDelta` altered the table. |
| **T4.2** | `DROP TABLE` `hedayati_user_phones` then re-migrate | High | Destroys all phone identities. Cleanup: restore from backup. |
| **T4.3** | Simulate concurrent `admin_init` migrations to exercise the lock + 60s stale recovery | Medium | Timing-dependent; a stuck lock blocks migrations 60s. Cleanup: `DELETE … option_name='hedayati_db_migration_lock'`. |
| **T4.4** | Plugin deactivate → reactivate (fires the activation hook) | Medium | Rewrite-flush → transient 404s; LiteSpeed may serve stale pages. Cleanup: Settings → Permalinks → Save; purge cache; re-run T3.5. |
| **T4.5** | Drive 30 failed logins from one IP to confirm the IP threshold | Medium–High | Locks that IP (likely your own) out of login for 15 min. Cleanup: T3.9 or wait 900s. Only with a second admin route. |
| **T4.6** | Delete a **real** user to test cleanup | High | Data loss. Already covered safely by T3.15 step 5 on a disposable user. Cleanup: restore from backup. |
| **T4.7** | `wp-config.php` changes (`WP_DEBUG`, prefix independence) | Medium | A syntax slip downs the whole site; prefix changes are destructive. Cleanup: restore original `wp-config.php`. |
| **T4.8** | Any redeploy of theme/plugin from the repo to staging | Medium | Changes the artifact under test; needs its own migration/non-regression check (`docs/DEPLOYMENT.md`). Only if T1.3 finds drift. |

---

## Appendix A — Expected roles & capabilities (`class-roles.php` @ `2.0.0`)

**21 managed capabilities** (`hedayati_core_managed_capabilities`):
`hedayati_view_own_portal`, `hedayati_edit_own_profile`, `hedayati_view_own_enrollments`,
`hedayati_upload_own_documents`, `hedayati_view_assigned_runs`, `hedayati_view_assigned_roster`,
`hedayati_manage_assigned_sessions`, `hedayati_record_attendance`, `hedayati_lookup_students`,
`hedayati_create_enrollments`, `hedayati_edit_enrollments_basic`,
`hedayati_view_student_profiles_basic`, `hedayati_initiate_verification`, `hedayati_manage_courses`,
`hedayati_manage_course_runs`, `hedayati_assign_staff`, `hedayati_verify_students`,
`hedayati_view_private_documents`, `hedayati_view_audit_logs`, `hedayati_manage_enrollments`,
`hedayati_manage_settings`.

| Role (slug) | Display name | Capabilities (plus `read`) |
|---|---|---|
| `student` | دانشجو | view_own_portal, edit_own_profile, view_own_enrollments, upload_own_documents |
| `teacher_assistant` | استادیار / پشتیبان آموزشی | view_assigned_runs, view_assigned_roster |
| `teacher` | مدرس | view_assigned_runs, view_assigned_roster, manage_assigned_sessions, record_attendance |
| `reception` | پذیرش و ثبت‌نام | lookup_students, create_enrollments, edit_enrollments_basic, view_student_profiles_basic, initiate_verification |
| `hedayati_manager` | مدیر آموزش مجتمع | the 5 reception caps + manage_courses, manage_course_runs, assign_staff, verify_students, view_private_documents, view_audit_logs, manage_enrollments, manage_settings |
| `administrator` | (native) | all 21 `hedayati_*` + all native WP caps unchanged |

**Negative assertions:** `reception` and `hedayati_manager` do **not** have `manage_options`;
`reception` does not have `delete_users` or `edit_theme_options`; `teacher_assistant` does not have
`hedayati_record_attendance` or `hedayati_manage_assigned_sessions`.

## Appendix B — Options expected after a successful Phase 2A baseline

| Option | Expected |
|---|---|
| `hedayati_core_db_version` | `2.0.0` |
| `hedayati_core_roles_version` | `2.0.0` |
| `hedayati_core_managed_capabilities` | serialized array, 21 entries (Appendix A) |
| `hedayati_institute_settings` | present (Phase 1 contact settings) |
| `hedayati_db_migration_lock` | **absent** |
| `{prefix}user_roles` | includes the 5 custom roles |

## Appendix C — Coverage map to the acceptance request

| Request area | Tests |
|---|---|
| A. Code / version | T1.1 ✅, T1.2 ✅, T1.3 ✅, T3.4 |
| B. Database | T3.1, T3.2, T3.3, T3.4 |
| C. Authentication | T1.5, T2.2, T2.3, T2.4, T3.11, T3.12 |
| D. Phone uniqueness | T3.13, T3.14 |
| E. Rate limiting | T2.5, T2.6, T3.7, T3.8, T3.9, (T4.5 = full IP bucket, deferred) |
| F. Roles / capabilities | T1.4, T2.7, T3.5, T3.6 |
| G. User lifecycle | T2.1, T2.8, T3.10, T3.15, T3.16 |
| H. Verification flags | T1.6 (documentation), T3.15 (behavioral) |

## Appendix D — T1.3 file download instructions

See the accompanying task message / the project chat for the exact directory list and exclusions.
Summary: download `wp-content/plugins/hedayati-core/` and `wp-content/themes/hedayati/` in full;
exclude nothing *inside* those two directories (they contain no generated, cache, or upload
files); do **not** include `wp-content/uploads/`, `wp-content/cache/`, `wp-content/mu-plugins/`,
`wp-config.php`, or any other plugin/theme.
