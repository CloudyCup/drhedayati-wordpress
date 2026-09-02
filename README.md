# Dr. Hedayati — WordPress Rebuild

Ground-up WordPress rebuild of the website for **مجتمع آموزشی دکتر هدایتی** (Dr. Hedayati
Educational Complex), a Persian-language computer / IT training institute. Persian-first, natively
RTL, light + dark themes.

This repository contains **two deliverables** — not a full WordPress install:

| Path | What it is |
|---|---|
| `theme/hedayati/` | Classic PHP theme. Presentation, templates, design system. Version 1.0.0. |
| `plugin/hedayati-core/` | Plugin. All persistent domain logic and data (courses, taxonomy, settings, identity, roles, migrations). Version 1.1.0. |
| `reference-react/` | Read-only React/Vite design prototype. Visual reference only — **not** production. |
| `docs/` | Project documentation (see below). |

## Status at a glance

- **Live public site:** Course catalog (custom post type + hierarchical categories), staff
  course-authoring UI, homepage, course archive/category/detail pages, 404 — implemented and
  deployed to staging (`mystik.ir`).
- **Identity foundation (Phase 2A):** phone normalization, dual username-or-phone login, a unique
  phone-identity table, versioned migrations, roles/capabilities, rate limiting — implemented in
  code, static tests passing, **staging integration acceptance still pending**.
- **Not built yet:** login/account UI, student profiles, verification, private documents, course
  runs / sessions / enrollments, staff panels, About/Contact/consultation pages, self-hosted
  Vazirmatn font, Shamsi date layer.

See [`docs/CURRENT_STATE.md`](docs/CURRENT_STATE.md) for the detailed, dated breakdown.

## Requirements

- WordPress ≥ 6.6, PHP ≥ 8.3 (staging runs PHP 8.3).
- No Composer/npm build for the shipped code — plain PHP + vanilla CSS/JS.

## Tests

```bash
# Static / structural verification of the plugin (Node, no dependencies)
node plugin/hedayati-core/tests/verify-phase2a.js

# Plugin logic tests (pure PHP with a mocked WP environment) — where PHP is available
php plugin/hedayati-core/tests/test-phase2a.php

# Syntax-lint changed plugin PHP
php -l plugin/hedayati-core/includes/<file>.php
```

Baseline: Node **74/74**, PHP **78/78** (per project handoff). These are isolated checks and do not
prove behavior on a real WordPress runtime.

## Documentation

| File | Purpose |
|---|---|
| [`AGENTS.md`](AGENTS.md) | Rules for AI coding agents (Claude Code entry point is `CLAUDE.md`) |
| [`docs/PROJECT.md`](docs/PROJECT.md) | Product, organization, users, scope |
| [`docs/CURRENT_STATE.md`](docs/CURRENT_STATE.md) | What exists right now — categorized and dated |
| [`docs/REQUIREMENTS.md`](docs/REQUIREMENTS.md) | Canonical product requirements + status |
| [`docs/ARCHITECTURE.md`](docs/ARCHITECTURE.md) | Active WordPress architecture; superseded stack |
| [`docs/DESIGN_SYSTEM.md`](docs/DESIGN_SYSTEM.md) | Brand, colors, typography, components, RTL, dark mode |
| [`docs/DATA_MODEL.md`](docs/DATA_MODEL.md) | CPT, taxonomy, meta keys, identity data, normalization |
| [`docs/SECURITY.md`](docs/SECURITY.md) | Security constraints and sensitive-data handling |
| [`docs/DEPLOYMENT.md`](docs/DEPLOYMENT.md) | Staging/production and safe deploy workflow |
| [`docs/ROADMAP.md`](docs/ROADMAP.md) | Prioritized backlog (P0–P3) |
| [`docs/DECISIONS.md`](docs/DECISIONS.md) | Architectural / product decision log |
| [`docs/HANDOFF_LEGACY.md`](docs/HANDOFF_LEGACY.md) | Historical source document (frozen) |

## Non-negotiables

WordPress-native auth and data APIs · never hardcode the `wp_` prefix · store Gregorian/ASCII
canonical data (localize at the UI) · Iranian phones canonicalize to `+989XXXXXXXXX` · never commit
secrets or real student data · no destructive/production actions without explicit approval.
