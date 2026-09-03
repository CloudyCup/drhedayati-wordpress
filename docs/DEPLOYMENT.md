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
> As of 2026-09-03 the canonical plugin is **Hedayati Core 1.5.2**. The following were **removed**
> from the repo this session (D27) because they held OLD code and are a deploy trap:
> `package-plugin/hedayati-core/` (`1.0.0`), the root `drhedayati-wordpress` diff dump, and the
> stale gitignored ZIPs `./hedayati-core.zip`, `plugin/hedayati-core.zip`, `staging-export/*.zip`
> (all `1.1.0`). If any reappear, they are junk — regenerate with `scripts/build-packages.ps1`.
> The **only** deployable artifacts are the ones that script just built.

---

## Deploy workflow (staging)

1. **Pre-flight**
   - `git status` clean; you are deploying a known commit. Note the commit hash.
   - Run checks: `node …/verify-phase2a.js` (74/74), `verify-phase2b.js` (171/171),
     `verify-phase2c.js` (25/25), `verify-audit-log.js` (98/98), `verify-jalali.js` (53/53) —
     421/0 total; where PHP is available, `php …/test-phase2a.php` (79/0), `php …/test-phase2b.php`
     (115/0), `php …/test-audit-log.php` (69/0), `php …/test-jalali.php` (39/0) — 302/0 total, and
     `php -l` on every PHP file (48/48, independently confirmed on PHP 8.4, 2026-09-03).
   - Confirm version headers bumped if behavior changed (`hedayati-core.php` / `style.css` /
     `HEDAYATI_CORE_VERSION` / `HEDAYATI_VERSION` / `CURRENT_DB_VERSION` / `ROLES_VERSION`).
     Current branch: `HEDAYATI_CORE_VERSION` `1.5.2`, `CURRENT_DB_VERSION` `2.2.0`,
     `ROLES_VERSION` `2.1.0`.
2. **Backup first** — take a full cPanel backup (files + database) and download an independent copy
   before replacing anything.
3. **Upload** — replace **only** the exact `wp-content/themes/hedayati/` and/or
   `wp-content/plugins/hedayati-core/` folder. Replacing individual files in place is acceptable
   for small fixes but folder replacement is cleaner.
4. **Run pending migrations** — replacing plugin files does **not** fire the activation hook.
   `Hedayati_DB_Schema::maybe_migrate()` and `Hedayati_Roles::maybe_sync_roles()` run on
   `admin_init`, so **log in to `wp-admin` and load the Dashboard / Plugins page** to trigger them.
5. **Verify migration & options** (as admin):
   - `{prefix}hedayati_user_phones` exists; after this branch's deploy, also
     `{prefix}hedayati_course_runs` / `_run_staff` / `_sessions` / `_enrollments` / `_attendance`
     / `_audit_log`.
   - Options present: `hedayati_core_db_version` = `2.2.0`, `hedayati_core_roles_version` =
     `2.1.0`, `hedayati_core_managed_capabilities` = 22 entries (incl. `hedayati_manage_teachers`).
   - Roles `student` / `teacher` / `teacher_assistant` / `reception` / `hedayati_manager` exist;
     `hedayati_manager` + `administrator` have `hedayati_manage_teachers`.
   - Full staging behavioural acceptance: `docs/PHASE_2A_ACCEPTANCE.md` **and**
     `docs/PHASE_2B_ACCEPTANCE.md` (both currently NOT RUN — a pre-merge gate).
6. **Flush rewrite rules** if permalinks/rewrites changed — Settings → Permalinks → Save (or
   deactivate/reactivate the plugin on a maintenance window). A permalink 404 after deploy is
   almost always stale rewrite rules.
7. **Purge LiteSpeed cache** if stale output is suspected.
8. **Smoke test** — homepage, `/courses/`, a category archive, a single course, a 404, light/dark
   toggle, mobile nav. Confirm no regression.
9. **Reconcile** — ensure the deployed code equals the repo commit. Mirror any emergency
   server-side edit back into Git immediately. Consider tagging the deployed commit.

### Rollback

Redeploy the previous artifact / restore the pre-deploy backup. The `2.0.0`, `2.1.0` and `2.2.0`
migrations only **add** tables, roles and one capability — they do not transform existing data —
so a code rollback is low risk; do **not** drop any `hedayati_*` table or delete
roles/capabilities as part of a routine rollback. Rolling the plugin back leaves the newer tables
in place but dormant (harmless); re-deploying re-attaches to them.

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
