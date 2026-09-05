# Phase 2C — Staging Deployment Checklist (mystik.ir)

Upgrades `mystik.ir` from plugin `1.5.3` / DB `2.2.0` / roles `2.1.0` to plugin `1.6.0` / DB
`2.3.0` / roles `2.2.0`. **This document is a checklist to follow manually — nothing here runs
automatically.** No step in this file has been executed as part of preparing it.

Constraints (unchanged from Phase 2A/2B, `AGENTS.md` rule 8): no destructive migration testing, no
forced migration reset, no table drops, no real student PII — use only disposable QA accounts and
synthetic (checksum-valid, fabricated) national IDs and documents. `drhedayati.com` is never
touched by any step below.

---

## 1. Fresh backup

- [ ] Full cPanel backup (files **and** database) taken and downloaded to a location outside the
      hosting account.
- [ ] Note the exact pre-deploy state: plugin `1.5.3`, DB `2.2.0`, roles `2.1.0`.

## 2. Current baseline checks (before touching anything)

- [ ] Homepage and `/wp-admin/` load normally.
- [ ] `hedayati_core_db_version` = `2.2.0`, `hedayati_core_roles_version` = `2.1.0`,
      `hedayati_core_managed_capabilities` has 22 entries.
- [ ] `{prefix}hedayati_user_phones`, `_course_runs`, `_run_staff`, `_sessions`, `_enrollments`,
      `_attendance`, `_audit_log` all exist. Record row counts for each (used in step 11).

## 3. Configure the three required `wp-config.php` constants

- [ ] Generate `HEDAYATI_DATA_ENCRYPTION_KEY` and `HEDAYATI_DATA_HMAC_KEY` as two **separate**
      `openssl rand -base64 32` invocations (see `docs/DEPLOYMENT.md`'s constants section for
      exact generation/placement guidance) — paste directly into `wp-config.php`, never into a
      ticket, chat, or log.
- [ ] Confirm the real cPanel home path (`docs/DEPLOYMENT.md` "Confirming the cPanel home path")
      before setting `HEDAYATI_PRIVATE_UPLOADS_DIR` — do not guess it.
- [ ] All three constants added to `wp-config.php` before `/* That's all, stop editing! */`.

## 4. Create/verify the private storage directory

- [ ] Directory created at the confirmed `HEDAYATI_PRIVATE_UPLOADS_DIR` path, outside `public_html`.
- [ ] Owned by the same account PHP runs as; permissions conservative (`750` on the directory,
      never `777`) — see `docs/DEPLOYMENT.md`'s constants section for the reasoning.
- [ ] Confirm it is writable by PHP (the read-only verification steps below don't require writing
      a real file — `Hedayati_Crypto::is_configured()` and `Hedayati_Document_Storage::resolve_root()`
      are the checks; the latter's `mkdir` side effect is itself the write-access proof).

## 5. Upload plugin `1.6.0` (existing rollback-folder method)

- [ ] Follow the same "replace `wp-content/plugins/hedayati-core/` folder, keep a copy of the old
      one for rollback" method already used for prior releases (`docs/DEPLOYMENT.md` step 3).
- [ ] Confirm the uploaded plugin header `Version:` and `HEDAYATI_CORE_VERSION` both read `1.6.0`
      (they were verified to match in the built package — re-check after upload in case of a
      partial/corrupted transfer).

## 6. Trigger migration safely

- [ ] Log in to `wp-admin` and load the Dashboard or Plugins page — `Hedayati_DB_Schema::maybe_migrate()`
      / `Hedayati_Roles::maybe_sync_roles()` run on `admin_init`. This is the existing, safe,
      idempotent trigger — do **not** force a migration re-run or reset any version marker.

## 7–10. Post-migration verification

- [ ] **DB version:** `hedayati_core_db_version` = `2.3.0`.
- [ ] **Roles version:** `hedayati_core_roles_version` = `2.2.0`.
- [ ] **Capability count:** `hedayati_core_managed_capabilities` has **23** entries, including
      `hedayati_manage_teachers` and `hedayati_upload_student_documents`; `reception` +
      `hedayati_manager` + `administrator` hold the new capability, `student`/`teacher`/
      `teacher_assistant` do not.
- [ ] **New tables exist:** `{prefix}hedayati_student_verification` and `{prefix}hedayati_documents`,
      both empty (0 rows) immediately after migration.

## 11. Confirm old Phase 2A/2B tables remain intact

- [ ] `{prefix}hedayati_user_phones`, `_course_runs`, `_run_staff`, `_sessions`, `_enrollments`,
      `_attendance`, `_audit_log` all still exist with the **same row counts** recorded in step 2
      (this migration is additive-only and must not touch them).

## 12. New admin screen appears

- [ ] «دانشجویان و احراز هویت» appears in the wp-admin menu for `hedayati_manager`/`administrator`.
- [ ] It does **not** appear (or its actions 403) for `reception` beyond what `reception` is
      supposed to reach (student search, national-ID/document intake) — `reception` should not see
      the decrypted-value reveal control or the download/archive/purge controls.
- [ ] It does not appear at all for `teacher`, `teacher_assistant`, or `student`.

## 13. Concise Phase 2C smoke tests

Run the pre-flight checks (P1–P4) and at minimum sections **B** (national ID), **D** (privileged
reveal, especially D2–D4 negative cases), and **E1/E4** (staff upload + spoofed-file rejection)
from `docs/PHASE_2C_ACCEPTANCE.md`, using disposable QA accounts and fabricated national IDs only.
Run the full matrix if time allows.

## 14. Rollback criteria

Roll back if **any** of the following is true:

- Any of steps 7–11 fails (wrong version, wrong capability count/assignment, a missing new table,
  or — critically — a changed row count in any Phase 2A/2B table).
- `Hedayati_Crypto::is_configured()` is `false` after the constants are set (misconfigured keys) —
  do not proceed with any national-ID test data while this is true.
- The homepage, course archive, single-course page, or existing wp-admin screens (Teacher CPT,
  «عملیات آموزشی») show a regression.
- The new admin screen is reachable by a role that should not reach it (step 12's negative checks).
- Any real student data was inadvertently used instead of synthetic QA data.

## 15. Rollback procedure

1. Restore the previous `wp-content/plugins/hedayati-core/` folder from the pre-deploy copy kept
   in step 5 (or redeploy the `1.5.3` artifact).
2. Do **not** drop `hedayati_student_verification` or `hedayati_documents` — the `2.0.0`–`2.3.0`
   migrations are additive-only; leaving the newer tables in place but dormant is harmless, and
   dropping them destroys data if any real writes occurred during the smoke test.
3. Do **not** manually edit `hedayati_core_db_version` / `hedayati_core_roles_version` /
   `hedayati_core_managed_capabilities` to "fix" a partial state — redeploying `1.5.3` simply
   leaves those newer values in `wp_options` unused; the `1.5.3` code paths don't reference them.
4. Re-run steps 2's baseline checks to confirm the rollback restored the exact pre-deploy state.
5. If any synthetic test data (QA users, disposable documents, test national IDs) was created
   during the smoke test, delete it via the normal `wp_delete_user()` path (fires the Phase 2C
   cleanup hooks) rather than raw SQL, then confirm the private storage directory has no leftover
   files.
6. Document what failed and why before attempting a second deploy attempt.
