# Launch completion — working checklist

Owner authorized Codex to take over on 2026-09-05; Claude is stopped.
Working branch: `feature/launch-completion`, preserving Claude's Phase 2D work at `01c4e1c`.
This checklist is the current release queue. Earlier phase documents preserve design/history;
their test totals and "not implemented" statements are not current evidence.

## Confirmed release decisions

- Preserve WordPress, Hedayati Core, the approved Navigator design, Persian RTL and light/dark modes.
- Accounts are reception-created. No public registration, payments, OTP or callback form for launch.
- Consultation uses institute phone/contact details; a callback form is a later feature.
- Teacher biographies and class dates/fees may be public only after explicit staff publication.
- Existing manager administration may remain in wp-admin; teacher/TA and reception need usable scoped journeys.
- Do not publish real identity records or copy production data into tests.

## Work queue

- [ ] Establish repeatable local real-WordPress testing without requiring Docker on this machine.
- [ ] Verify Phase 2D runtime and HTTP login/reset, portal guards, upload/download and cross-user denial.
- [ ] Correct manager course/category/settings permissions and direct student-detail scope.
- [ ] Complete teacher/TA assigned runs, roster, sessions and teacher-only attendance.
- [ ] Complete reception account creation, lookup, enrollment and identity/document intake.
- [ ] Complete public About/Contact/Consultation, staff-approved teacher/class publication and Shamsi display.
- [ ] Load licensed local Persian fonts and preserve editable branding/content.
- [ ] Verify mobile/desktop RTL, light/dark, keyboard navigation and public/private cache behavior.
- [ ] Run regression suite, record actual results, prepare versioned deployment artifacts.
- [ ] Inventory legacy URLs/content and prepare redirect/migration mapping with authenticated access if needed.
- [ ] Verify staging environment, secrets/private storage, email, backups and a restore/rollback rehearsal.
- [ ] Obtain approval of the tested release before deployment/cutover.

## Baseline evidence

- 2026-09-05: six local Node suites passed: 98 + 53 + 74 + 208 + 132 + 82 = 647 assertions.
- GitHub read directly: latest green acceptance run is Phase 2C `372b169`; no Phase 2D runtime run yet.
- PHP/Docker absent from PATH at takeover. Portable local runtime preparation in progress.
- No staging/production changes, commits, pushes or merges performed by this takeover.

## Required institute inputs before launch

Approved final content/logo/contact details; which teacher/class records staff wish to publish;
legacy migration scope; authorized staging/hosting access and cutover timing. These do not block
local implementation and synthetic-data testing.
