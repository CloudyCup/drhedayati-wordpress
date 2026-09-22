# Staging deploy checklist — integrated candidate `69ae3600`

**Scope:** the full rebuild to date — Phase 2A + 2B + 2C + 2D + 3 + the manager-experience work
(D44–D52: AI-Studio-style `/panel/`, in-panel courses/featured, consultations, progress,
certificates, materials, support tickets, notifications, in-panel settings).

**This document contains no secrets.** Real keys / paths / credentials live only in
`wp-config.php` and the cPanel control panel on `mystik.ir`.

> ⚠️ This checklist is a **plan**. Nothing here has been executed. `mystik.ir` and
> `drhedayati.com` have not been contacted. Do not run any step until the owner approves the
> staging cycle.

---

## 0. Exact package identity

| | |
|---|---|
| Branch | `feature/manager-experience` |
| Commit | **`69ae36006fbbfe2e6d0dbae733ec01a776d51893`** (short `69ae3600`) |
| Working tree at build | clean (`git status --porcelain` empty) — the ZIPs equal the commit |
| Plugin version | **Hedayati Core 1.9.0** |
| Theme version | **hedayati 1.3.0** |
| DB schema target | **`CURRENT_DB_VERSION` = `2.4.0`** (`hedayati_core_db_version`) |
| Roles version target | **`ROLES_VERSION` = `2.4.0`** (`hedayati_core_roles_version`) |
| Managed capabilities | **30** (`hedayati_core_managed_capabilities`) |

### Artifacts (built with `pwsh ./scripts/build-packages.ps1`, `tar -a`, D23)

| File | ZIP root entry | Installs to | Bytes | SHA-256 |
|---|---|---|---|---|
| `staging-export/hedayati-core.zip` | `hedayati-core/hedayati-core.php` | `wp-content/plugins/hedayati-core/` | 245 295 | `04447dfe0352f0b160e8cc1766ebcd9132153949f7efd72f577b1d8cacd782e8` |
| `staging-export/hedayati.zip` | `hedayati/style.css` | `wp-content/themes/hedayati/` | 173 488 | `8cab5cf1a8dfc75040aedf4ab154bf7d4221c9c724903efbbb1b1387e177b40a` |

Plugin ZIP = 65 files (incl. `tests/` — small, harmless to ship, per `docs/DEPLOYMENT.md`).
Theme ZIP = 40 files. Both ZIPs are gitignored; rebuild rather than reuse. Recompute the
checksums on the machine that will upload them and confirm they match this table before upload:

```bash
sha256sum staging-export/hedayati-core.zip staging-export/hedayati.zip
```

If the numbers differ, the tree was not clean at `69ae3600` — **stop**, re-check out the commit,
rebuild.

---

## 1. Pre-flight (do all before touching the server)

- [ ] `git -C <repo> rev-parse HEAD` == `69ae36006fbbfe2e6d0dbae733ec01a776d51893`; `git status`
      clean. If deploying from a different machine, `git fetch && git checkout 69ae3600`.
- [ ] Rebuild the packages from this exact commit: `pwsh ./scripts/build-packages.ps1` — it must
      print `Canonical Hedayati Core version: 1.9.0` and two `OK` lines. Confirm the SHA-256s
      against §0.
- [ ] Node static suite green: run each `plugin/hedayati-core/tests/verify-*.js` — **876 / 0**
      (`76 + 208 + 132 + 84 + 118 + 98 + 53 + 107`), every process exit 0.
- [ ] `Acceptance (Docker WordPress)` GitHub Actions **green on `69ae3600`** — run
      [`34025897670`](https://github.com/CloudyCup/drhedayati-wordpress/actions/runs/34025897670):
      **576 / 0, PASS, cleanup verified**. (Re-dispatch on the exact commit if any doubt.)
- [ ] Version headers confirmed: `HEDAYATI_CORE_VERSION` `1.9.0` **and** the plugin header
      `Version: 1.9.0`; theme `HEDAYATI_VERSION` `1.3.0` **and** `style.css` `Version: 1.3.0`;
      `CURRENT_DB_VERSION` `2.4.0`; `ROLES_VERSION` `2.4.0`.
- [ ] Confirm the three `wp-config.php` constants are provisioned on `mystik.ir` **before**
      upload (see §2). Without them: national-ID intake **and** course-material `file` uploads
      fail closed with a clear Persian error; `link` / `note` materials and every other feature
      still work.
- [ ] Note what staging currently runs (plugin version, `hedayati_core_db_version`,
      `hedayati_core_roles_version`) so the rollback target is known.
- [ ] Pick a low-traffic window. Staging only — production untouched.

---

## 2. Required `wp-config.php` constants (unchanged list; `HEDAYATI_PRIVATE_UPLOADS_DIR` now also serves materials)

Provision directly in `wp-config.php` before the `/* That's all, stop editing! */` line — never
in Git, a plugin file, a ticket, or a chat. Full guidance (cPanel home-path confirmation,
permissions, verification) is in `docs/DEPLOYMENT.md` §"Required wp-config.php constants".

| Constant | Format | Needed for | Missing → |
|---|---|---|---|
| `HEDAYATI_DATA_ENCRYPTION_KEY` | base64 → exactly 32 raw bytes | national-ID storage | intake fails closed, no plaintext fallback |
| `HEDAYATI_DATA_HMAC_KEY` | base64 → 32 raw bytes, **independent** of the above | national-ID storage | same |
| `HEDAYATI_PRIVATE_UPLOADS_DIR` | absolute path **outside** the web root, PHP-writable (`750`) | private documents **+ course-material `file` uploads** (D49 reuses this root in its own key namespace) | those uploads fail closed; `link`/`note` materials unaffected |

- [ ] `HEDAYATI_DATA_ENCRYPTION_KEY` present, valid.
- [ ] `HEDAYATI_DATA_HMAC_KEY` present, valid, different value.
- [ ] `HEDAYATI_PRIVATE_UPLOADS_DIR` present; directory exists, `750`, outside `public_html`,
      owned by the PHP-executing user. Never `777`.
- [ ] `WP_DEBUG_DISPLAY` false and PHP `display_errors` off on staging.

---

## 3. Backup (mandatory — the rollback depends on this)

- [ ] Full cPanel backup on `mystik.ir`: **files + database**.
- [ ] Download an **independent** copy off the server (not just a server-side snapshot).
- [ ] Separately export the current `wp-content/plugins/hedayati-core/` and
      `wp-content/themes/hedayati/` folders as their own ZIPs — this is the fastest rollback
      artifact.
- [ ] Record: backup filename(s), timestamp, current plugin/theme versions, current
      `hedayati_core_db_version` / `_roles_version` / `_managed_capabilities` count.

---

## 4. Upload

- [ ] cPanel File Manager (or SFTP). Replace **only** these two folders:
      - `wp-content/plugins/hedayati-core/`  ← from `hedayati-core.zip`
      - `wp-content/themes/hedayati/`  ← from `hedayati.zip`
- [ ] Extract so the paths are `.../plugins/hedayati-core/hedayati-core.php` and
      `.../themes/hedayati/style.css` — **no** nested wrapper folder.
- [ ] Do **not** touch `wp-config.php`, `wp-content/uploads/`, the DB, or any other plugin/theme.
- [ ] The plugin stays **activated** through an in-place folder replace (WordPress does not
      deactivate it). If it somehow deactivated, reactivate it (that fires the activation hook,
      which is fine — it also provisions pages + runs migrations).

---

## 5. Trigger migrations + role sync (the `admin_init` model — a folder replace does NOT fire the activation hook)

- [ ] Log in to `mystik.ir/wp-admin` as an **administrator**.
- [ ] Load the Dashboard, then the Plugins page — this runs
      `Hedayati_DB_Schema::maybe_migrate()` (→ `2.4.0`) and `Hedayati_Roles::maybe_sync_roles()`
      (→ `2.4.0`).
- [ ] If LiteSpeed or a page cache may have served a cached wp-admin shell, hard-reload / purge
      and reload so `admin_init` actually runs.

---

## 6. Post-deploy verification (as admin — all read-only / harmless)

### 6.1 Schema

- [ ] `hedayati_core_db_version` option == `2.4.0`.
- [ ] These tables exist (dynamic prefix — check with the real `{prefix}`):
      `{p}hedayati_user_phones`, `_course_runs`, `_run_staff`, `_sessions`, `_enrollments`,
      `_attendance`, `_audit_log`, `_student_verification`, `_documents`,
      **`_consultations`, `_certificates`, `_session_materials`, `_support_tickets`,
      `_support_messages`, `_notifications`**.
- [ ] `hedayati_db_migration_lock` option **absent** (no stuck lock).

### 6.2 Roles / capabilities

- [ ] `hedayati_core_roles_version` == `2.4.0`.
- [ ] `hedayati_core_managed_capabilities` has **30** entries, including the six new ones:
      `hedayati_manage_consultations`, `hedayati_manage_certificates`,
      `hedayati_manage_session_materials`, `hedayati_manage_support_tickets`,
      `hedayati_use_support_tickets`, `hedayati_view_own_certificates`.
- [ ] Roles present: `student`, `teacher`, `teacher_assistant`, `reception`, `hedayati_manager`
      (+ native `administrator` augmented).
- [ ] Spot-check grants:
      - `hedayati_manager` + `administrator` → all four `hedayati_manage_{consultations,
        certificates,session_materials,support_tickets}`.
      - `reception` → `hedayati_manage_consultations` + `hedayati_manage_support_tickets`,
        **not** `hedayati_manage_certificates`.
      - `teacher` → `hedayati_manage_session_materials`, **not** certificates/consultations.
      - `student` → `hedayati_view_own_certificates` + `hedayati_use_support_tickets`, **no**
        `hedayati_manage_*`.

### 6.3 Pages

- [ ] The plugin (re)created / has these published Pages with these slugs:
      `account`, `panel`, `about`, `contact`, `consult`, `teachers`, **`verify`** (new).
- [ ] If a page cache hid the `admin_init` safety net, create any missing Page manually with the
      exact slug and assign no special template (the theme resolves `page-{slug}.php`).
- [ ] Settings → Permalinks → **Save** (flush rewrite rules) so `/verify/`, `/consult/`,
      `/panel/`, `/account/` resolve.

### 6.4 Crypto / private storage

- [ ] `wp eval 'var_export( Hedayati_Crypto::is_configured() );'` → `true`.
- [ ] `wp eval 'var_export( Hedayati_Document_Storage::resolve_root() );'` → prints the exact
      configured absolute path as a **string** (not a `WP_Error` array).

### 6.5 Cache

- [ ] Purge LiteSpeed cache.
- [ ] Confirm LiteSpeed never serves an authenticated `/account/*` or `/panel/*` response
      (log in as a student, load `/account/`, confirm it is not a cached logged-out page; the
      plugin sends no-cache headers + a `litespeed_control_set_nocache` exclusion, but verify).

---

## 7. Smoke test (staging, ~15 min — a subset of `docs/AI_STUDIO_VISUAL_REVIEW_CHECKLIST.md`)

**Public / regression**
- [ ] Homepage, `/courses/`, a category archive, a single course, a 404, a generic Page — no
      regression; light/dark toggle; mobile nav.
- [ ] `/consult/` (logged out): form renders; bad phone → Persian error; valid submit →
      "درخواست شما ثبت شد"; 6 rapid submits → rate-limit message.
- [ ] `/verify/` (logged out): empty → hint; unknown code → "یافت نشد".

**Manager** (`hedayati_manager` or admin)
- [ ] `/panel/` dashboard: real KPIs (incl. new-consultation + waiting-ticket counts), module
      cards, sidebar with every module entry.
- [ ] `?view=courses` / `?view=featured`: toggle feature + publish; 9th featured → "حداکثر ۸".
- [ ] `?view=consultations`: the test submission from above appears; move new→contacted→closed;
      save a note.
- [ ] `?view=certificates`: issue by an enrollment id → code shown; re-issue same enrollment →
      "قبلاً … صادر شده"; open `/verify/` for that code → **valid**, shows only
      name/course/date/institute/code; revoke → `/verify/` shows **revoked**.
- [ ] `?view=support`: a student ticket (below) shows; open, reply, change status.
- [ ] `?view=settings`: change institute name / a phone / Tehran address → save → reload shows
      new values → footer / `/contact/` reflect them.
- [ ] `?view=run&run_id=<id>` → "منابع و جزوات": add a **link**, a **note**, and a **file**
      (PDF); file "دانلود" downloads through the gated handler (URL is
      `admin-post.php?action=hedayati_material_download…`, **not** a bare `/wp-content/uploads/`
      path); delete works. Run-progress line shows "X از Y جلسه".

**Reception**
- [ ] `/panel/` simple home; sidebar shows only students + consultations + support.
- [ ] Direct URL `?view=courses` / `?view=certificates` / `?view=settings` → **403**.
- [ ] Create-student / enroll / verification / documents still work.

**Teacher**
- [ ] Assigned run: attendance grid + materials add form present.
- [ ] Unassigned run `?view=run&run_id=` → 403. `?view=students` → 403.
- [ ] Direct URL `?view=certificates` / `?view=consultations` / `?view=settings` → 403.

**TA**
- [ ] Assigned run: roster names only, **no** attendance grid, **no** materials add form.

**Student** (`/account/`)
- [ ] Forced first-login password change still intercepts before any view.
- [ ] `?view=enrollments`: run-progress + attendance bars ("—" when no basis, never 0%);
      materials listed; sessions listed.
- [ ] `?view=schedule`: only this student's future sessions.
- [ ] `?view=certificates`: own certificates only.
- [ ] `?view=support`: open a ticket (subject/category/body); reply while open; a closed ticket
      shows no reply box.
- [ ] `?view=notifications`: real rows from the events above; "علامت‌گذاری همه…" clears the
      count; sidebar badge disappears.
- [ ] Try `/account/?view=support&ticket=<another student's ticket id>` → "تیکت یافت نشد".
      Try `/panel/?view=support` as the student → denied entirely.
- [ ] `?view=verification` / `?view=documents` / `?view=profile` unchanged; national ID never
      shown.

**Security spot-checks**
- [ ] Privileged national-ID reveal as a non-`hedayati_verify_students` QA account → 403, never
      a value.
- [ ] POST `admin-post.php?action=hedayati_staff_cert_issue` without a valid nonce, or as
      reception → 403.

---

## 8. Reconcile

- [ ] Deployed code == commit `69ae3600`. Any emergency server-side edit is mirrored back into
      Git **immediately** and this checklist annotated.
- [ ] Consider tagging: `git tag staging/69ae3600 69ae3600 && git push origin staging/69ae3600`.
- [ ] Update `docs/agent/STATUS.md` + `TEST_RESULTS.md` with the staging result (PASS/FAIL,
      date, what was executed).

---

## 9. Rollback

**Trigger:** any release-blocking failure in §6–§7 that cannot be fixed forward in minutes
(fatal error, migration stuck, a capability check that denies a legitimate role or allows an
illegitimate one, PII visible on `/verify/`, LiteSpeed serving an authenticated page).

### 9.1 Code rollback (fast path)

1. [ ] Re-upload the **pre-deploy** `hedayati-core/` and `hedayati/` folder ZIPs from §3 (or
   restore those two folders from the full backup). Replace the folders in place; the plugin
   stays activated.
2. [ ] Load `wp-admin` as admin so `admin_init` runs on the older code (it will not downgrade
   anything — see 9.2).
3. [ ] Settings → Permalinks → Save (flush rewrites). Purge LiteSpeed.
4. [ ] Smoke test: homepage, `/courses/`, single course, `/account/` login, `/panel/` for a
   staff user — confirm the pre-deploy behaviour is back.

### 9.2 What a rollback does and does NOT do

- The `2.0.0` → `2.4.0` migrations are **purely additive** — new tables, roles, capabilities.
  They never transform or delete existing data.
- **Do NOT** drop any `hedayati_*` table or remove roles/capabilities as part of a routine
  rollback. The six new tables (`_consultations`, `_certificates`, `_session_materials`,
  `_support_tickets`, `_support_messages`, `_notifications`) simply sit **dormant** under the
  older plugin — harmless. `hedayati_core_db_version` / `_roles_version` stay at `2.4.0`;
  `Hedayati_Roles::maybe_sync_roles()` on the old code is version-gated and will **not**
  re-run or strip the new caps (an older plugin doesn't know they're "obsolete" unless its
  own `get_all_hedayati_capabilities()` omits them — which would only prune them from roles,
  not touch data; still, leave them).
- **Do NOT** hand-edit `hedayati_core_db_version` on the server. If a migration half-applied,
  fix the migration in code, redeploy `hedayati-core.zip`, and re-trigger via `admin_init`.
- Any data written by the new modules before rollback (a consultation row, an issued
  certificate, a support ticket, uploaded material bytes under `HEDAYATI_PRIVATE_UPLOADS_DIR`)
  remains intact and becomes visible again when `1.9.0` is redeployed. Encrypted national-ID /
  document data is unaffected by any rollback as long as the same crypto keys stay in
  `wp-config.php`.
- Course-material **files** live under `HEDAYATI_PRIVATE_UPLOADS_DIR/<run id>/…`. A rollback
  leaves them on disk; they are only reachable through the (now-absent) gated handler, so they
  are inert until `1.9.0` returns. If you must remove them, delete that directory's contents
  manually — never `wp-content/uploads/`.

### 9.3 Full restore (if the fast path is insufficient)

1. [ ] cPanel → restore the full **files + database** backup from §3.
2. [ ] Confirm `hedayati_core_db_version` / `_roles_version` are back to the pre-deploy values
   recorded in §3.
3. [ ] Purge LiteSpeed; flush permalinks; smoke test.

### 9.4 After any rollback

- [ ] Record in `docs/agent/DEFECTS.md`: what failed, the exact assertion/screen, the commit,
  and whether it is a code bug (fix forward) or an environment issue (`wp-config.php`,
  permissions, cache).
- [ ] Do not re-attempt the deploy until the cause is fixed and re-verified by
      `Acceptance (Docker WordPress)` on the new commit.

---

## 10. Explicitly NOT part of this deploy

- No `drhedayati.com` / production change of any kind. No DNS.
- No new `wp-config.php` constants beyond the three in §2 (all pre-existing).
- No database edits by hand.
- No merge to `main` — that is a separate step, gated on this staging cycle passing plus the
  full `docs/AI_STUDIO_VISUAL_REVIEW_CHECKLIST.md`.
- Ticket attachments and certificate-PDF export are **v2** — not in `1.9.0`.
