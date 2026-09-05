# Launch completion — working checklist

Working branch: `feature/phase-3-launch-completion` (off Phase 2D @ `01c4e1c`). `main` is
unchanged at `32640e4`. The prior Codex/ChatGPT working-tree WIP is preserved verbatim at commit
`7500348` and on `snapshot/codex-launch-completion-wip-2026-09-05` — nothing was discarded.
Earlier phase documents preserve design/history; the current authoritative status is
`docs/agent/STATUS.md`'s Phase 3 section.

## Confirmed release decisions (owner, 2026-09-05 — see `docs/DECISIONS.md` D41–D43)

- Preserve WordPress, Hedayati Core, the approved Navigator design, Persian RTL and light/dark.
- Accounts are reception-created (`hedayati_create_students`). No public registration.
- Reception-created accounts get a one-shot temporary password + forced first-login change; no
  email/SMS in Phase 3.
- Consultation page is phone/contact CTA only for launch; a callback form is a later feature.
- Teacher biographies and class dates/fees are public only after explicit per-record staff opt-in.
- Manager administration stays in wp-admin; teacher/TA and reception get scoped `/panel/` journeys.
- Students never see internal verification rejection notes.
- Do not publish real identity records or copy production data into tests.

## Work queue

- [x] Preserve the Codex WIP (baseline commit `7500348` + snapshot branch).
- [x] Establish real-WordPress runtime signal — via `Acceptance (Docker WordPress)` GitHub Actions
      on the Phase 3 branch (no local Docker needed). GREEN: 489/0 on HEAD `046bd31`.
- [x] Correct manager course / category / settings permissions + student-detail scope (D42;
      HD-007/008/009).
- [x] Teacher/TA assigned runs, roster, sessions, teacher-only attendance (`Hedayati_Staff_Portal`).
- [x] Reception account creation, lookup, enrollment, identity/document intake.
- [x] Forced first-login password change + one-shot temporary password (`Hedayati_Account_Security`).
- [x] Public About/Contact/Consultation/Teachers, per-run publication opt-in, Shamsi on the course
      page.
- [x] Self-hosted Vazirmatn WOFF2 (login + public pages, no CDN).
- [x] `page.php` regression handling (keeps `.entry-content`, adds `role=main`).
- [x] Split the WIP's dense one-line PHP (staff portal, public content) into readable form.
- [x] Static + runtime regression suites (Node 750/0; Docker 491/0) + docs reconciliation.
- [x] **Visual/RTL/dark-mode review** of `/panel/`, `/account/`, the forced-change screen, and the
      four public pages — done 2026-09-05 via a real-theme-CSS static render (no local
      WordPress/Docker), desktop + mobile, light + dark. Fixes in commit `3e274d9`. A final check
      on real hardware is folded into Phase 4 staging acceptance.
- [x] Follow-up real local WordPress browser review (desktop/mobile, RTL, light/dark) confirmed the
      Phase 3 templates on genuine HTTP responses; removed the WordPress admin toolbar from the
      staff panel and forced password screen after it appeared as unexplained mobile chrome.
- [ ] Merge Phase 3 → `main` once the review is done and CI is green on the merge HEAD.
- [ ] **Phase 4 — integrated staging** (`mystik.ir`, once): provision `HEDAYATI_DATA_ENCRYPTION_KEY`
      / `HEDAYATI_DATA_HMAC_KEY` / `HEDAYATI_PRIVATE_UPLOADS_DIR` + private uploads dir, deploy the
      2A+2B+2C+2D+3 build, run the post-deploy roles-sync (2.2.0 → 2.3.0) + migration check,
      execute one consolidated acceptance matrix, verify LiteSpeed never caches `/account/*` or
      `/panel/*`, Lighthouse/Web-Vitals + a11y baseline, backup/restore drill.
- [ ] Inventory legacy ASP.NET URLs/content, build the redirect map (parallel; needs institute
      access).
- [ ] Obtain approval of the tested release before deployment/cutover (Phase 5).

## Required institute inputs before launch

Approved final content / logo / contact details; which teacher/class records to publish; legacy
migration scope; authorized staging/hosting access and cutover timing. These do not block Phase 3
merge or synthetic-data testing.
