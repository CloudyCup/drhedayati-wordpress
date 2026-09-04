# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

@AGENTS.md

## Claude Code workflow notes

- **Environment:** Windows, PowerShell primary shell (Bash tool also available). WordPress and PHP
  are **not installed locally** — you cannot boot the site or run `php` here. The Node check
  (`node plugin/hedayati-core/tests/verify-phase2a.js`) does run; it is static/structural only.
- **This is a theme + plugin, not a WordPress install.** Template hierarchy files resolve against a
  real WP runtime you don't have — reason about them from the code, don't try to execute them.
- **Local integration environment:** `docker/` + `scripts/*.{sh,ps1}` provide a disposable Docker
  Compose WordPress backend (see `docs/LOCAL_TESTING.md`). `./scripts/run-acceptance.sh` runs the
  Phase 2A/2B runtime suite in `docker/wp-tests/`. Requires Docker — **not available in this
  Claude Code environment** (no WSL2). It is an *additional* layer; it does not replace the
  Node/PHP static suites and does not change the `mystik.ir` staging gate.
- **Removed 2026-09-03** (D27, owner-approved): `package-plugin/` (stale `1.0.0` copy) and the root
  `drhedayati-wordpress` diff dump. Stale gitignored ZIPs (`./hedayati-core.zip`,
  `plugin/hedayati-core.zip`, old `staging-export/*.zip`, all `1.1.0`) were deleted from the tree.
  If any reappear, they are junk — never build from or deploy them.
- **`reference-react/`** is the design prototype. The approved direction is `NavigatorHome` in
  `reference-react/src/components/Concepts.jsx`. Read it for visual intent only.
- **Packaging** (only when asked): run `pwsh ./scripts/build-packages.ps1` — it packages **only**
  `plugin/hedayati-core/` + `theme/hedayati/` with `tar -a` (never `Compress-Archive`) into
  `staging-export/`, and fails on a wrong layout or a version mismatch. See `docs/DEPLOYMENT.md`.
- **Persian UI strings** are expected throughout templates and admin — match the existing tone and
  keep `esc_html_e()` / text-domain usage (`hedayati` for the theme, `hedayati-core` for the plugin).
- **Current priority** (per the handoff, unless the user redirects): Phase 2A *staging integration
  acceptance* — not Phase 2B feature work. You cannot perform that acceptance from here; scope work
  accordingly and surface what needs a real staging environment.
- When you change code that a `docs/*` file describes, update that doc in the same change and bump
  the date in `docs/CURRENT_STATE.md`.
