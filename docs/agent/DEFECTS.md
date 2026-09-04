# Defects and acceptance gaps — 2026-09-04

Reviewed at 345e368. These are harness defects and evidence gaps, not newly proven product vulnerabilities. No product code changed.

## HD-001 — OPEN — Bash bootstrap fails on default configuration

docker/.env.example:16 contains WP_TITLE=Hedayati Local Test. scripts/lib.sh sources it under set -e; Bash interprets Local as a command and exits before Docker is checked. Independently reproduced with Git Bash: line 16: Local: command not found, exit 1.

Required fix: use consistent dotenv handling for values with spaces in both shells (quoting the example alone also requires stripping those quotes in the current PowerShell loader), or make the default title shell-safe. Verify both launchers' interpreted title and startup behavior.

## HD-002 — OPEN — Phone deletion acceptance discrepancy is untested

Owner reports one orphan phone row after QA-user deletion, manually removed. class-user-phone-service.php registers deleted_user -> delete_phone, but the hook's presence does not prove runtime cleanup. Neither integration phase directly asserts deletion of a phone-bearing user's row; helpers.php reset later DELETEs every phone row and can hide the failure.

Required evidence: assign a synthetic phone, delete that user through WordPress, assert both user deletion and zero phone rows for its ID BEFORE reset/manual cleanup. Keep automatic cleanup unverified until this passes and reconcile staging conditions. Related staging IDs: T2.8, T3.15 step 5, T3.16.

## HD-003 — OPEN — Runtime suite covers only part of the acceptance matrix

- A2/A3: no invalid-nonce or valid-nonce/insufficient-capability admin-post requests. A4's unrelated-user helper test does not exercise a staffed user attempting another run through the handler. A5's role-cap check does not prove a manager's attendance POST is refused.
- T2: fixture writes Teacher link metadata directly; never attempts a second profile through the real save handler. 1:1 refusal is not tested.
- T5: REST requests run anonymously only; authenticated low-privilege requests are missing.
- R5: partial has/not matrix, not every one of the 22 caps across all roles.
- G2: completed run tested, cancelled run missing. G5/S3/G6: direct enrollment/session deletion and user-deletion attendance cascades are incomplete (G6 creates no attendance).
- J1/J4/J6/J8: not every mutation/action/count or actor attribution is asserted; viewer authorization/filter/pagination behavior is missing. No broad PII guarantee follows from checking two synthetic strings in notes.
- B5/J9: migrate() with an already-current version exercises the no-op gate, not re-execution of dbDelta. Index-name checks do not inspect Non_unique; engine/charset coverage is incomplete.

These are coverage gaps. Add targeted runtime cases before claiming the whole matrix passed; do not label existing product guards broken without evidence.

## HD-004 — OPEN — Cleanup failure can still report PASS

docker/wp-tests/run.php computes HDIT::finish() before final reset, catches reset exceptions as warnings without changing the success exit code, and always prints environment reset to a clean state. helpers.php reset ignores DELETE return values, so SQL cleanup failures need not throw at all.

Required fix: check cleanup results, return nonzero on cleanup failure, and print clean-state success only after verified cleanup. Include a disposable-environment guard: reset currently empties all seven Hedayati tables and checks ABSPATH/plugin availability only. Never use this harness against a real site.
