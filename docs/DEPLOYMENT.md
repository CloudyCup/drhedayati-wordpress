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
| DB table prefix | `vShPz25x_` (example — **always use `$wpdb->prefix`**) | n/a |
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

Use `tar -a` (produces a zip). **Do not use PowerShell `Compress-Archive`** — it produced archives
this host mis-extracted / failed to recognize even when the listing looked correct.

```bash
# Theme
cd theme && tar -a -c -f hedayati.zip hedayati && cd ..

# Plugin
cd plugin && tar -a -c -f hedayati-core.zip hedayati-core && cd ..
```

Exclude dev-only files if you add any later (there are none today; the `tests/` folder is small and
harmless to ship). Build ZIPs are gitignored (`*.zip`).

> Ignore `package-plugin/` and the repo-root `hedayati-core.zip` — stale artifacts, not build
> inputs. Build from `plugin/hedayati-core/` and `theme/hedayati/` only.

---

## Deploy workflow (staging)

1. **Pre-flight**
   - `git status` clean; you are deploying a known commit. Note the commit hash.
   - Run checks: `node plugin/hedayati-core/tests/verify-phase2a.js` (expect 74/74); where PHP is
     available, `php plugin/hedayati-core/tests/test-phase2a.php` (expect 78/78) and `php -l` on
     changed files.
   - Confirm version headers bumped if behavior changed (`hedayati-core.php` /
     `style.css` / `HEDAYATI_CORE_VERSION` / `HEDAYATI_VERSION` / `CURRENT_DB_VERSION` /
     `ROLES_VERSION` as appropriate).
2. **Backup first** — take a full cPanel backup (files + database) and download an independent copy
   before replacing anything.
3. **Upload** — replace **only** the exact `wp-content/themes/hedayati/` and/or
   `wp-content/plugins/hedayati-core/` folder. Replacing individual files in place is acceptable
   for small fixes but folder replacement is cleaner.
4. **Run pending migrations** — replacing plugin files does **not** fire the activation hook.
   `Hedayati_DB_Schema::maybe_migrate()` and `Hedayati_Roles::maybe_sync_roles()` run on
   `admin_init`, so **log in to `wp-admin` and load the Dashboard / Plugins page** to trigger them.
5. **Verify migration & options** (as admin):
   - `{prefix}hedayati_user_phones` table exists.
   - Options present: `hedayati_core_db_version` = `2.0.0`, `hedayati_core_roles_version` =
     `2.0.0`, `hedayati_core_managed_capabilities` populated.
   - Roles `student` / `teacher` / `teacher_assistant` / `reception` / `hedayati_manager` exist.
6. **Flush rewrite rules** if permalinks/rewrites changed — Settings → Permalinks → Save (or
   deactivate/reactivate the plugin on a maintenance window). A permalink 404 after deploy is
   almost always stale rewrite rules.
7. **Purge LiteSpeed cache** if stale output is suspected.
8. **Smoke test** — homepage, `/courses/`, a category archive, a single course, a 404, light/dark
   toggle, mobile nav. Confirm no regression.
9. **Reconcile** — ensure the deployed code equals the repo commit. Mirror any emergency
   server-side edit back into Git immediately. Consider tagging the deployed commit.

### Rollback

Redeploy the previous artifact / restore the pre-deploy backup. The `2.0.0` migration only
**adds** a table and roles — it does not transform existing data — so a code rollback is low risk;
do **not** drop the phone table or delete roles as part of a routine rollback.

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
