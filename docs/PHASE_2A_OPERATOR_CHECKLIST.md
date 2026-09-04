# Phase 2A — Staging Behavioural Acceptance — Operator Checklist (`mystik.ir`)

**Purpose:** close the remaining **non-destructive** Phase 2A behavioural acceptance
(Categories 2–3 of `docs/PHASE_2A_ACCEPTANCE.md`) on the **current** staging install
**before** the Phase 2B branch is deployed.

**Do NOT run any Category 4 test here.** See the exclusion list at the bottom.
**Do NOT deploy the Phase 2B branch** as part of this.

> **2026-09-03 — DONE.** All rounds (A–D) were executed and PASSED on disposable users `qa_phase2a`
> (ID 2) / `qa_phase2a_b` (ID 3); results reconciled into `docs/PHASE_2A_ACCEPTANCE.md`
> ("Behavioural execution log (2026-09-03)") and `docs/CURRENT_STATE.md`. Only T2.4 (unknown
> non-phone username wording) was not exercised — non-gating. This checklist is retained as the
> execution record.

Staging today runs plugin **1.1.0**, DB & roles schema **2.0.0** — the Phase 2A build. That is
the artefact under test; do not upgrade it during this checklist.

Everything below is either read-only, a disposable test user, or a staging-only transient/test-row
write that is cleaned up in Round D. Substitute the real table prefix for `P_` in every SQL
statement (get it from any existing table name in phpMyAdmin).

---

## Safety setup — do this once, before Round A

1. **STEP 0 — Full backup (mandatory).** Take a full cPanel backup (files **+** database) and
   download an independent copy off-server. Note the timestamp. Do not proceed until the download
   is complete.
2. **Escape hatch #1 — keep a logged-in admin session open.** In **Browser 1**, log into
   `wp-admin` as your administrator now and leave that tab open for the whole session. The rate
   limiter only blocks *new* logins; an existing session cookie keeps working even if login is
   temporarily locked.
3. **Escape hatch #2 — phpMyAdmin in a separate tab.** Open phpMyAdmin (its login is independent
   of WordPress). This is how you clear rate-limit state if anything locks up.
4. **Use Browser 2 (private/incognito) for all the login tests below** so Browser 1's admin
   session is never disturbed.
5. **Network:** if practical, run the login tests from a connection whose IP you can change
   (e.g. be ready to switch to a phone hotspot). The per-IP limit is 30 fails / 15 min; this
   checklist stays well under that **only if you run the "RL-RESET" step where indicated.**
6. Confirm staging has **no real users and no live traffic** during the window (N8).
7. **RL-RESET (you will use this repeatedly):** in phpMyAdmin run
   ```sql
   DELETE FROM P_options
   WHERE option_name LIKE '\_transient\_hd\_rl\_%'
      OR option_name LIKE '\_transient\_timeout\_hd\_rl\_%';
   ```
   - **What changes:** deletes the rate-limiter's transient counter rows (staging only, not
     persistent data).
   - **Expected result:** `Rows affected` = however many counters existed; a follow-up
     `SELECT COUNT(*) FROM P_options WHERE option_name LIKE '\_transient\_hd\_rl\_%';` returns `0`.
   - **Cleanup:** none — this *is* cleanup.

---

## Round A — setup + read-only close-outs  (no failed logins in this round)

### A1 — Determine the phone-test path
Run in a shell on the server (cPanel Terminal / SSH): `wp --info`
- **If WP-CLI works:** you can run the whole checklist now. Use the `wp eval` / `wp eval-file`
  commands as written in Round C.
- **If WP-CLI is NOT available:** stop after Round B and tell me. Rounds C–D need either WP-CLI or
  a temporary admin-only must-use-plugin harness, which I will supply as a separate short file
  (it only calls the existing `Hedayati_User_Phone_Service` methods and is deleted in Round D).
  Rounds A–B are still fully valid on their own.

### A2 — Baseline the rate-limit state
Run **RL-RESET** (above). Confirm the count is `0`. This gives every later assertion a clean
starting point, including your tester IP bucket.

### A3 — Create disposable student user #1  *(state-changing)*
`wp-admin → Users → Add New`: username `qa_phase2a`, email a mailbox you control, a known strong
password, **Role = دانشجو (student)**. Record the **user ID** (hover the username / see the edit
URL).
- **What changes:** one new WP user with role `student`.
- **Expected result:** user created, listed as دانشجو, no PHP error.
- **Cleanup:** Round D (D1).

### A4 — Create disposable student user #2  *(state-changing)*
Same as A3: username `qa_phase2a_b`, its own email, role **student**. Record the **user ID**.
(Created now so uniqueness testing in Round C doesn't need another round.)
- **What changes:** one more `student` user.
- **Expected result:** created, listed as دانشجو.
- **Cleanup:** Round D (D2).

### A5 — Role / capability least-privilege check  (closes T3.5 / T3.6)  *(reversible role edits)*
**Path 1 (fast, if WP-CLI):**
```
wp role list
wp cap list administrator | grep -c '^hedayati_'          # expect 21
for r in student teacher_assistant teacher reception hedayati_manager; do \
  echo "== $r =="; wp cap list $r; done
```
Check against **Appendix A** of `docs/PHASE_2A_ACCEPTANCE.md`. Key negatives:
`reception` and `hedayati_manager` must **not** have `manage_options`, `activate_plugins`,
`edit_users`, `delete_users`, `edit_theme_options`; `teacher_assistant` must **not** have
`hedayati_record_attendance` or `hedayati_manage_assigned_sessions`; every custom role has `read`.

**Path 2 (no WP-CLI) — wp-admin negative checks via `qa_phase2a`:**
For each role in {`teacher_assistant`, `teacher`, `reception`, `hedayati_manager`}: set
`qa_phase2a`'s role to it (Users → edit → Role → Update), then in **Browser 2** log in as
`qa_phase2a` and:
- confirm the admin menu shows **no** Settings / Plugins / Users / Appearance / Tools;
- directly visit `wp-admin/options-general.php`, `wp-admin/plugins.php`, `wp-admin/users.php` →
  each must say *"Sorry, you are not allowed to access this page."*
- **What changes:** `qa_phase2a`'s role, temporarily.
- **Expected result:** every custom role is denied all four admin areas; only `administrator`
  (Browser 1) reaches them.
- **Cleanup:** set `qa_phase2a`'s role **back to `student`** before Round B. Log `qa_phase2a` out
  of Browser 2.

> After A5, run **RL-RESET** again (the role-switch logins may have created buckets).

---

## Round B — authentication + rate-limit behaviour  (generates failed logins — RL-RESET at the end)

All steps in **Browser 2 (incognito)**, at `wp-login.php`, with `qa_phase2a` back on role
`student` and a known password.

### B1 — Username + password success (T2.2)
Log in as `qa_phase2a` + correct password.
- **Expected:** login succeeds; lands on a minimal dashboard (student has `read` only). Log out.

### B2 — Wrong password once (T2.3)
`qa_phase2a` + wrong password, **once**.
- **Expected:** rejected with WordPress core wording for an incorrect password (the **username**
  path is *not* genericised — this is by design). Failure count for this identifier = 1.

### B3 — Unknown non-phone username (T2.4)
`no_such_user_xyz` + any password, **once**.
- **Expected:** WordPress native *"Unknown username"* (`invalid_username`) — **not** genericised.
  This is the documented as-built behaviour (generic errors are the **phone** path only).

### B4 — Identifier lockout at the 5th failure (T2.5)  ⚠️ SEE "TESTS THAT CAN LOCK YOU OUT"
From **one** browser, submit **wrong-password** logins for `qa_phase2a` **5 times**, then a 6th
attempt **with the correct password**.
- **What changes:** `hd_rl_id_*` bucket for `qa_phase2a` reaches the threshold; ~5–6 added to the
  shared `hd_rl_ip_*` bucket (limit 30).
- **Expected:** attempts 1–4 = normal rejection; attempt ≥ 5 = Persian rate-limit error
  (*"تعداد تلاش‌های ناموفق بیش از حد مجاز است…"*, code `too_many_retries`); the correct-password
  6th attempt is **still blocked** (the priority-90 filter overrides a valid credential while the
  bucket is hot).
- **Cleanup:** run **RL-RESET**, then confirm recovery by logging `qa_phase2a` in correctly (B1),
  then log out.

### B5 — Successful login clears the identifier bucket (T2.6 + T3.8)
From zero (RL-RESET done): 3 wrong-password failures for `qa_phase2a`. In phpMyAdmin:
```sql
SELECT option_name, option_value FROM P_options
WHERE option_name LIKE '\_transient\_hd\_rl\_%' AND option_name NOT LIKE '%timeout%';
```
- **Expected now:** one `hd_rl_id_*` row = `3`, one `hd_rl_ip_*` row = `3`.
Then log in **correctly** as `qa_phase2a`. Re-run the SELECT.
- **Expected after success:** the `hd_rl_id_*` row for this identifier is **gone**; the
  `hd_rl_ip_*` row **remains at 3** (success clears the identifier bucket, never the shared IP
  bucket). Then 3 more wrong-password failures **do not** trigger lockout (counter restarted).
- **Cleanup:** **RL-RESET**.

### B6 — No double-count per attempt (T3.7, username half)
RL-RESET done. **One** wrong-password login for `qa_phase2a`. Run the B5 SELECT.
- **Expected:** the canonical-username `hd_rl_id_*` bucket = **1** (not 2); `hd_rl_ip_*` = **1**
  (not 2). No bucket ever jumps by 2 on a single attempt. (The phone-identifier half of this test
  is C-round, step C3-note.)
- **Cleanup:** **RL-RESET**.

> **If WP-CLI was not available (A1), STOP HERE** and report Rounds A–B. Otherwise continue.

---

## Round C — phone provisioning, formats, uniqueness, lifecycle  (WP-CLI or harness required)

Use a synthetic test mobile number that is **not a real person's**. This checklist uses
`09123456789` (canonical `+989123456789`) as the primary and `+989000000000` as a
valid-format-but-unassigned number. All `wp eval` commands run as a shell user (so
`actor` context is CLI, not a wp-admin user — expected).

### C1 — Provision and inspect a phone for `qa_phase2a` (T3.10)  *(state-changing: 1 test row)*
```
wp eval 'var_export( Hedayati_User_Phone_Service::assign_phone( <ID_A>, "09123456789" ) );'
wp eval 'var_export( Hedayati_User_Phone_Service::get_phone_record_by_user( <ID_A> ) );'
```
- **What changes:** one row in `P_hedayati_user_phones` for user A.
- **Expected:** `assign_phone` → `true`; record shows `phone_e164 = +989123456789`,
  `is_verified = false` / `0`, `verified_at = NULL`, `created_at` and `updated_at` set (UTC).
- **Cleanup:** removed automatically when user A is deleted in Round D; verified in D1.

### C2 — Accepted Iranian-phone format matrix — all must SUCCEED (T3.11)
With the phone from C1 assigned and the correct password, in **Browser 2** log in once with each
identifier below (log out between). Run **RL-RESET** if you approach 5 failures (you shouldn't —
these should all succeed):

| # | Identifier entered | Expect |
|---|---|---|
| 1 | `09123456789` | logs in as `qa_phase2a` |
| 2 | `9123456789` | logs in |
| 3 | `+989123456789` | logs in |
| 4 | `00989123456789` | logs in |
| 5 | `989123456789` | logs in |
| 6 | `۰۹۱۲۳۴۵۶۷۸۹` (Persian digits) | logs in |
| 7 | `٠٩١٢٣٤٥٦٧٨٩` (Arabic-Indic digits) | logs in |
| 8 | `0912 345 6789` | logs in |
| 9 | `0912-345-6789` | logs in |
| 10 | `(0912) 345.6789` | logs in |

- **Expected:** every row authenticates as `qa_phase2a`.
- **Cleanup:** log out; **RL-RESET**.

### C3 — Invalid / malformed phone + generic-error behaviour (T3.12)  ⚠️ generates IP failures
Each attempt below must **fail** with the **identical** Persian generic message
(*"نام کاربری/شماره موبایل یا رمز عبور اشتباه است"*, code `invalid_credentials`) — no distinction
between "no such number" and "wrong password", and malformed inputs must never partially match.
Keep the running total of failed attempts in mind (RL-RESET at the halfway point):

| # | Identifier + password | Expect |
|---|---|---|
| 1 | correct phone `09123456789` + **wrong** password | generic `invalid_credentials` |
| 2 | `+989000000000` (valid format, unassigned) + any password | **same** generic error |
| 3 | `0912abc4567` + any | generic error, no partial match |
| 4 | `0912<script>` + any | generic error (input rejected, not stripped) |
| 5 | `0912_3456789` + any | generic error |
| 6 | `++989123456789` + any | generic error |
| 7 | `0912+3456789` + any | generic error |
| 8 | `02112345678` (Tehran landline) + any | generic error |
| 9 | `0912345` (too short) + any | generic error |
| 10 | `+14155552671` (non-Iranian) + any | generic error |

- **What changes:** `hd_rl_ip_*` bucket climbs (~10) and the `hd_rl_id_*` bucket for
  `+989123456789` reaches the threshold after row 1 repeats — do **not** repeat row 1 more than
  4×.
- **Expected:** all ten fail identically; rows 3–7 never behave as a partial/loose match.
- **Cleanup:** **RL-RESET** after row 5 and again after row 10.
- **Note (T3.7 phone half):** immediately after RL-RESET, do exactly **one** wrong-password login
  with `09123456789`, then check buckets — canonical-phone `hd_rl_id_*` = 1, `hd_rl_ip_*` = 1.
  Then **RL-RESET**.

### C4 — Phone uniqueness, service level + DB level (T3.13)  *(state-changing: expected to write nothing)*
```
wp eval 'var_export( Hedayati_User_Phone_Service::assign_phone( <ID_B>, "+98 912 345 6789" ) );'
```
- **Expected:** `WP_Error` with code `phone_already_exists` (a different input format of user A's
  number). User B gets **no** row.

Then attempt a raw duplicate insert in phpMyAdmin (it is **expected to be rejected**):
```sql
INSERT INTO P_hedayati_user_phones (user_id, phone_e164, is_verified, created_at, updated_at)
VALUES (<ID_B>, '+989123456789', 0, UTC_TIMESTAMP(), UTC_TIMESTAMP());
```
- **What changes:** nothing — the DB refuses it.
- **Expected:** MySQL **duplicate key** error on `uq_phone_e164`. Do **not** add
  `ON DUPLICATE KEY UPDATE`. Confirm with
  `SELECT COUNT(*) FROM P_hedayati_user_phones WHERE user_id = <ID_B>;` → `0`.
- **Cleanup:** none needed (nothing written); user B removed in Round D.

### C5 — Equivalent formats normalize identically (T3.14)  (pure functions — no writes)
```
for v in 09123456789 9123456789 +989123456789 00989123456789 989123456789 ; do \
  wp eval "echo Hedayati_Phone::normalize('$v'), \"\n\";"; done
wp eval 'echo Hedayati_Phone::normalize("۰۹۱۲۳۴۵۶۷۸۹"), "\n";'
wp eval 'echo Hedayati_Phone::normalize("٠٩١٢٣٤٥٦٧٨٩"), "\n";'
wp eval 'echo Hedayati_Phone::normalize("0912 345 6789"), "\n";'
wp eval 'var_export( (bool) Hedayati_User_Phone_Service::find_user_by_phone("0912-345-6789") );'
```
- **Expected:** every `normalize()` prints exactly `+989123456789`; `find_user_by_phone()` for any
  equivalent form returns user A; an invalid vector returns a `WP_Error`.
- **Cleanup:** none.

### C6 — Phone lifecycle & verification-flag transitions (T3.15 steps 1–4)  *(state-changing: 1 row, mutated)*
On user A's existing row:
```
wp eval 'var_export( Hedayati_User_Phone_Service::verify_phone( <ID_A> ) );'
wp eval 'var_export( Hedayati_User_Phone_Service::get_phone_record_by_user( <ID_A> ) );'
wp eval 'var_export( Hedayati_User_Phone_Service::update_phone( <ID_A>, "09120000000" ) );'
wp eval 'var_export( Hedayati_User_Phone_Service::get_phone_record_by_user( <ID_A> ) );'
wp eval 'var_export( Hedayati_User_Phone_Service::update_phone( <ID_A>, "+989120000000" ) );'
wp eval 'var_export( Hedayati_User_Phone_Service::get_phone_record_by_user( <ID_A> ) );'
```
- **What changes:** user A's single phone row: verified flag set, then number changed, then
  re-set to the same normalized number.
- **Expected:**
  1. `verify_phone` → `true`; record now `is_verified = true`, `verified_at` set, `updated_at`
     bumped.
  2. `update_phone` to `09120000000` (**different** number) → `is_verified` back to `false`,
     `verified_at` `NULL`, `phone_e164 = +989120000000`, `updated_at` bumped.
  3. `update_phone` to `+989120000000` (**same** number, different format) → `true`, **row
     unchanged**: verification state preserved, `updated_at` **not** bumped (no-op).
- **Cleanup:** row is deleted with user A in Round D (D1 confirms `COUNT = 0`).

---

## Round D — teardown  (this round is the cleanup)

### D1 — Delete `qa_phase2a` and confirm phone-row cleanup (T2.8 + T3.15 step 5)
`wp-admin → Users → All Users → qa_phase2a → Delete → "Delete all content" → Confirm`.
Then in phpMyAdmin: `SELECT COUNT(*) FROM P_hedayati_user_phones WHERE user_id = <ID_A>;`
- **What changes:** user A removed; the `deleted_user` hook fires `delete_phone()`.
- **Expected:** user gone, no PHP error; `COUNT(*)` = **0** (the phone row was auto-removed).

### D2 — Delete `qa_phase2a_b`
Same delete flow. `SELECT COUNT(*) FROM P_hedayati_user_phones WHERE user_id = <ID_B>;` → `0`
(there was never a row).

### D3 — Full teardown (T3.16)
```sql
SELECT * FROM P_hedayati_user_phones;
```
- **Expected:** back to its pre-test state — **0 rows** on a fresh Phase 2A staging site. Delete
  any stray test row if one somehow remains.
- Run **RL-RESET** one final time.
- If you used the temporary mu-plugin harness (A1 fallback), **delete the harness file** from
  `wp-content/mu-plugins/` now and confirm it's gone.
- In **Browser 1**, confirm the administrator can still open Dashboard, Settings → General,
  Plugins, Users (auth chain and caps intact).

### D4 — Confirm the account state (T2.9)
- `qa_phase2a` and `qa_phase2a_b` both deleted.
- No `hd_rl_*` transients remain (or 15+ minutes have elapsed).
- Real admin login works from a fresh browser (proves no lingering lockout).

---

## What to send back to me

Per round: the exact command output / SQL result set / on-screen wording for every step, plus the
two user IDs. Paste raw text (no screenshots needed except any unexpected PHP error). I will
reconcile it into `docs/PHASE_2A_ACCEPTANCE.md` and `docs/CURRENT_STATE.md` and mark each test
PASS/FAIL.

---

## Tests that can lock you out — run with the escape hatches ready

- **B4** (5-fail identifier lockout) locks the `qa_phase2a` identifier for 15 min and adds ~6 to
  your shared IP bucket. Safe because: you clear it immediately with RL-RESET, and Browser 1 keeps
  an admin session regardless.
- **C3** (malformed-phone matrix) adds ~10 IP-bucket failures. Kept safe **only** by the mid-point
  and end RL-RESET steps. If you skip RL-RESET, ~40 failed logins across the checklist will trip
  the 30/IP limit and lock your IP for 15 min.
- If your IP does lock: either wait 900 s, or run RL-RESET in phpMyAdmin (independent login), or
  switch to a phone hotspot. Your admin work continues in Browser 1 throughout.
- Your **administrator** identifier bucket is never touched by this checklist (all tests use the
  `qa_*` users) — as long as you don't fail your own admin login.

---

## Deliberately EXCLUDED — destructive / Category 4 — DO NOT RUN

None of these are in the checklist above; do not perform them as part of Phase 2A acceptance:

| Excluded | Why it's out |
|---|---|
| Reset `hedayati_core_db_version` and re-run `migrate()` (T4.1) | Baseline already has migration success (T3.1). Re-running risks `dbDelta` altering a populated table. |
| `DROP TABLE hedayati_user_phones` + re-migrate (T4.2) | Destroys all phone identities. |
| Simulate concurrent `admin_init` migrations / exercise the lock (T4.3) | A stuck lock blocks all migrations for 60 s; timing-dependent. |
| Plugin deactivate → reactivate (T4.4) | Fires the activation hook; rewrite flush → transient 404s; LiteSpeed may serve stale pages. |
| Drive 30 failed logins to force the IP threshold (T4.5) | Deliberately locks your own IP out of login for 15 min. |
| Delete a **real** user to test cleanup (T4.6) | Data loss — already covered safely by D1 on a disposable user. |
| Any `wp-config.php` change — `WP_DEBUG`, prefix (T4.7) | A syntax slip downs the whole site; prefix changes are destructive. |
| Redeploy / upgrade the theme or plugin (T4.8) | Changes the artefact under test. |
| **Deploying the Phase 2B branch** (plugin 1.5.2 / DB 2.2.0 / roles 2.1.0) | Separate gated step — only after this Phase 2A acceptance closes **and** `docs/PHASE_2B_ACCEPTANCE.md` is planned. Deploying it runs migrations 2.1.0 + 2.2.0 and the roles 2.1.0 sync on staging. |
| Forced migration re-runs, table drops, deleting roles/capabilities, editing option markers by hand | Migration-safety rules in `docs/DEPLOYMENT.md`. |

If any Category 4 test becomes necessary (e.g. T1.3 finds code drift, or a defect needs a
migration re-run), it gets its own fresh backup, its own maintenance window, and explicit written
approval per test — separately from this checklist.
