# Phase 2B — Controlled Staging Deployment Plan (`mystik.ir`)

**Status: PLAN ONLY — NOT EXECUTED.** Deployment and migration execution are state-changing and
are **not** performed here. This document is the exact operator runbook to deploy the current
`feature/phase-2b-academic-operations` branch to **staging only**.

**Production (`drhedayati.com`) is not touched by any step here.**
**Do not merge to `main`. Do not push. Do not run any Category-4 / destructive test.**

---

## 0. Facts this plan is built on

| Item | Value |
|---|---|
| Branch | `feature/phase-2b-academic-operations` |
| Commit | the `1.5.2` Teacher-CPT meta-cap fix commit — confirm with `git rev-parse HEAD` before building (was `4c55468` at first draft; `1.5.2` adds the `class-teacher.php` fix + test guards) |
| Plugin version (branch) | Hedayati Core **1.5.2** (`1.5.1` had a Teacher CPT meta-capability collision — Phase 2B acceptance **T1 failed on staging 1.5.1**; `1.5.2` fixes `includes/class-teacher.php` only, no schema/roles change. Retest T1 after this deploy) |
| Theme version (branch) | Hedayati **1.0.0** — **unchanged vs `main`** (`git diff --stat main -- theme/hedayati/` = empty) |
| `CURRENT_DB_VERSION` | **2.2.0** |
| `ROLES_VERSION` | **2.1.0** |
| Staging now (Phase 2A) | plugin `1.1.0`, theme `1.0.0`, `hedayati_core_db_version` `2.0.0`, `hedayati_core_roles_version` `2.0.0`, `hedayati_core_managed_capabilities` = 21 entries |
| Migrations that will run | `2.0.0` → **`2.1.0`** (5 academic-operations tables) → **`2.2.0`** (1 metadata-only audit-log table), in order, on `admin_init` |
| Phone table | `{prefix}hedayati_user_phones` — **must remain untouched**; migrations `2.1.0`/`2.2.0` are additive and never reference it |
| Trigger model | `admin_init` only — replacing plugin files does **not** fire the activation hook; an admin must load a wp-admin page |
| Canonical build | `pwsh ./scripts/build-packages.ps1` → `staging-export/hedayati-core.zip` + `staging-export/hedayati.zip`. **No historical/stale ZIP.** |
| Operator tools | cPanel / WordPress Toolkit, phpMyAdmin, WP-CLI (via the hosting toolkit), browser / wp-admin |

Because the theme is byte-identical to what is already on staging, **this is effectively a
plugin-only deployment**. The theme ZIP is rebuilt for completeness; re-uploading it is optional
and is a no-op.

---

## Deployment checklist

### 1. Fresh pre-deployment staging backup (MANDATORY)

1. cPanel → **WordPress Toolkit** → the `mystik.ir` install → **Back Up / Restore** → **Back Up**
   (files **+** database). Wait for completion.
2. Also take a cPanel full-account backup **or** a phpMyAdmin **Export** (Custom → all tables →
   "Add DROP TABLE" off, gzip) of the site database.
3. **Download an independent copy off-server.** Record: backup name, timestamp, size, download
   location.
4. In phpMyAdmin, record the current baseline (paste output into the deploy log). Substitute the
   real prefix for `P_`:
   ```sql
   SELECT option_name, option_value FROM P_options
   WHERE option_name IN (
     'hedayati_core_db_version','hedayati_core_roles_version',
     'hedayati_core_managed_capabilities','hedayati_db_migration_lock'
   );
   SELECT table_name FROM information_schema.tables
   WHERE table_schema = DATABASE() AND table_name LIKE '%hedayati%'
   ORDER BY table_name;
   SELECT COUNT(*) AS phone_rows FROM P_hedayati_user_phones;
   SHOW CREATE TABLE P_hedayati_user_phones;
   ```
   Expected now: db_version `2.0.0`, roles_version `2.0.0`, managed caps = 21-element `a:21:{…}`,
   **no** migration lock, tables = `…hedayati_user_phones` only, phone rows = 0 (or whatever the
   QA baseline is — it must not change across the deploy).

> Do not proceed until the off-server backup download is confirmed complete.

### 2. Package rebuild (from canonical source only)

On the machine with the repo checked out at the target commit:

```bash
git rev-parse HEAD          # confirm == 4c55468 (or the intended commit); working tree clean
git status                  # must be clean
```

```powershell
pwsh ./scripts/build-packages.ps1
```

- The script packages **only** `plugin/hedayati-core/` and `theme/hedayati/` with `tar -a`.
- It fails on wrong layout or a plugin version mismatch. A clean run prints
  `OK  …/hedayati-core.zip  version: 1.5.2` and `OK  …/hedayati.zip`.
- **Do not** use `Compress-Archive`, any `package-plugin/` output, or any pre-existing ZIP.
- Delete any older ZIPs first if unsure: `Remove-Item staging-export/*.zip` then rebuild.

### 3. Package version / layout verification (before upload)

Run the pre-flight checks:

```bash
node plugin/hedayati-core/tests/verify-phase2a.js   # expect 74/0
node plugin/hedayati-core/tests/verify-phase2b.js   # expect 171/0
node plugin/hedayati-core/tests/verify-phase2c.js   # expect 25/0
node plugin/hedayati-core/tests/verify-audit-log.js # expect 98/0
node plugin/hedayati-core/tests/verify-jalali.js    # expect 53/0
```

Inspect the built archives:

```bash
tar -tf staging-export/hedayati-core.zip | head        # first entry: hedayati-core/hedayati-core.php
tar -tf staging-export/hedayati-core.zip | wc -l       # ~43 entries
tar -xO -f staging-export/hedayati-core.zip hedayati-core/hedayati-core.php | grep -E "Version:|HEDAYATI_CORE_VERSION"
#   expect  Version:  1.5.2   AND   define( 'HEDAYATI_CORE_VERSION', '1.5.2' )
tar -tf staging-export/hedayati.zip | head             # first entry: hedayati/style.css
```

- No nested wrapper folder (`hedayati-core-1/hedayati-core/…` is wrong).
- Confirm the ZIP has `includes/class-db-schema.php`, `class-audit-log.php`, `class-jalali.php`,
  `class-academic-admin.php`, `class-teacher.php`, and the five `class-*-service.php` files.

### 4. Current staging version confirmation (immediately before replacing files)

In wp-admin (admin session, keep it open in a separate browser/tab throughout — it survives a
login lockout):

- Plugins → **Hedayati Core** shows **1.1.0**, Active.
- Appearance → Themes → **Hedayati** shows **1.0.0**, Active.
- Tools → Site Health → Info → confirm PHP 8.3.
- Re-run the phpMyAdmin baseline queries from step 1 and confirm they still read `2.0.0` / `2.0.0`
  / 21 caps / no lock. This is the "known starting point" the rollback returns to.

### 5. Safest plugin / theme replacement procedure

Use the **WordPress Toolkit** where possible; File Manager is the fallback.

**Plugin (the real change):**

1. Put the site in a short maintenance window if any staff might be using «عملیات آموزشی» screens
   (none exist yet on staging, so this is low risk).
2. cPanel → **File Manager** → `wp-content/plugins/`.
3. **Rename** `hedayati-core` → `hedayati-core.OLD-1.1.0` (do **not** delete — this is the instant
   rollback).
4. Upload `hedayati-core.zip` into `wp-content/plugins/` and **Extract** it. Confirm the path is
   `wp-content/plugins/hedayati-core/hedayati-core.php` (no nested folder).
5. Fix ownership/permissions if the host requires it (match the `.OLD` folder: dirs `755`, files
   `644`).
6. Do **not** deactivate/reactivate the plugin (that fires the activation hook + a rewrite flush —
   unnecessary and riskier). The plugin stays "active" across the folder swap because WordPress
   keys `active_plugins` on the folder/file name, which is unchanged.
7. Delete `hedayati-core.OLD-1.1.0` only **after** acceptance passes (step 15) — keep it for the
   rollback window.

**Alternative (WordPress Toolkit "Deploy" / plugin upload):** Toolkit → Plugins → upload ZIP →
"Replace existing". Same outcome; still no activation-hook fire.

**Theme:** unchanged vs staging — **skip**. (If you re-upload it anyway for hygiene: rename
`themes/hedayati` → `themes/hedayati.OLD`, extract `hedayati.zip`, verify `style.css` Version
`1.0.0`. It is a no-op.)

### 6. Plugin before or after theme?

**Plugin only.** The theme is identical to what is deployed, so there is nothing to sequence.
If (and only if) you choose to re-deploy the theme as well: **theme first, then plugin**, so the
plugin's `admin_init` migration/roles sync (step 7) runs last, against a fully settled filesystem.
Never interleave: finish one folder swap completely before starting the other.

### 7. Trigger / confirm the `admin_init` migrations safely

The migration framework is ordered, version-gated, idempotent, and advances the stored version
**only after each migration verifies its own tables exist**. A 60s stale-lock recovery guards a
crashed run.

1. In the **admin browser session**, load **wp-admin → Dashboard**, then **Plugins**, then
   **Settings → Hedayati**. Each `admin_init` runs `Hedayati_DB_Schema::maybe_migrate()` and
   `Hedayati_Roles::maybe_sync_roles()`.
2. If WP-CLI is available via the toolkit, the deterministic way is:
   ```bash
   wp eval 'Hedayati_DB_Schema::maybe_migrate(); Hedayati_Roles::maybe_sync_roles();'
   wp option get hedayati_core_db_version        # expect 2.2.0
   wp option get hedayati_core_roles_version     # expect 2.1.0
   wp option get hedayati_db_migration_lock      # expect: not set / empty
   ```
3. If `hedayati_db_migration_lock` is still present after a minute, **stop** — do not hand-edit
   options. Wait 60s (stale-lock recovery), load a wp-admin page again, re-check. If it persists,
   check PHP error logs, then roll back (step 12) and investigate off-staging.
4. **Never** manually set `hedayati_core_db_version` / `hedayati_core_roles_version` to work around
   a failed migration (`docs/DEPLOYMENT.md` migration-safety rules).

### 8. Exact post-deployment DB version checks (phpMyAdmin)

```sql
SELECT option_name, option_value FROM P_options
WHERE option_name IN (
  'hedayati_core_db_version','hedayati_core_roles_version',
  'hedayati_core_managed_capabilities','hedayati_db_migration_lock'
);
```

| Option | Expected after deploy |
|---|---|
| `hedayati_core_db_version` | `2.2.0` |
| `hedayati_core_roles_version` | `2.1.0` |
| `hedayati_core_managed_capabilities` | serialized array, **22** entries (`a:22:{…}`), includes `hedayati_manage_teachers` |
| `hedayati_db_migration_lock` | **absent / empty** |

Also confirm the plugin header now reports **1.5.2** (Plugins screen) and
`HEDAYATI_CORE_VERSION` `1.5.2` (Site Health → Info, or `wp plugin get hedayati-core --field=version`).

### 9. Exact expected six Phase 2B / 2.2.0 tables

```sql
SELECT table_name, engine, table_collation
FROM information_schema.tables
WHERE table_schema = DATABASE() AND table_name LIKE '%hedayati%'
ORDER BY table_name;
```

Must now list **seven** `…hedayati_*` tables — the pre-existing phone table **plus the six new
ones** (all `InnoDB`, `utf8mb4…`), under the real (non-`wp_`) prefix:

1. `{prefix}hedayati_course_runs`
2. `{prefix}hedayati_run_staff`
3. `{prefix}hedayati_sessions`
4. `{prefix}hedayati_enrollments`
5. `{prefix}hedayati_attendance`
6. `{prefix}hedayati_audit_log`

(+ `{prefix}hedayati_user_phones`, unchanged.)

Spot-check structure against `class-db-schema.php::migrate_2_1_0()` / `migrate_2_2_0()`:

```sql
SHOW CREATE TABLE P_hedayati_sessions;      -- UNIQUE KEY uq_run_session (run_id, session_number)
SHOW CREATE TABLE P_hedayati_enrollments;   -- UNIQUE KEY uq_run_user (run_id, user_id)
SHOW CREATE TABLE P_hedayati_attendance;    -- UNIQUE KEY uq_session_enrollment (session_id, enrollment_id)
SHOW CREATE TABLE P_hedayati_audit_log;     -- NO ip / user_agent / updated_at column;
                                            -- KEY idx_object (object_type,object_id), idx_actor, idx_action, idx_created_at
```

Phone table unchanged:

```sql
SHOW CREATE TABLE P_hedayati_user_phones;                 -- identical to the step-1 capture
SELECT COUNT(*) FROM P_hedayati_user_phones;              -- identical to the step-1 count
```

### 10. Roles-version / capability checks

Via WP-CLI (preferred):

```bash
wp option get hedayati_core_roles_version                       # 2.1.0
wp cap list administrator | grep -c '^hedayati_'                # 22
wp cap list hedayati_manager | grep -c '^hedayati_'             # 14  (13 Phase-2A + hedayati_manage_teachers)
wp cap list hedayati_manager | grep hedayati_manage_teachers    # present
for r in student teacher_assistant teacher reception; do
  echo "== $r =="; wp cap list $r | grep hedayati_manage_teachers || echo "  (correctly absent)"
done
wp role list                                                    # 5 custom roles + WP defaults still present
```

Expected: only `administrator` and `hedayati_manager` gain `hedayati_manage_teachers`; **no
Phase-2A capability lost from any role**; the five custom roles still exist; `administrator` keeps
every native capability. (This also closes Phase 2A T3.5's forward-looking "22-cap" note.)

Via phpMyAdmin (cross-check): the serialized `{prefix}user_roles` option grew and now contains
`hedayati_manage_teachers`; `hedayati_core_managed_capabilities` = `a:22:{…}`.

### 11. Smoke test (before any deeper acceptance)

Do these first; if any fails, treat it as a rollback trigger.

- **Public site unaffected:** homepage, `/courses/`, one category archive, one single course, a
  404 URL — all render; light/dark toggle and mobile nav work. (Phase 2B is admin-only; the public
  site must be identical to pre-deploy.)
- **No PHP errors:** check the cPanel error log and `wp-content/debug.log` (if `WP_DEBUG_LOG` on)
  for new `Fatal`/`Warning` lines with a `hedayati` path.
- **Admin loads:** Dashboard, Plugins, Users, Settings → Hedayati — no white screen, no notice.
- **New menu present:** «عملیات آموزشی» top-level menu visible to the administrator; its sub-views
  (runs list, «گزارش رویدادها») load with an empty state and no error.
- **Teacher CPT:** the «مدرسان» (teacher) admin menu appears for the administrator.
- **REST leak regression:** `GET https://mystik.ir/wp-json/wp/v2/hedayati_teacher` → 404 / no
  route (D34).
- **Phone auth still works:** the Phase 2A username + phone login path still authenticates a
  disposable `student` (quick re-check, not the full matrix).

### 12. Rollback criteria and rollback procedure

**Roll back if any of these occur:**

- `hedayati_core_db_version` does not reach `2.2.0`, or `hedayati_core_roles_version` does not
  reach `2.1.0`, after two admin-page loads + the 60s lock window.
- `hedayati_db_migration_lock` is stuck present > ~2 minutes.
- Fewer than the six new tables exist, or a `SHOW CREATE TABLE` diverges from the migration DDL
  (missing UNIQUE key, wrong engine, an `ip`/`user_agent` column on `audit_log`).
- `{prefix}hedayati_user_phones` schema or row count changed.
- Any Phase-2A capability disappeared from a role, or a custom role vanished.
- New PHP fatal on any admin or public page; public site regressed; admin unreachable.

**Rollback procedure (additive migrations → code-only rollback is safe):**

1. File Manager → delete the new `wp-content/plugins/hedayati-core/`; rename
   `hedayati-core.OLD-1.1.0` back to `hedayati-core`. (Theme: same, if it was re-deployed.)
2. Load wp-admin Dashboard as admin to let the (older) plugin re-attach.
3. **Leave the six new tables in place** — they are dormant and harmless with plugin `1.1.0`. Do
   **not** `DROP` any `hedayati_*` table and do **not** delete roles/capabilities as part of a
   routine rollback (`docs/DEPLOYMENT.md` → Rollback).
4. The stored `hedayati_core_db_version` may read `2.2.0` while the code is `1.1.0` — that is fine
   (version-gated `maybe_migrate()` simply does nothing). If step 8/10 showed a *partial* advance
   or a stuck lock, restore the **database** from the step-1 backup instead of hand-editing
   options, then restore the plugin folder.
5. If in doubt, or if data integrity is uncertain: full restore from the step-1 WordPress Toolkit
   backup (files + DB), then re-confirm the Phase 2A baseline (step 1 queries read `2.0.0`).
6. Record what happened; do not retry the deploy until the cause is understood off-staging.

### 13. Phase 2B acceptance execution order

Run `docs/PHASE_2B_ACCEPTANCE.md` in this order, on the same disposable-account discipline
(`qa_*` users, synthetic data, teardown at the end). Phase 2A behavioural acceptance is already
closed (2026-09-03), so this is the next gate.

1. **Section A — Migration 2.1.0** (B1–B6): version markers, six tables, column/index audit, phone
   table preserved, idempotent re-run *on a backup only* (B5 is Category-4-style — defer unless
   needed), lock absent.
2. **Section B — Roles schema 2.1.0** (R1–R5): 22 managed caps, `hedayati_manage_teachers` on
   manager/admin only, nothing lost, admin native caps intact, full per-role matrix.
3. **Section C — Teacher CPT** (T1–T5): visibility by capability, 1:1 user link enforcement,
   unlink on user delete, not front-end reachable, REST 404.
4. **Section I — Authorization negatives** (A1–A5): menu visibility, nonce/capability/scope
   enforcement on `admin-post.php`, attendance read-only for managers without
   `hedayati_record_attendance`. **Do this before creating much test data** — it is cheap and
   high-value.
5. **Section D — Course Runs** (D1–D6): create/validate/fallback/date-range/NULL-vs-0/Persian
   digits/cascade delete.
6. **Section E — Sessions** (S1–S3).
7. **Section F — Staff assignment** (F1–F7).
8. **Section G — Enrollments** (G1–G6).
9. **Section H — Attendance** (H1–H5) — depends on runs + sessions + enrollments existing.
10. **Section K — Shamsi/Jalali display** (K1–K5): can run alongside D/E once a run with dates
    exists.
11. **Section J — Audit log** (J1–J9): run last / continuously — assert one row per successful
    mutation, zero on failure, survives cascades, `actor_id` correct, no PII, viewer read-only and
    manager/admin-only, filters validated. J9 (idempotent re-run) is backup-only — defer.
12. **Teardown:** delete all `qa_*` users and test posts; confirm domain rows cascaded but audit
    rows remain; `RL-RESET`; confirm admin access.

### 14. Tests to perform IMMEDIATELY after deploy (health gate)

These block "the deploy is good": all of **step 11 (smoke test)**, **step 8** (DB version markers +
no lock), **step 9** (six tables exist, correct keys, phone table untouched), **step 10** (roles
`2.1.0`, 22 caps, nothing lost), plus Phase 2B **B1, B2, B4, B6, R1–R4, A1–A3, T5**. If all pass,
the deployment is healthy and the `.OLD` folder can be scheduled for removal.

### 15. Tests that can wait until basic health is confirmed

Deeper behavioural acceptance, run after the health gate in a later window:
Phase 2B **B3, B5, R5, T1–T4, D1–D6, S1–S3, F1–F7, G1–G6, H1–H5, K1–K5, J1–J9**, the full Phase 2A
phone-format matrix re-run (regression), and the theme-side fallback wiring work (a separate
development task, not part of this deploy).

---

## Explicitly out of scope for this deployment

- Merging to `main`, pushing, tagging — none of these.
- Any production (`drhedayati.com`) action.
- Category-4 / destructive tests as ordinary deploy steps: forced migration re-run/reset on the
  live DB, `DROP TABLE`, deliberate concurrent-migration lock tests, plugin deactivate/reactivate,
  `wp-config.php` edits, deleting real users. B5 / J9 (idempotent re-run) are done **only on a
  restored backup copy**, with approval, not on the deployed staging DB.
- New feature work.
