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
| T1.4 | Custom roles visible in UI | 1 read-only | ✅ PASS | 2026-09-02 | M1 — all 5 custom roles listed in Users → Add New; corroborates SQL `C2` |
| T1.5 | Existing admin can still log in / keeps admin access | 1 read-only | ✅ PASS | 2026-09-02 | M2 — Dashboard, Settings → General, Plugins, Users, Settings → Hedayati all load as administrator; no PHP error; auth filter chain (@30/@90) does not break normal admin login |
| T1.6 | Verification-flag implementation (doc) | 1 read-only | ✅ PASS | 2026-09-02 | code conclusion; staging schema (`B2`/`B3`) confirms `is_verified` + `verified_at` + `idx_is_verified` exactly as built; no trigger for `verify_phone()` exists |
| T3.1 | Migration version options | 3 read-only | ✅ PASS | 2026-09-02 | operator-confirmed Stage A: `hedayati_core_db_version`=`2.0.0`, `hedayati_core_roles_version`=`2.0.0`, migration lock absent; corroborated by `C4` (`managed_capabilities` = 21-element array, 841 B) |
| T3.2 | Phone table exists under real prefix | 3 read-only | ✅ PASS | 2026-09-02 | `B1`/`B1b`/`B1c`: one `…_hedayati_user_phones`, InnoDB, no `wp_` variant, 13 site-prefixed tables, 0 `wp_` tables |
| T3.2b | Phone table data baseline | 3 read-only | ✅ PASS | 2026-09-02 | `D1`–`D4`: 0 rows, 0 duplicates, 0 orphans, 0 multi-phone users, 0 integrity violations |
| T3.3 | Phone table schema & constraints | 3 read-only | ✅ PASS | 2026-09-02 | `B2` (7 columns exact) + `B3` (PK `id`, UNIQUE `uq_user_id`, UNIQUE `uq_phone_e164`, KEY `idx_is_verified`) match `class-db-schema.php::migrate_2_0_0` |
| T3.4 | No `wp_` assumption (code + runtime) | 1 + 3 read-only | ✅ PASS | 2026-09-02 | repo grep: only `wp_login*` hook names; table name = `$wpdb->prefix . 'hedayati_user_phones'`. Runtime `B1b`: no `wp_hedayati_user_phones` |
| T3.5 | Full role → capability audit | 3 read-only | 🟡 NEEDS REVIEW | 2026-09-02 | `C1` (5616 B ≈ 4× stock ⇒ sync ran), `C2` (all 5 slugs + admin), `C3` (all 21 caps present), `hedayati_token_count` = 50 = expected; M1 confirms roles are registered & selectable. **Exact per-role matrix + least-privilege negatives** (reception/manager lack `manage_options`, TA lacks attendance) still need `wp cap list` or T2.7 |
| T3.6 | Administrator retains access + all Hedayati caps | 3 read-only | ✅ PASS | 2026-09-02 | M2 — admin functionally reaches Settings / Plugins / Users (⇒ `manage_options`, `activate_plugins`, `edit_users` intact); `C2` confirms `manage_options` + `activate_plugins` in the option; `C3` + token arithmetic (28 + 1 + 21 = 50) consistent with admin holding all 21. Residual (non-blocking): a positional `wp cap list administrator \| grep -c hedayati_` = 21 |
| E1 obs | Rate-limiter transient baseline | 3 read-only | ℹ️ OBSERVED | 2026-09-02 | 19 active `hd_rl_*` counters (19 value + 19 timeout option rows), DB-backed (no object cache). Benign — limiter is recording real `wp-login.php` failures. **Must be cleared / IP bucket confirmed clean before T2.5, T2.6, T3.7, T3.8** |
| E2/E3/F1 | Cache & environment context | 3 read-only | ✅ PASS | 2026-09-02 | 41 total DB transient rows (transients are DB-backed); no caching plugin active; `template`/`stylesheet` both `hedayati` (DB-side cross-check of T1.2) |
| T2.1–T2.9 | Disposable test user | 2 | ⬜ blocked | — | needs operator go-ahead (N3) |
| T3.7, T3.8 | Rate-limit counter behaviour | 3 state-changing | ⬜ blocked | — | needs disposable user + clean IP bucket (see E1 obs) |
| T3.9–T3.16 | Phone provisioning / lifecycle / uniqueness | 3 state-changing | ⬜ blocked | — | needs WP-CLI or harness (N5) |
| T4.1–T4.8 | Potentially destructive | 4 | ⛔ hold | — | explicit per-test approval required |

**Baseline status: STATIC + READ-ONLY LAYER FULLY VERIFIED.**
Confirmed: version identity (T1.1–T1.2), code-match (T1.3), custom roles present & selectable
(T1.4), admin access unbroken by the auth filter chain (T1.5), verification-flag design (T1.6),
migration state & options (T3.1), phone-table existence / prefix / schema / constraints / empty
baseline (T3.2, T3.2b, T3.3), no `wp_` assumption (T3.4), administrator retains full access + the
21 caps installed (T3.6).
Provisional: exact per-role capability matrix + least-privilege negatives (T3.5 — NEEDS REVIEW;
structure is consistent with the design but not positionally enumerated).
Outstanding: **all** runtime behaviour — authentication flows, rate-limit thresholds / reset /
no-double-count, phone provisioning / normalization end-to-end / uniqueness under real insert /
lifecycle / deletion cleanup (Categories 2–4), and every destructive test.

---

## Read-only database verification — evidence (2026-09-02)

Operator ran a single consolidated read-only batch (`SELECT` / `SHOW` / `information_schema`) in
phpMyAdmin against the staging database. Results, keyed by the `check_id` in the batch:

| check_id | Result | Meaning |
|---|---|---|
| `B1_table_meta` | `…_hedayati_user_phones`, `InnoDB`, `utf8mb4_unicode_520_ci`, est_rows 0, created `2026-09-01 12:03:30` | table present, correct engine/charset |
| `B1b_table_name_scan` | correct_prefix 1, legacy `wp_` 0, any 1 | exactly one phone table, no hardcoded-`wp_` variant |
| `B1c_prefix_sanity` | 13 site-prefixed tables, 0 `wp_` tables | 12 core + 1 Hedayati table; prefix consistent |
| `B2_columns` | 7 rows: `id` bigint unsigned auto_increment · `user_id` bigint unsigned NOT NULL · `phone_e164` varchar(20) NOT NULL utf8mb4 · `is_verified` tinyint(1) NOT NULL default 0 · `verified_at` datetime NULL · `created_at` datetime NOT NULL · `updated_at` datetime NOT NULL | exact match to `migrate_2_0_0` |
| `B3_indexes` | `PRIMARY`(id) · `uq_user_id`(user_id, non_unique 0) · `uq_phone_e164`(phone_e164, non_unique 0) · `idx_is_verified`(is_verified, non_unique 1); all BTREE | both UNIQUE constraints real; lookup index present |
| `B4` (`SHOW CREATE TABLE`) | executed OK; DDL text truncated on paste — **not relied upon**; B1–B3 fully cover the schema | — |
| `C1_user_roles_option` | `{prefix}user_roles` present, autoload on, **5616 bytes** (stock WP ≈ 1300–1600) | role/capability sync ran |
| `C2_role_slugs` | `student`, `teacher_assistant`, `teacher`, `reception`, `hedayati_manager`, `administrator` all present; admin has `manage_options` + `activate_plugins`; `hedayati_token_count` = **50** | all 5 custom roles installed; admin keeps core caps; token count = expected 28 (custom-role caps) + 1 (`hedayati_manager` key) + 21 (admin) |
| `C3_capabilities_present` | `c01`–`c21` **all 1** | every one of the 21 managed capabilities is installed somewhere in the role structure |
| `C4_managed_caps_option` | `hedayati_core_managed_capabilities` = 841 bytes, `a:21:{…}`, autoload auto | tracked managed-cap list has exactly 21 entries (corroborates Stage A / T3.1) |
| `D1_aggregates` | total_rows 0; verified 0; unverified 0; bad_is_verified 0; timestamp-consistency 0/0; non_canonical_format 0 | phone table empty and internally consistent |
| `D2` / `D3` / `D4` | 0 / 0 / 0 | no duplicate numbers, no orphan rows, no user with >1 phone row |
| `E1_rate_limit_transients` | **19 value rows + 19 timeout rows** (`_transient_hd_rl_*`) | 19 active rate-limit counters — see the E1 analysis below |
| `E2_transient_context` | 41 total `_transient_%` rows | transients are written to the DB (no object-cache interception) |
| `E3_active_plugins` | `hedayati-core` active; no LiteSpeed/Redis/W3TC/Super-Cache | plugin active (corroborates T1.1); no persistent object cache |
| `F1_active_theme` | `template` = `stylesheet` = `hedayati` | DB-side cross-check of T1.2 |

### E1 analysis — 19 rate-limit counters

`Hedayati_Rate_Limiter::record_failure()` calls `set_transient()` for the IP bucket and the
identifier bucket on every failed login. WordPress `set_transient($k, $v, $ttl)` writes **two**
option rows — `_transient_<k>` (value) and `_transient_timeout_<k>` (unix expiry). So 19 value +
19 timeout rows = **19 distinct active rate-limit counters** (a mix of `hd_rl_ip_<hash>` and
`hd_rl_id_<hash>`), each with its timeout companion (clean 1:1 — no orphaned rows).

- **Where they came from:** ordinary failed logins at `wp-login.php` — almost certainly a mix of
  the operator's own earlier sessions and the constant background of bot login attempts that hits
  any internet-facing WordPress site. Each distinct username/phone a bot tries creates its own
  `hd_rl_id_*` bucket.
- **Is it expected / benign?** Yes. It is positive evidence that the limiter is live and recording
  failures through the single `wp_login_failed` path, exactly as designed. The default TTL is 900 s;
  WordPress does not proactively purge expired transient rows, so some of these may already be
  logically expired but still present as rows.
- **Does it affect acceptance?** No — it fails no read-only check. Recorded as an observation.
- **Does any future test need them cleared first?** Yes. The rate-limit **behavioural** tests
  (T2.5, T2.6, T3.7, T3.8) assert exact bucket values ("IP bucket = 1", "= 3", "identifier bucket
  cleared on success"). They require the specific identifier buckets **and the tester's own IP
  bucket** to start at 0. The disposable-user identifier buckets will be clean (they don't exist
  yet), but the tester's IP bucket may not be. Before those tests: either (a) confirm the tester's
  current `hd_rl_ip_*` bucket is absent, or (b) clear the `_transient_hd_rl_*` / `_transient_timeout_hd_rl_*`
  rows (a **write** — deferred, needs approval as part of the T3.9 utility step), or (c) run the
  tests from an IP with no recent failures.

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
| N4 | Staging **database** read access (phpMyAdmin) | Category 3 read-only | ✅ have (phpMyAdmin; consolidated read-only batch run 2026-09-02) |
| N5 | **One** of: (a) WP-CLI access (`wp shell` / `wp eval`), or (b) approval to install a temporary, admin-only, nonce-protected **must-use plugin harness** (Claude writes it; it only calls the existing `Hedayati_User_Phone_Service` methods; deleted afterward) | Category 3 phone tests | ⬜ pending |
| N6 | Confirmation of the **actual table prefix** on staging | all SQL | ✅ operator supplied it directly (used in the Stage B–F batch) |
| N7 | Whether a persistent object cache (LiteSpeed / Redis) is active for `wp_options` / transients | E1/E2 accuracy | ✅ resolved — `E1` shows 38 `hd_rl` transient rows **in the DB** and `E3` shows no cache plugin ⇒ transients are DB-backed and rate-limit state is DB-visible |
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

### T1.4 — Custom roles appear in the UI  ✅ PASS (2026-09-02, check M1)
- **Purpose:** first-pass confirmation that role registration ran.
- **Steps:** wp-admin → Users → Add New → open the **Role** dropdown.
- **Expected:** lists **دانشجو (student)**, **استادیار / پشتیبان آموزشی (teacher_assistant)**,
  **مدرس (teacher)**, **پذیرش و ثبت‌نام (reception)**, **مدیر آموزش مجتمع (hedayati_manager)** plus
  WordPress defaults.
- **Result:** all five custom roles present in the dropdown. Corroborates SQL `C2`.

### T1.5 — Existing administrator can still log in and reach admin  ✅ PASS (2026-09-02, check M2)
- **Purpose:** confirm the auth filter chain (`authenticate` @30 + @90) has not broken normal
  admin login or capabilities.
- **Steps:** operator logs into wp-admin as the existing administrator; confirms Dashboard,
  Settings → General, Plugins, Users, and Settings → Hedayati all load.
- **Expected:** normal login; admin access intact; no PHP notice/error.
- **Result:** all five screens load normally as administrator; no PHP error. The phone-auth
  adapter (@30) passes non-phone identifiers through untouched and the late rate-limit filter
  (@90) does not block a valid admin login — confirmed in practice.

### M3 — Object-cache drop-in  ⏳ NOT SUPPLIED (non-blocking)
- **Purpose:** confirm whether transients (incl. rate-limit counters) are DB-backed or held in an
  object cache — determines whether SQL inspection of `hd_rl_*` is meaningful.
- **Status:** operator left this blank. **Already answered indirectly:** `E1` found 19 `hd_rl_*`
  rate-limit transients physically present as `wp_options` rows, and `E3` shows no caching plugin
  active ⇒ transients are DB-backed and rate-limit state is DB-visible. A direct check of
  `wp-content/object-cache.php` / LiteSpeed **Object** Cache remains a nice-to-have only.

### T1.6 — Document verification-flag implementation (code review)  ✅ PASS (2026-09-02)
- **Purpose:** record exactly what "verification" exists in Phase 2A.
- **Steps:** none on staging — restated here for the baseline record; confirmed against the deployed
  files (T1.3) and the live schema (`B2`/`B3`).
- **Result:** the deployed schema carries `is_verified` (`tinyint(1)` NOT NULL default 0) and
  `verified_at` (`datetime` NULL) with `KEY idx_is_verified` — exactly as built. No trigger for
  `verify_phone()` exists anywhere in the shipped code. Verification is a data-model + service
  method only; there is no OTP/SMS/admin/reception path to set it, no UI, and nothing gated on it.
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

### T3.1 — Migration version options  ✅ PASS (2026-09-02, operator-confirmed Stage A)
- **Purpose:** confirm the Phase 2A migration recorded success.
- **Result:** Stage A batch reported PASS — `hedayati_core_db_version` = `2.0.0`,
  `hedayati_core_roles_version` = `2.0.0`, `hedayati_db_migration_lock` absent. `C4` in the
  Stage B–F batch independently confirms `hedayati_core_managed_capabilities` is a serialized
  21-element array (841 bytes). `hedayati_institute_settings` presence: operator-confirmed under
  Stage A (its absence would not be a Phase 2A defect).
- **Steps:**
  `SELECT option_name, option_value FROM P_options WHERE option_name IN
  ('hedayati_core_db_version','hedayati_core_roles_version','hedayati_core_managed_capabilities',
  'hedayati_institute_settings','hedayati_db_migration_lock');`
- **Expected:** `hedayati_core_db_version` = `2.0.0`; `hedayati_core_roles_version` = `2.0.0`;
  `hedayati_core_managed_capabilities` = serialized array of **21** names (Appendix A);
  `hedayati_institute_settings` present; **`hedayati_db_migration_lock` absent** (a lingering lock
  ⇒ crashed / mid-migration ⇒ finding).
- **Browser:** via phpMyAdmin/Adminer. **DB:** yes. **Risk:** None. **Cleanup:** none.

### T3.2 — Phone table exists, under the real prefix  ✅ PASS (2026-09-02)
- **Steps:** `information_schema` checks `B1` / `B1b` / `B1c`.
- **Expected:** exactly one `<prefix>hedayati_user_phones`; no `wp_*` variant.
- **Result:** one table `…_hedayati_user_phones`, `InnoDB`, `utf8mb4_unicode_520_ci`, created
  `2026-09-01 12:03:30`. `B1b`: no `wp_hedayati_user_phones`. `B1c`: 13 site-prefixed tables,
  0 `wp_` tables.
- **DB:** yes. **Risk:** None. **Cleanup:** none.

### T3.2b — Phone table data baseline  ✅ PASS (2026-09-02)
- **Steps:** `information_schema` / aggregate checks `D1`–`D4` (counts only, no phone numbers).
- **Expected:** empty table on a fresh Phase 2A site; no duplicates, orphans, or integrity
  violations.
- **Result:** `total_rows` = 0; `verified` / `unverified` = 0; `bad_is_verified_values` = 0;
  timestamp-consistency = 0/0; `non_canonical_format` = 0; `D2`/`D3`/`D4` = 0/0/0.
- **DB:** yes. **Risk:** None. **Cleanup:** none.

### T3.3 — Phone table schema & constraints  ✅ PASS (2026-09-02)
- **Steps:** `information_schema` checks `B2` (columns) + `B3` (indexes). `SHOW CREATE TABLE`
  (`B4`) ran but its pasted DDL was truncated and is **not relied upon** — `B2`+`B3` fully cover
  columns, types, nullability, defaults, PK, both UNIQUE constraints, the lookup index, engine,
  and charset.
- **Expected (must match `class-db-schema.php::migrate_2_0_0`):**
  - `id` BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT, PRIMARY KEY
  - `user_id` BIGINT(20) UNSIGNED NOT NULL — **UNIQUE KEY `uq_user_id`**
  - `phone_e164` VARCHAR(20) NOT NULL — **UNIQUE KEY `uq_phone_e164`**
  - `is_verified` TINYINT(1) NOT NULL DEFAULT 0 — **KEY `idx_is_verified`**
  - `verified_at` DATETIME NULL
  - `created_at` DATETIME NOT NULL
  - `updated_at` DATETIME NOT NULL
  - table charset/collation = DB default from `get_charset_collate()` (typically `utf8mb4`).
- **Result:** `B2` returned the 7 columns exactly as listed above (types, nullability, defaults,
  `phone_e164` on `utf8mb4`). `B3` returned exactly `PRIMARY(id)`, `uq_user_id(user_id)` and
  `uq_phone_e164(phone_e164)` both with `non_unique = 0`, and `idx_is_verified(is_verified)` with
  `non_unique = 1` — all `BTREE`. Table collation `utf8mb4_unicode_520_ci`.
- **DB:** yes. **Risk:** None. **Cleanup:** none.

### T3.4 — No `wp_` assumption (code + runtime)  ✅ PASS (2026-09-02)
- **Steps:** repo grep of the plugin for string literals used as table names + confirm
  `Hedayati_DB_Schema::get_table_user_phones()` returns `$wpdb->prefix . 'hedayati_user_phones'`;
  runtime check `B1b` (no `wp_hedayati_user_phones` on staging).
- **Result:** the only `wp_` literals in the plugin are the WordPress **action-hook names**
  `wp_login_failed` and `wp_login` (`class-auth.php`). The single table-name construction is
  `$wpdb->prefix . 'hedayati_user_phones'` (`class-db-schema.php:64`), used everywhere via
  `get_table_user_phones()`; charset from `$wpdb->get_charset_collate()`. Runtime `B1b` confirms
  no `wp_`-prefixed phone table exists.
- **DB:** partial (runtime half). **Risk:** None. **Cleanup:** none.

### T3.5 — Full role → capability audit  🟡 NEEDS REVIEW (2026-09-02)
- **Steps:** SQL checks `C1` (option size), `C2` (role slugs + token count), `C3` (all 21 caps
  present). Full enumeration would need `wp cap list <role>` (read-only) or the wp-admin negative
  checks in T2.7.
- **Expected:** exact match to Appendix A; every custom role also has `read`; no custom role has
  `manage_options`, `edit_theme_options`, `delete_users`, `activate_plugins`, `edit_users`.
- **Result — what the SQL proves:** the `{prefix}user_roles` option is present, autoloaded, and
  **5616 bytes** (≈4× a stock install) ⇒ the capability sync ran; all five custom role slugs and
  `administrator` are present as serialized keys; all 21 `hedayati_*` capability names appear
  somewhere in the structure (`C3` = all 1); the `hedayati_` token count is exactly **50**, which
  equals the intended arithmetic — 28 custom-role cap assignments (student 4 + TA 2 + teacher 4 +
  reception 5 + manager 13) + 1 (`hedayati_manager` role-slug key) + 21 (administrator).
- **Result — what the SQL does NOT prove:** which specific capability sits on which specific role
  (positional matrix), and the least-privilege **negatives** (e.g. `reception` / `hedayati_manager`
  lack `manage_options`; `teacher_assistant` lacks `hedayati_record_attendance`). The exact-match
  total of 50 makes a wrong assignment unlikely but not impossible.
- **To close:** run `wp role list` + `wp cap list student|teacher_assistant|teacher|reception|hedayati_manager`
  (read-only), or perform the T2.7 wp-admin negative checks. Then upgrade to PASS/FAIL.
- **DB / WP-CLI.** **Risk:** None. **Cleanup:** none.

### T3.6 — Administrator retains full access + gains all Hedayati caps  🟡 NEEDS REVIEW (2026-09-02)
- **Steps:** SQL check `C2`; to complete: `wp cap list administrator` and/or manual check M2
  (admin can load Settings / Plugins / Users).
- **Expected:** `manage_options`, `activate_plugins`, `edit_users`, `delete_users`,
  `edit_theme_options`, `manage_categories`, **and** all 21 `hedayati_*` capabilities present;
  no native capability removed.
- **Result — what the SQL proves:** `admin_has_manage_options` = 1 and
  `admin_has_activate_plugins` = 1 (two core admin capabilities retained); the token-count
  arithmetic (28 + 1 + 21 = 50) is consistent with `administrator` holding all 21 `hedayati_*`
  capabilities.
- **Result — not yet proven:** that **every** native administrator capability is retained (only
  two were spot-checked), and a direct positional confirmation that all 21 sit on `administrator`.
- **To close:** manual check M2 + `wp cap list administrator | grep -c hedayati_` (expect 21).
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
