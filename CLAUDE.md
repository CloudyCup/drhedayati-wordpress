# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

@AGENTS.md

## Claude Code workflow notes

- **Environment:** Windows, PowerShell primary shell (Bash tool also available). WordPress and PHP
  are **not installed locally** — you cannot boot the site or run `php` here. The Node check
  (`node plugin/hedayati-core/tests/verify-phase2a.js`) does run; it is static/structural only.
- **This is a theme + plugin, not a WordPress install.** Template hierarchy files resolve against a
  real WP runtime you don't have — reason about them from the code, don't try to execute them.
- **Repo cruft to leave alone** (do not delete, do not build from): `package-plugin/` (stale
  pre-Phase-2A plugin copy), root `hedayati-core.zip`, and the root file named `drhedayati-wordpress`
  (an accidentally-committed diff dump). Flag them if asked, but they are out of scope.
- **`reference-react/`** is the design prototype. The approved direction is `NavigatorHome` in
  `reference-react/src/components/Concepts.jsx`. Read it for visual intent only.
- **Packaging** (only when asked): `cd theme && tar -a -c -f hedayati.zip hedayati` and
  `cd plugin && tar -a -c -f hedayati-core.zip hedayati-core`. Use `tar -a`, never PowerShell
  `Compress-Archive` (produced archives the host mis-extracts). See `docs/DEPLOYMENT.md`.
- **Persian UI strings** are expected throughout templates and admin — match the existing tone and
  keep `esc_html_e()` / text-domain usage (`hedayati` for the theme, `hedayati-core` for the plugin).
- **Current priority** (per the handoff, unless the user redirects): Phase 2A *staging integration
  acceptance* — not Phase 2B feature work. You cannot perform that acceptance from here; scope work
  accordingly and surface what needs a real staging environment.
- When you change code that a `docs/*` file describes, update that doc in the same change and bump
  the date in `docs/CURRENT_STATE.md`.
