# Staging runbook — D53 Manager Experience → mystik.ir

**No secrets in this file.** Operator (manual) runbook for the integrated
`feature/manager-experience` candidate. This build is **not merged** and **not deployed**;
`drhedayati.com` is untouched.

| | Value |
|---|---|
| Branch / packaged HEAD | `feature/manager-experience` @ `6473c5c059c53aeb22f406f4d84d4baa58094f53` |
| Plugin | `hedayati-core` **1.14.0** — `staging-export/hedayati-core.zip` |
| Theme | `hedayati` **1.3.1** — `staging-export/hedayati.zip` |
| DB schema | **2.4.0** (D53 added no schema) |
| Roles | **2.4.0**, **30** managed capabilities (D53 added no capability) |
| Static tests | 940 / 0 (9 Node suites) |
| Docker CI (`Acceptance (Docker WordPress)`, PR #1) | **GREEN** — run `34407929399`, 686 / 0 PASS, cleanup verified |
| Package build | `scripts/build-packages.ps1` — layout + version checks PASS |

Package SHA-256 (from the local build at `6473c5c`; re-verify after any rebuild):

- `hedayati-core.zip` — `79d16c49ae97099c65937680e7099ee7609c1a0b9a6ca71c92da7a0312e307eb`
- `hedayati.zip` — `e1fb7c5d2fefbb93f34c829e521428de6a174cacde92778e8b7b1ae3e3a2790e`

---

## 1. Staging prerequisites (Phase 2C / D36 / D38)

These three `wp-config.php` constants must be live on `mystik.ir` **before** the plugin is
relied on for identity/document features. None are in Git.

### 1a. Generate the two keys (on a trusted machine — never paste into chat/ticket/log)

```bash
openssl rand -base64 32
openssl rand -base64 32
```

Run twice; the two outputs are the **independent** encryption key and HMAC key. Each is a
44-character base64 string that decodes to exactly 32 raw bytes (`Hedayati_Crypto::KEY_BYTES`;
strict `base64_decode(..., true)`).

Alternative (equivalent):

```bash
php -r 'echo base64_encode(random_bytes(32)), "\n";'
```

**Never** derive either key from `SECURE_AUTH_KEY` / `AUTH_KEY` / any WordPress salt — rotating
salts must never make national-ID records unreadable (D15/D36). **Never** reuse the throwaway
values in `docker/docker-compose.yml`.

### 1b. Confirm the private-uploads path in cPanel (do NOT guess)

`HEDAYATI_PRIVATE_UPLOADS_DIR` must be an **absolute path outside `public_html` / `ABSPATH`**,
writable by the PHP user. To find the real home directory on ParsPack/cPanel:

- cPanel dashboard → **General Information** → **Home Directory** (commonly `/home/<cpanel_user>`,
  but confirm the exact prefix — do not assume `/home/example`), **or**
- **File Manager** → navigate one level above `public_html` → read the address/breadcrumb bar, **or**
- once WP-CLI works: `wp eval 'echo ABSPATH;'` prints `…/public_html/`; the private dir is a
  **sibling** of `public_html`, e.g. `<home>/hedayati-private-storage`.

Then create the directory (File Manager or SSH) as a sibling of `public_html`, e.g.
`<confirmed-home>/hedayati-private-storage`.

### 1c. `wp-config.php` placeholder lines

Add alongside the other `define()` calls, **before** `/* That's all, stop editing! */`:

```php
// ── Hedayati Core — Phase 2C identity/document encryption (D36/D38) ──────────
// Provisioned outside Git. Never commit real values here or anywhere else.
define( 'HEDAYATI_DATA_ENCRYPTION_KEY', '<PASTE base64, decodes to 32 bytes>' );
define( 'HEDAYATI_DATA_HMAC_KEY',       '<PASTE a DIFFERENT base64, decodes to 32 bytes>' );
define( 'HEDAYATI_PRIVATE_UPLOADS_DIR', '<confirmed absolute path, sibling of public_html>' );
```

Also confirm on this environment (should already be the case for staging):

```php
define( 'WP_ENVIRONMENT_TYPE', 'staging' );   // NOT 'local' — 'local' disables the private-dir requirement
define( 'WP_DEBUG', true );
define( 'WP_DEBUG_DISPLAY', false );           // errors never echo to the page (no key leakage)
define( 'WP_DEBUG_LOG', true );                // to wp-content/debug.log (not shown to visitors)
```

### 1d. Directory permissions (never `777`)

- Private-uploads directory: **`750`** if PHP runs as the cPanel account owner (the normal
  suPHP / PHP-FPM-per-account case) — owner rwx, group rx, no world access. If hosting support
  confirms the web-server group needs to write here, **`770`** is the next step — still never
  world-writable.
- Files the plugin writes get `640` automatically (`Hedayati_Document_Storage::save()` →
  `chmod( …, 0640 )`).
- If something "only works at 777", the owner/group is wrong — fix ownership (`chown` via File
  Manager / SSH per hosting support), do not loosen permissions.

### 1e. Harmless checks that config exists — **without revealing key values**

```bash
# 1. Both crypto constants are present, well-formed, independent, 32 bytes each:
wp eval 'var_export( Hedayati_Crypto::is_configured() );'          # must print: true

# 2. The three constants are DEFINED (booleans only — never prints a value):
wp eval 'foreach (["HEDAYATI_DATA_ENCRYPTION_KEY","HEDAYATI_DATA_HMAC_KEY","HEDAYATI_PRIVATE_UPLOADS_DIR"] as $c) printf("%s: %s\n", $c, defined($c) ? "defined" : "MISSING");'

# 3. Encryption key and HMAC key are NOT the same value (prints only "true"/"false"):
wp eval 'var_export( defined("HEDAYATI_DATA_ENCRYPTION_KEY") && defined("HEDAYATI_DATA_HMAC_KEY") && HEDAYATI_DATA_ENCRYPTION_KEY !== HEDAYATI_DATA_HMAC_KEY );'   # must print: true

# 4. Private root resolves to a real path OUTSIDE the webroot (prints the path string, not a WP_Error;
#    NOTE: this call wp_mkdir_p()'s the directory if absent — harmless/idempotent, writes no data):
wp eval '$r = Hedayati_Document_Storage::resolve_root(); var_export( is_string($r) ? $r : $r );'
wp eval '$r = Hedayati_Document_Storage::resolve_root(); var_export( is_string($r) && strpos($r, ABSPATH) !== 0 );'   # must print: true  (root is NOT under ABSPATH)

# 5. The one plaintext code path is capability-gated — run as a disposable RECEPTION QA user
#    against a disposable QA student who has a fabricated national ID on file: must be 403, never a value.
#    (Do this in the browser during acceptance — see the checklist. No CLI form for it.)
```

Do **not** `cat wp-config.php` or grep logs for the key to "confirm" it — that is the exact
exposure being guarded against. `WP_DEBUG_DISPLAY=false` + a 403 on the non-privileged reveal
are the read-only confirmations that exposure is impossible by design.

---

## 2. Safe deployment order (rollback-folder method)

> Do **not** drop tables. Do **not** edit `hedayati_core_db_version` / `hedayati_core_roles_version`
> by hand. The `2.x` migrations are **additive only** (new tables / roles / caps, no data transform).

**Naming:** use a single timestamp for the whole run, e.g. `TS=20260910-1400`.

### 2.1 — Full database backup

```bash
# WP-CLI (preferred):
wp db export ~/backups/mystik-db-$TS.sql
gzip ~/backups/mystik-db-$TS.sql
# AND: take a cPanel full backup (Files + DB) and download an independent copy.
```

### 2.2 — Copy the current plugin folder (rollback source)

```bash
cd ~/public_html/wp-content/plugins
cp -a hedayati-core hedayati-core.rollback-$TS
```

### 2.3 — Copy the current theme folder (rollback source)

```bash
cd ~/public_html/wp-content/themes
cp -a hedayati hedayati.rollback-$TS
```

(File Manager equivalent: select the folder → Copy → rename the copy.)

### 2.4 — Record the CURRENT on-server versions (write these down before touching anything)

```bash
wp eval 'echo "plugin: ", (defined("HEDAYATI_CORE_VERSION") ? HEDAYATI_CORE_VERSION : "n/a"), "\n";'
wp eval 'echo "theme:  ", wp_get_theme()->get("Version"), "\n";'
wp option get hedayati_core_db_version
wp option get hedayati_core_roles_version
wp eval 'echo count( (array) get_option("hedayati_core_managed_capabilities", []) ), " managed caps\n";'
wp eval 'global $wpdb; foreach ($wpdb->get_col("SHOW TABLES LIKE \"".$wpdb->esc_like($wpdb->prefix."hedayati_")."%\"") as $t) echo $t,"\n";'
```

> Expected: staging may still be on an **older** integrated candidate (per
> `docs/agent/STATUS.md`, D45–D52 was never per-phase-deployed). If DB/roles < `2.4.0`, this
> deploy also carries the additive D46–D52 gap — expected and safe.

### 2.5 — Configure the 3 `wp-config.php` values (§1c) if not already present

Skip only if §1e checks 1–4 already pass on the current site.

### 2.6 — Create / verify the private storage directory outside `public_html` (§1b, §1d)

```bash
mkdir -p <confirmed-home>/hedayati-private-storage
chmod 750 <confirmed-home>/hedayati-private-storage
ls -ld <confirmed-home>/hedayati-private-storage        # confirm perms + it is a SIBLING of public_html
```

### 2.7 — Install / replace `hedayati-core` 1.14.0

Upload `staging-export/hedayati-core.zip` via **Plugins → Add New → Upload** (WordPress will
replace the existing folder and keep it active), **or** File Manager: delete
`wp-content/plugins/hedayati-core/` and extract the ZIP so the folder is
`wp-content/plugins/hedayati-core/` (no nested wrapper).

### 2.8 — Verify the site still loads + wp-admin as **administrator** still works

- Load the public homepage (logged out) → 200, renders.
- Log in as the real administrator → `/wp-admin/` Dashboard loads, no fatal, no white screen.
- `tail -n 50 ~/public_html/wp-content/debug.log` → no new `PHP Fatal`.

> If white screen / fatal here → **STOP, roll back** (§6).

### 2.9 — Trigger the additive DB migration + roles sync (safe)

Replacing plugin files does **not** fire the activation hook. `Hedayati_DB_Schema::maybe_migrate()`
and `Hedayati_Roles::maybe_sync_roles()` run on `admin_init`:

- As the administrator, **load the wp-admin Dashboard, then the Plugins page** (two page loads).
- Or explicitly: `wp eval 'Hedayati_DB_Schema::maybe_migrate(); Hedayati_Roles::register_roles();'`

### 2.10 — Verify DB = `2.4.0`

```bash
wp option get hedayati_core_db_version        # -> 2.4.0
```

### 2.11 — Verify roles = `2.4.0`

```bash
wp option get hedayati_core_roles_version     # -> 2.4.0
wp eval 'echo count( (array) get_option("hedayati_core_managed_capabilities", []) ), "\n";'   # -> 30
```

### 2.12 — Verify the expected tables still exist (nothing lost)

```bash
wp eval 'global $wpdb; $need=["hedayati_user_phones","hedayati_course_runs","hedayati_run_staff","hedayati_sessions","hedayati_enrollments","hedayati_attendance","hedayati_audit_log"]; foreach($need as $s){$t=$wpdb->prefix.$s; echo $t,": ", ($wpdb->get_var("SHOW TABLES LIKE \"$t\"")?"OK":"MISSING"),"\n";}'
```

### 2.13 — Verify the Phase 2C / D46–D52 tables exist

```bash
wp eval 'global $wpdb; $need=["hedayati_student_verification","hedayati_documents","hedayati_consultations","hedayati_certificates","hedayati_session_materials","hedayati_support_tickets","hedayati_support_messages","hedayati_notifications"]; foreach($need as $s){$t=$wpdb->prefix.$s; echo $t,": ", ($wpdb->get_var("SHOW TABLES LIKE \"$t\"")?"OK":"MISSING"),"\n";}'
```

### 2.14 — Install / replace the theme 1.3.1

Upload `staging-export/hedayati.zip` via **Appearance → Themes → Add New → Upload** (replace),
**or** File Manager delete+extract so the folder is `wp-content/themes/hedayati/`.

### 2.15 — Verify the public site loads

Homepage, `/courses/`, a category archive, a single course, a 404 — all render, light + dark,
mobile nav works.

### 2.16 — Verify `/login/`

Logged out, visit `https://mystik.ir/login/` → the branded split-panel form renders (not a
wp-login screen). If it 404s: **Settings → Permalinks → Save** (flush rewrites), then re-check;
if still missing, `wp eval 'Hedayati_Login::maybe_create_page();'` and re-check.

### 2.17 — Verify `/panel/`

Log in as a disposable `hedayati_manager` QA user → `https://mystik.ir/panel/` → the manager
dashboard renders with the module cards (no duplicates, styled).

### 2.18 — Verify `/account/`

Log in as a disposable `student` QA user → `https://mystik.ir/account/` → the student shell
renders.

### 2.19 — Purge LiteSpeed cache, then start detailed browser acceptance (§5)

**Purge all** in the LiteSpeed Cache plugin (or cPanel) — the theme version bump (1.3.0→1.3.1)
means `account.css` must be re-fetched. Hard-refresh (Ctrl/Cmd+Shift+R) each QA browser once.

---

## 3. Post-deploy read-only verification (copy/paste)

```bash
# Versions
wp eval 'echo "HEDAYATI_CORE_VERSION = ", HEDAYATI_CORE_VERSION, "\n";'                 # 1.14.0
wp eval 'echo "CURRENT_DB_VERSION    = ", Hedayati_DB_Schema::CURRENT_DB_VERSION, "\n";' # 2.4.0
wp option get hedayati_core_db_version                                                  # 2.4.0
wp eval 'echo "ROLES_VERSION         = ", Hedayati_Roles::ROLES_VERSION, "\n";'          # 2.4.0
wp option get hedayati_core_roles_version                                               # 2.4.0
wp eval 'echo "theme Version         = ", wp_get_theme()->get("Version"), "\n";'         # 1.3.1

# Managed capability count
wp eval 'echo count( (array) get_option("hedayati_core_managed_capabilities", []) ), " (expect 30)\n";'

# All 15 Hedayati tables present, none of the old ones lost
wp eval 'global $wpdb; $need=["hedayati_user_phones","hedayati_course_runs","hedayati_run_staff","hedayati_sessions","hedayati_enrollments","hedayati_attendance","hedayati_audit_log","hedayati_student_verification","hedayati_documents","hedayati_consultations","hedayati_certificates","hedayati_session_materials","hedayati_support_tickets","hedayati_support_messages","hedayati_notifications"]; $ok=0; foreach($need as $s){$t=$wpdb->prefix.$s; $e=(bool)$wpdb->get_var("SHOW TABLES LIKE \"$t\""); $ok+=$e; echo ($e?"OK  ":"MISS"), " $t\n";} echo "$ok / 15\n";'

# No fatal errors during/after deploy
tail -n 80 ~/public_html/wp-content/debug.log | grep -i "fatal\|uncaught" || echo "no fatals"

# Crypto configured (no value printed)
wp eval 'var_export( Hedayati_Crypto::is_configured() );'                                # true

# Pages exist
wp eval 'foreach (["login","panel","account","about","contact","consult","teachers","verify"] as $s){$p=get_page_by_path($s); printf("%-9s %s\n", $s, $p ? "#".$p->ID." (".$p->post_status.")" : "MISSING");}'

# Roles present + a manager can manage the D53 areas
wp eval 'foreach (["student","teacher","teacher_assistant","reception","hedayati_manager"] as $r) echo $r, ": ", (get_role($r) ? "OK" : "MISSING"), "\n";'
wp eval '$m=get_role("hedayati_manager"); foreach (["hedayati_manage_courses","hedayati_manage_course_runs","hedayati_manage_teachers","hedayati_verify_students","hedayati_view_private_documents","hedayati_view_audit_logs"] as $c) echo $c, ": ", ($m->has_cap($c) ? "OK" : "NO"), "\n";'
wp eval 'echo "reception has hedayati_verify_students (must be NO): ", (get_role("reception")->has_cap("hedayati_verify_students") ? "YES-BAD" : "NO-OK"), "\n";'
```

---

## 4. Rollback

### 4a. Roll back if ANY of these — during or after deploy

| Trigger | Notes |
|---|---|
| White screen / PHP fatal on the public site or wp-admin | after either the plugin or the theme upload |
| DB migration fails to reach `2.4.0`, or `maybe_migrate()` throws | do **not** hand-edit the marker — roll back and fix the migration in the repo |
| Login broken — the administrator **or** any QA role cannot authenticate | including a `/login/` redirect loop or `wp-login.php` being unreachable for the admin |
| `hedayati_manager` / `reception` / `teacher` / `student` cannot reach their workspace, or a redirect loop between `/wp-admin/` and `/panel/` `/` `/account/` | |
| A critical authorization bypass | a non-`hedayati_verify_students` user can reveal a plaintext national ID; a student reaches `/panel/`; a non-admin reaches an operational wp-admin screen without the redirect firing |
| Private file exposure | any document reachable at a predictable `wp-content/uploads/...` URL; the private dir resolving **inside** `public_html` |
| Unrecoverable UI / runtime regression on a launch-critical surface | homepage, course archive, single course, `/panel/`, `/account/`, `/login/` |

### 4b. Rollback steps (prefer code-folder restore; DB restore is last resort)

```bash
# 1. Restore the previous PLUGIN folder
cd ~/public_html/wp-content/plugins
rm -rf hedayati-core
mv hedayati-core.rollback-$TS hedayati-core

# 2. Restore the previous THEME folder
cd ~/public_html/wp-content/themes
rm -rf hedayati
mv hedayati.rollback-$TS hedayati

# 3. Load wp-admin as admin (re-runs admin_init) + Purge LiteSpeed cache + hard refresh.
# 4. Re-run the §3 checks — versions should read the PRE-deploy numbers you recorded in §2.4.
```

- The new tables/roles/caps left behind by a partial migration are **dormant and harmless** with
  the older plugin — do **not** drop them, do **not** delete roles/caps.
- **Restore the DB (from §2.1)** *only* if the additive migration itself corrupted data or left
  the schema in a state that cannot safely remain dormant (very unlikely for additive `dbDelta`).
  If you restore the DB, also restore both code folders to the matching pre-deploy versions.
- Encrypted national-ID / document data written before a rollback stays valid and readable once
  the 1.14.0 plugin (with the **same** crypto keys) is redeployed — a rollback never invalidates
  it.

---

## 5. Integrated browser acceptance checklist (synthetic QA data only)

> Create disposable QA users per role (`qa_admin` is the real admin; make `qa_mgr`, `qa_recep`,
> `qa_teacher`, `qa_ta`, `qa_stu1`, `qa_stu2`). Use **fabricated** national IDs (valid checksum,
> e.g. `0123456789`), fake documents, synthetic certificates. **No real student PII.** Clean up
> all QA data at the end.

### ADMINISTRATOR
- [ ] `/wp-admin/` loads fully; no fatal in `debug.log`
- [ ] Gutenberg opens and saves a draft Course
- [ ] Native screens still work: `edit.php?post_type=teacher`, `edit.php?post_type=course`,
      `admin.php?page=hedayati-academic`, `admin.php?page=hedayati-students`,
      `admin.php?page=hedayati-academic-audit`, Settings → هدایتی
- [ ] admin is **not** redirected away from any wp-admin screen

### MANAGER (`qa_mgr`)
- [ ] `/panel/` dashboard — one card each for courses / teachers / academic / students / audit /
      settings / consultations / certificates / support / notifications (no duplicates, styled)
- [ ] `?view=courses` — list renders; **create** a course (`?view=course-new`) with title,
      english name, duration, category (+ inline new category), pick an existing image, tick
      featured, publish → lands on `?view=course-edit`; re-open → every value round-trips
- [ ] `?view=course-edit` — change fields, save; toggle publish/draft; Shamsi `next_start_date`
      accepted and shown back
- [ ] `?view=featured` — toggle featured; the 9th featured is refused with the 8-slot message
- [ ] `?view=teachers` — create, edit, link a `teacher`-role user (1:1 rule — linking an
      already-linked user is refused), trash
- [ ] `?view=academic` — create a run; filter by status; open it (`&run=N`)
  - [ ] edit run (label, status, registration status, start/end dates in Shamsi, capacity,
        tuition, notes, schedule)
  - [ ] assign a primary instructor (teacher) + an assistant (user); remove one
  - [ ] add a session (Shamsi date + `HH:MM`); delete a session
  - [ ] enroll `qa_stu1` + `qa_stu2`; set capacity 2 → a 3rd enrollment is refused; change one
        status; remove one
  - [ ] toggle "نمایش عمومی این کلاس" → the single course page shows the run's date/fee only when
        both this **and** the course-level catalog opt-in are on
- [ ] `?view=academic&asession=N` — as a **teacher assigned to the run** (`qa_teacher`), record
      attendance + notes; the **manager** is 403 on the attendance POST (lacks
      `hedayati_record_attendance`) — confirm the manager cannot save attendance
- [ ] `?view=students` — search `qa_stu1`; the student detail shows the **reviewer block**:
  - [ ] "نمایش شناسه ملی" opens a new tab with the fabricated value once, back-link returns to
        the panel; a 2nd load re-audits
  - [ ] approve a pending verification → status `verified`; (separately) reject → `rejected` with
        a staff-only note the student never sees
  - [ ] private documents: list, **download** (opens/streams — URL contains `admin-post.php`, not
        `wp-content/uploads`), archive-confirm, then (after the 7-day window, or just confirm the
        control appears only when eligible) purge
- [ ] `?view=audit` — filter by object-type / action / actor; paginate; **no** IP / user-agent
      column
- [ ] `?view=settings` — edit institute name / phones / addresses → reflected in the footer
- [ ] consultations queue, certificates issue/revoke + `/verify/`, materials (link/note/file),
      support queue, notifications badge — all function
- [ ] direct `https://mystik.ir/wp-admin/` → **redirected to `/panel/`**; the WordPress admin bar
      is absent on `/panel/` and the public site
- [ ] `https://mystik.ir/wp-admin/profile.php` **still loads** (own-account carve-out) — change
      the manager's own password there, then log in with it

### RECEPTION (`qa_recep`)
- [ ] `/panel/?view=students` — **create** a student account → a one-shot temp password is shown
      exactly once
- [ ] enroll that student into a scheduled run
- [ ] national-ID **intake** (set a fabricated ID) + **document upload** (a fake PDF/JPG)
- [ ] **initiate** verification
- [ ] consultations / support queues work where reception is authorized
- [ ] reception detail view shows **no** "نمایش شناسه ملی" button and **no** approve/reject and
      **no** private-document list (lacks `hedayati_verify_students` /
      `hedayati_view_private_documents`)
- [ ] the privileged reveal endpoint returns **403** for reception (test by POSTing the reveal
      form fields, or simply confirm the control is absent and the service denies —
      `wp eval` in §1e check 5 context)
- [ ] direct `/wp-admin/` → **redirected to `/panel/`**

### TEACHER (`qa_teacher`)
- [ ] `/panel/?view=run&run_id=N` for an assigned run — roster (names only), sessions, attendance
      grid, "new session", materials section
- [ ] can **record attendance**
- [ ] **cannot** see manager-only modules (courses/teachers/academic-list/settings/audit not in
      the sidebar; direct `?view=teachers` / `?view=academic` → 403)
- [ ] direct `/wp-admin/` → **redirected to `/panel/`**

### TA (`qa_ta`)
- [ ] same assigned-run view — roster + sessions visible
- [ ] attendance select is **disabled** / the save is denied (TA lacks `hedayati_record_attendance`)
- [ ] no session-material management (TA lacks `hedayati_manage_session_materials`)
- [ ] direct `/wp-admin/` → **redirected to `/panel/`**

### STUDENT (`qa_stu1`)
- [ ] `/account/` — dashboard, profile (edit address/phone), enrollments, schedule (only *their*
      active enrollments, no past sessions), progress (held/total + %), certificates, support,
      notifications, verification (status + national-ID **presence only**, never the value),
      "مدارک من"
- [ ] national ID is **never** shown in plaintext anywhere in `/account/`
- [ ] `/panel/` → **403 / denied**
- [ ] direct `/wp-admin/` → **redirected to `/account/`**

### LOGIN / AUTH (logged out)
- [ ] `https://mystik.ir/login/` — branded split-panel page, RTL, Vazirmatn, light **and** dark,
      desktop + mobile (≤720px collapses), no WordPress branding
- [ ] username + password login → routes to `/panel/` (staff) or `/account/` (student)
- [ ] Iranian phone login — `0914…`, `+98914…`, `989…`, Persian digits — all resolve to the same
      account
- [ ] wrong password → **one** generic Persian error ("نام کاربری/شمارهٔ همراه یا رمز عبور صحیح
      نیست."), stays on `/login/`
- [ ] 5 failed attempts for one identifier → lockout message; ~15 min later it clears
- [ ] "مرا به خاطر بسپار" — session persists after browser close
- [ ] `?action=lostpassword` — submit an **unknown** identifier and a **real** one → **identical**
      "ایمیل خود را بررسی کنید" screen (no enumeration)
- [ ] a real reset email arrives (check the QA inbox / mail log) with a link to
      `mystik.ir/login/?action=rp&key=…&login=…`
- [ ] the reset link → lands on `?action=resetpass` (key consumed off the URL) → set a new
      password (≥ 12 chars, mismatch rejected) → "رمز عبور جدید ثبت شد" → log in with it
- [ ] an expired/garbage reset key → "پیوند بازیابی نامعتبر" + link to request again
- [ ] forced first-login change — reception creates an account; first login with the temp
      password → the styled forced-change screen (not wp-admin); set a password → lands in the
      workspace already logged in
- [ ] a logged-out visitor hitting `https://mystik.ir/wp-login.php` → **bounced to `/login/`**
      (preserving `redirect_to`); `?action=logout` and the admin's `/wp-login.php` still work
- [ ] administrator can still log in via `/login/` **or** `/wp-login.php` → lands at `/wp-admin/`

### PUBLIC (logged out)
- [ ] homepage (NavigatorHome), course archive, a category archive, a single course, a 404
- [ ] single course shows a run's date/fee **only** when the per-run opt-in is on (test one on,
      one off)
- [ ] `/consult/` form submits (nonce + honeypot) → appears in the manager consultations queue
- [ ] `/verify/` — a valid synthetic certificate code shows name/course/date/institute/code
      only; an unknown/revoked code shows a non-sensitive status
- [ ] `/about/`, `/contact/`, `/teachers/` render; only opted-in teachers appear on `/teachers/`
- [ ] light/dark toggle, desktop + mobile, Persian RTL, **no horizontal overflow** on any page

### SECURITY (spot-checks with synthetic data)
- [ ] direct-URL: as `qa_stu1`, open `/panel/?view=course-edit&course_id=<real id>` → 403;
      `/panel/?view=academic&run=<real id>` → 403
- [ ] as `qa_teacher`, `/panel/?view=academic&run=<a run they are NOT on>` → "دسترسی ندارید"
- [ ] as `qa_mgr`, `?view=course-edit&course_id=<a non-course post id>` → "دوره یافت نشد"
- [ ] national ID: nowhere in any student-facing page, URL, notice, or `debug.log`
- [ ] private documents: no `wp-content/uploads/...` URL for any uploaded doc; the private dir is
      a sibling of `public_html`
- [ ] support tickets: `qa_stu2` cannot open/reply to `qa_stu1`'s ticket (change the `ticket` id
      in the URL → denied)
- [ ] `/verify/` and the certificates list expose no phone / national ID / address / documents
- [ ] `?view=audit` shows no IP / user-agent, no decrypted values, no reviewer notes
- [ ] every non-admin role: direct `/wp-admin/` → redirect (the **only** interactive wp-admin
      page a non-admin can still open is `profile.php` — documented carve-out, MX-6)
- [ ] `redirect_to` on `/login/` — try `?redirect_to=https://evil.example/` → after login you land
      on the safe local workspace, never the external URL

---

## 6. Rollback (quick reference)

See §4. Criteria: white screen / fatal · migration failure · login broken · role access broken ·
authorization bypass · private-file exposure · unrecoverable launch-critical regression. Steps:
restore `hedayati-core.rollback-$TS` → restore `hedayati.rollback-$TS` → load wp-admin → purge
cache → re-verify pre-deploy versions. DB restore only if the additive migration itself caused an
unrecoverable problem. Never drop the new `hedayati_*` tables casually.
