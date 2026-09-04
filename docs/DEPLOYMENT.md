# DEPLOYMENT.md

**No secrets in this file.** No passwords, API keys, cPanel/hosting credentials, SFTP details,
database names, or WordPress salts. Those live only in the hosting control panel and
`wp-config.php` on the server.

---

## Environments

| | Staging | Production |
|---|---|---|
| Domain | `mystik.ir` | `drhedayati.com` |
| Stack | Fresh WordPress on **ParsPack** shared hosting (cPanel) | Legacy custom **ASP.NET / ASP.NET MVC + MSSQL** app on Plesk |
| PHP | 8.3 (raised from 8.1) | n/a |
| Cache | LiteSpeed (may be active) | n/a |
| DB table prefix | non-`wp_` randomized prefix — **always use `$wpdb->prefix`**, never a literal | n/a |
| Role | Active staging / QA target for the rebuild | Live business — **untouched until an approved cutover** |

There is **no** local WordPress in this repository and no CI. Deployment today is manual upload of
the theme and/or plugin folder via cPanel File Manager.

> A former `dev.drhedayati.com` on Plesk is **not** the current staging plan (superseded).

---

## What gets deployed

Two independent artifacts. Each ZIP's **top-level folder** must directly contain the entry file:

| Artifact | ZIP root must contain | Installs to |
|---|---|---|
| `hedayati.zip` | `hedayati/style.css` | `wp-content/themes/hedayati/` |
| `hedayati-core.zip` | `hedayati-core/hedayati-core.php` | `wp-content/plugins/hedayati-core/` |

No nested wrappers (`hedayati-core-1/hedayati-core/hedayati-core.php` is wrong).

---

## Building the packages

**Always use the build script — never package by hand, never reuse an old ZIP.**

```powershell
pwsh ./scripts/build-packages.ps1
```

It packages **only** `plugin/hedayati-core/` and `theme/hedayati/` with the approved `tar -a`
convention (D23 — **never** `Compress-Archive`, which produced archives this host mis-extracted),
writes fresh `staging-export/hedayati-core.zip` + `staging-export/hedayati.zip`, and **fails** unless:

- the plugin ZIP's top-level entry is `hedayati-core/hedayati-core.php`;
- the theme ZIP's top-level entry is `hedayati/style.css`;
- the `HEDAYATI_CORE_VERSION` **and** the header `Version:` line inside the plugin ZIP both equal
  `plugin/hedayati-core/hedayati-core.php`'s version.

The equivalent manual commands (if `pwsh` is unavailable) are
`cd plugin && tar -a -c -f ../staging-export/hedayati-core.zip hedayati-core` and
`cd theme && tar -a -c -f ../staging-export/hedayati.zip hedayati` — but run the script so the
layout/version checks happen.

The `tests/` folder is small and harmless to ship (no other dev-only files exist). All ZIPs are
gitignored (`*.zip`).

> ### ⚠️ Stale-artifact hazard — do NOT deploy these
> As of 2026-09-04 the canonical plugin is **Hedayati Core 1.5.3**. The following were **removed**
> from the repo this session (D27) because they held OLD code and are a deploy trap:
> `package-plugin/hedayati-core/` (`1.0.0`), the root `drhedayati-wordpress` diff dump, and the
> stale gitignored ZIPs `./hedayati-core.zip`, `plugin/hedayati-core.zip`, `staging-export/*.zip`
> (all `1.1.0`). If any reappear, they are junk — regenerate with `scripts/build-packages.ps1`.
> The **only** deployable artifacts are the ones that script just built.

---

## Required `wp-config.php` constants — Phase 2C (D36/D38)

**Pre-deploy checklist item, staging and production alike, before this branch's plugin version is
deployed anywhere it is expected to actually work.** None of these are in Git — provision them
directly in `wp-config.php` or equivalent server config.

| Constant | Required format | Required on | Behavior if missing/malformed |
|---|---|---|---|
| `HEDAYATI_DATA_ENCRYPTION_KEY` | base64 string decoding to exactly 32 raw bytes | Any environment where national ID will be stored | The feature fails closed — `set_national_id()`/`get_national_id_decrypted()` return an error; no plaintext fallback exists |
| `HEDAYATI_DATA_HMAC_KEY` | base64 string decoding to exactly 32 raw bytes, **independent** of the encryption key | Same as above | Same fail-closed behavior |
| `HEDAYATI_PRIVATE_UPLOADS_DIR` | absolute filesystem path, outside the web root, writable by PHP | **Required** on any environment except local/Docker-CI (`wp_get_environment_type() !== 'local'`) | Document upload fails closed — nothing is written to disk |

Generate a key with, e.g., `openssl rand -base64 32` (run on a machine you trust, paste the output
directly into `wp-config.php`, never into a chat/ticket/log). Never derive either key from
`SECURE_AUTH_KEY` or any other WordPress salt — rotating those must never make national-ID records
unreadable (D15/D36). `docker/docker-compose.yml` defines throwaway, committed, test-only versions
of the first two for the disposable Docker-CI containers only — **never** reuse those values
anywhere real.

Verify before relying on the feature: `wp eval 'var_export( Hedayati_Crypto::is_configured() );'`
(WP-CLI) should print `true`. On ParsPack specifically, confirm with hosting support whether PHP
can write outside `public_html`; if not, the protected in-webroot fallback pattern from D14 would
need to be revisited as a deliberate, documented exception before production use — do not assume.

---

## Deploy workflow (staging)

1. **Pre-flight**
   - `git status` clean; you are deploying a known commit. Note the commit hash.
   - Run checks: `node …/verify-phase2a.js` (74/74), `verify-phase2b.js` (208/208),
     `verify-phase2c.js` (131/131), `verify-audit-log.js` (98/98), `verify-jalali.js` (53/53) —
     564/0 total; where PHP is available, `php …/test-phase2a.php`, `test-phase2b.php`,
     `test-phase2c.php`, `test-audit-log.php`, `test-jalali.php`, and `php -l` on every PHP file.
   - The `Acceptance (Docker WordPress)` GitHub Actions workflow must be **green** on the branch
     being deployed — Phase 2C is not considered complete on static/mocked tests alone.
   - Confirm version headers bumped if behavior changed (`hedayati-core.php` / `style.css` /
     `HEDAYATI_CORE_VERSION` / `HEDAYATI_VERSION` / `CURRENT_DB_VERSION` / `ROLES_VERSION`).
     `feature/phase-2c-student-portal`: `HEDAYATI_CORE_VERSION` `1.6.0`, `CURRENT_DB_VERSION`
     `2.3.0`, `ROLES_VERSION` `2.2.0`.
   - Confirm the three `wp-config.php` constants above are provisioned on the target environment
     **before** deploying, if this deploy is expected to expose the identity/document features.
2. **Backup first** — take a full cPanel backup (files + database) and download an independent copy
   before replacing anything.
3. **Upload** — replace **only** the exact `wp-content/themes/hedayati/` and/or
   `wp-content/plugins/hedayati-core/` folder. Replacing individual files in place is acceptable
   for small fixes but folder replacement is cleaner.
4. **Run pending migrations** — replacing plugin files does **not** fire the activation hook.
   `Hedayati_DB_Schema::maybe_migrate()` and `Hedayati_Roles::maybe_sync_roles()` run on
   `admin_init`, so **log in to `wp-admin` and load the Dashboard / Plugins page** to trigger them.
5. **Verify migration & options** (as admin):
   - `{prefix}hedayati_user_phones` exists; also `{prefix}hedayati_course_runs` / `_run_staff` /
     `_sessions` / `_enrollments` / `_attendance` / `_audit_log`; after this branch's deploy, also
     `{prefix}hedayati_student_verification` / `_documents`.
   - Options present: `hedayati_core_db_version` = `2.3.0`, `hedayati_core_roles_version` =
     `2.2.0`, `hedayati_core_managed_capabilities` = 23 entries (incl. `hedayati_manage_teachers`
     and `hedayati_upload_student_documents`).
   - Roles `student` / `teacher` / `teacher_assistant` / `reception` / `hedayati_manager` exist;
     `hedayati_manager` + `administrator` have `hedayati_manage_teachers`; `reception` +
     `hedayati_manager` + `administrator` have `hedayati_upload_student_documents`.
   - `wp eval 'var_export( Hedayati_Crypto::is_configured() );'` prints `true` (confirms the two
     crypto constants above are actually live on this environment).
   - Full staging behavioural acceptance: `docs/PHASE_2A_ACCEPTANCE.md`, `docs/PHASE_2B_ACCEPTANCE.md`
     **and** `docs/PHASE_2C_ACCEPTANCE.md` (all a pre-merge/pre-deploy gate; see each file's own
     status line for what has actually been executed).
6. **Flush rewrite rules** if permalinks/rewrites changed — Settings → Permalinks → Save (or
   deactivate/reactivate the plugin on a maintenance window). A permalink 404 after deploy is
   almost always stale rewrite rules.
7. **Purge LiteSpeed cache** if stale output is suspected.
8. **Smoke test** — homepage, `/courses/`, a category archive, a single course, a 404, light/dark
   toggle, mobile nav. Confirm no regression.
9. **Reconcile** — ensure the deployed code equals the repo commit. Mirror any emergency
   server-side edit back into Git immediately. Consider tagging the deployed commit.

### Rollback

Redeploy the previous artifact / restore the pre-deploy backup. The `2.0.0`–`2.3.0` migrations only
**add** tables, roles and capabilities — they do not transform existing data — so a code rollback
is low risk; do **not** drop any `hedayati_*` table or delete roles/capabilities as part of a
routine rollback. Rolling the plugin back leaves the newer tables in place but dormant (harmless);
re-deploying re-attaches to them. If national-ID/document data has already been written and the
plugin is rolled back, the encrypted/HMAC values and stored files remain valid and readable once
the newer plugin version (and its crypto keys) are redeployed — nothing about a rollback changes or
invalidates existing encrypted data, provided the same keys stay configured.

---

## Migration safety rules

- Migrations are ordered, idempotent, and version-gated (`class-db-schema.php`).
- The stored version advances **only** after the migration verifies its own success.
- The lock is a plain option with 60s stale recovery — fine for the current tiny table; longer
  future migrations need stronger locking / ownership tokens before they ship.
- Never manually edit `hedayati_core_db_version` (or any marker) on the server to work around a
  failed migration. Fix the migration, redeploy, re-trigger.
- Every deploy needs an **explicit** post-deploy migration check (step 4–5) because of the
  `admin_init` trigger model.

---

## Production cutover (future — not yet planned in detail)

Before switching `drhedayati.com` DNS:

- Fresh, downloadable backups of **both** the legacy production (files + MSSQL) and the new staging
  site.
- Full inventory of legacy pages, URLs, courses, articles, images, forms, exam/certificate
  functions, contact details, and SEO metadata.
- Migration mapping + redirect rules for every changed path.
- Completed QA: roles/security, privacy, performance (Lighthouse/Web Vitals baseline),
  accessibility, RTL, light/dark, at common mobile/tablet/desktop widths.
- Agreed plan for DNS, email, TLS, caching, downtime window, rollback, analytics/Search Console.
- **Explicit owner approval** and a tested rollback path. No destructive production change or DNS
  switch without all of the above.
