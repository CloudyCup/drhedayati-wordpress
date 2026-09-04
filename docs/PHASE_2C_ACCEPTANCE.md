# Phase 2C — Student Identity, Verification, Private Documents Acceptance (staging matrix)

**Status: NOT YET EXECUTED ON STAGING.** This document is the concise smoke-test matrix for the
`mystik.ir` staging retest, per the same pattern as `docs/PHASE_2A_ACCEPTANCE.md` /
`docs/PHASE_2B_ACCEPTANCE.md`. Authoring it is in scope for this work; **running it, and any
deploy, is a separate, explicit, owner-approved step** — no automatic deployment happens as part
of building Phase 2C (`AGENTS.md` rule 8).

Prerequisite for this entire matrix: `docs/agent/STATUS.md`'s "Docker/GitHub Actions runtime
acceptance" gate for Phase 2C must be **GREEN** first (`docker/wp-tests/test-phase-2c.php` via the
`Acceptance (Docker WordPress)` workflow on `feature/phase-2c-student-portal`). Local Docker CI
proves the same code against a real WordPress + MySQL on the CI runner; this matrix additionally
confirms staging-specific facts CI cannot know about (real `wp-config.php` key provisioning, real
hosting filesystem behavior, real capability sync after deploy).

Constraints (unchanged from Phase 2A/2B): operator drives every authenticated step; no destructive
DB changes without per-test approval; no production (`drhedayati.com`) contact; take a fresh full
backup before any state-changing test; use only disposable/synthetic student accounts and
fabricated (checksum-valid) national IDs — **never a real person's data**.

---

## Pre-flight — infrastructure provisioning (blocks everything below)

| ID | Check | Pass condition |
|---|---|---|
| P1 | `HEDAYATI_DATA_ENCRYPTION_KEY` defined in staging `wp-config.php` (outside Git) | base64, decodes to 32 bytes; distinct from any Docker-CI test key |
| P2 | `HEDAYATI_DATA_HMAC_KEY` defined, separate from P1 | base64, decodes to 32 bytes; not derived from P1 or any WP salt |
| P3 | `HEDAYATI_PRIVATE_UPLOADS_DIR` defined, points outside the web root | directory exists, is writable by PHP, `realpath()` does not start with the site's `ABSPATH` |
| P4 | Plugin deploy: version, schema, roles | `HEDAYATI_CORE_VERSION` `1.6.0`; `hedayati_core_db_version` `2.3.0`; `hedayati_core_roles_version` `2.2.0`; 23 managed capabilities |

**If P1/P2/P3 are not yet provisioned, stop here.** Do not test with an ad hoc or weak key "just to
see it work" — that is exactly the fail-closed behavior D36 requires, and a passing test against a
misconfigured key proves nothing.

## A. Schema & capability sync

| ID | Check | Pass condition |
|---|---|---|
| A1 | Migration ran | `hedayati_student_verification` and `hedayati_documents` tables exist (`SHOW TABLES LIKE`) |
| A2 | Unique constraints | `uq_user_id` and `uq_national_id_hmac` present on `hedayati_student_verification` |
| A3 | New capability assigned correctly | `reception` and `hedayati_manager` hold `hedayati_upload_student_documents`; `student`/`teacher`/`teacher_assistant` do not (WP-CLI: `wp role list-caps <role>`) |
| A4 | Administrator retains full access | `administrator` holds all 23 managed capabilities |

## B. National ID — storage, encryption, duplicate detection

| ID | Check | Pass condition |
|---|---|---|
| B1 | Staff sets a national ID for a disposable student | Save succeeds; the raw DB column (`SELECT national_id_enc FROM ...`) is not the plaintext and does not contain the digits |
| B2 | Invalid checksum rejected | A wrong check-digit value is refused with a friendly error, not a fatal |
| B3 | Duplicate national ID rejected | Assigning the same national ID to a second disposable student fails with the "already exists" error |
| B4 | Persian-digit input | A national ID typed in Persian digits normalizes and validates identically to its ASCII form |

## C. Verification workflow

| ID | Check | Pass condition |
|---|---|---|
| C1 | Initiate requires a national ID on file | Attempting to initiate for a student with no national ID is refused |
| C2 | Enforced transitions | `initiate` → `pending` → `approve` → `verified` works; `approve`/`reject` while not `pending` is refused; re-`initiate` while already `pending`/`verified` is refused |
| C3 | Rejection is reversible | `reject` → `rejected`, then `initiate` again → `pending` succeeds |
| C4 | Legal-name change resets verification | Editing a **verified** disposable student's first/last name (WordPress profile screen) drops them back to `unverified` |
| C5 | Non-triggers confirmed | Editing the same student's email, phone, or address does **not** change their verification status |

## D. Privileged national-ID reveal (the one plaintext-rendering path)

| ID | Check | Pass condition |
|---|---|---|
| D1 | Manager/administrator can reveal | The "نمایش شناسه ملی" action, run as `hedayati_manager`, shows the correct decrypted value |
| D2 | Reception cannot reveal | The same action as `reception` is refused (403) — the button should not even render for this role |
| D3 | Teacher/TA cannot reveal | Same refusal for `teacher` and `teacher_assistant` |
| D4 | Student cannot reveal their own | Same refusal for the student's own account |
| D5 | Reveal is audited without the value | An `identity.viewed` row appears in «گزارش رویدادها»; its note contains no national-ID digits |
| D6 | No caching artifact | The browser/proxy does not serve a cached copy of the reveal response on a second visit (network tab: no-store) |

## E. Staff-assisted document intake

| ID | Check | Pass condition |
|---|---|---|
| E1 | Reception can upload on behalf of a student | A genuine PDF/JPEG/PNG uploads successfully for a disposable student account |
| E2 | Teacher/TA cannot upload | The upload control is unreachable/refused for these roles |
| E3 | Scope check | Attempting to upload "on behalf of" a non-student account (e.g. another staff member) is refused |
| E4 | Spoofed file rejected | An HTML file renamed `.pdf`, or a text file renamed `.jpg`, is rejected with a clear error, not silently accepted |
| E5 | No public URL | The uploaded file is not reachable via any Media Library or guessable `/wp-content/uploads/...` URL |

## F. Document lifecycle

| ID | Check | Pass condition |
|---|---|---|
| F1 | Download | `hedayati_manager`/`administrator` can download a stored document; `reception` cannot (only `hedayati_view_private_documents` holders can) |
| F2 | Archive confirmation | Marking a document "منتقل به خارج از میزبان" sets an archived state, audited |
| F3 | Purge eligibility | A freshly archived document does not yet offer a purge action; one that's (synthetically, for this test) old enough does |
| F4 | Manual purge only | No purge happens without an explicit staff click — confirm nothing was purged automatically since P4's deploy |
| F5 | Purge outcome | After purging, the file is gone but the document row (metadata) remains, marked deleted |

## G. Cleanup

| ID | Check | Pass condition |
|---|---|---|
| G1 | Deleting a disposable student | Their verification row is deleted; their documents are purged (bytes gone, rows kept, marked deleted); both are audited before deletion |
| G2 | Teardown | Every disposable student/document created for this matrix is removed at the end; re-run `A1`–`A4` to confirm no drift |

---

## Not required for this gate (deferred, mirroring Phase 2A/2B precedent)

- A full 6-role × every-new-capability negative matrix beyond the representative rows above (D2–D4,
  E2 already cover the highest-risk actions).
- Load/volume testing of the document storage path.
- The actual ~48-hour offsite-transfer mechanism — Phase 2C only builds the manual confirmation
  step (F2); no real transfer integration exists or is claimed.
