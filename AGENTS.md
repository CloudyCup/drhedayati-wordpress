# AGENTS.md

Vendor-neutral rules for any AI coding agent working in this repository. Claude Code loads this
via `@AGENTS.md` from `CLAUDE.md`. Other agents should read it directly.

## What this project is

A ground-up **WordPress** rebuild of the website for **مجتمع آموزشی دکتر هدایتی** (Dr. Hedayati
Educational Complex) — a Persian-language computer / IT training institute in Iran (Tabriz and
Tehran). Not a medical practice.

The codebase is **two deliverables only** — there is no WordPress core, no `wp-config.php`, and no
database in this repo:

- `theme/hedayati/` — a classic PHP theme (presentation).
- `plugin/hedayati-core/` — a plugin holding all persistent domain logic and data.

Staging runs at `mystik.ir`. Production (`drhedayati.com`) still runs the legacy ASP.NET/MSSQL site
and must stay untouched until an approved cutover.

## Authoritative documentation

Read these before non-trivial work. When code and docs disagree, **code wins for "what exists";
the handoff wins for "what was intended".**

| File | Purpose |
|---|---|
| `docs/PROJECT.md` | Product, org, users, scope |
| `docs/CURRENT_STATE.md` | What is actually built right now (dated, categorized) |
| `docs/REQUIREMENTS.md` | Canonical product requirements + status |
| `docs/ARCHITECTURE.md` | Active WordPress architecture; superseded stack (historical) |
| `docs/DESIGN_SYSTEM.md` | Brand, tokens, components, layout, RTL, dark mode |
| `docs/DATA_MODEL.md` | CPT, taxonomy, meta keys, user/phone data, normalization rules |
| `docs/SECURITY.md` | Security constraints, sensitive-data handling |
| `docs/DEPLOYMENT.md` | Staging/production, packaging, deploy workflow |
| `docs/ROADMAP.md` | Prioritized backlog (P0–P3) |
| `docs/DECISIONS.md` | Decision log — including why the stack changed |
| `docs/HANDOFF_LEGACY.md` | Historical source document — **do not edit**; superseded by the above |

## Rules

1. **Inspect before editing.** Read the target file, related classes/templates, `git status`, and
   the relevant doc section. Understand the current implementation and its backward-compatibility
   and migration implications before changing anything.
2. **Preserve working behavior.** Do not break the public site (homepage, course archive, category
   archives, single course, 404) or the admin course-authoring flow. Preserve unrelated changes.
3. **Preserve Persian / RTL.** The site is Persian-first and natively RTL. Keep logical CSS
   properties, correct bidi handling for mixed Persian/English technical text, and RTL-correct
   keyboard/focus order. Test layouts in RTL, not just mirrored.
4. **Use the active WordPress architecture.** Persistent business logic and data live in
   `hedayati-core` (so they survive a theme switch); presentation lives in `theme/hedayati`. Use
   WordPress-native APIs: CPTs, taxonomies, `register_post_meta`, Settings API, roles/capabilities,
   `$wpdb` with `$wpdb->prepare`, `wp_authenticate_*`, nonces, `dbDelta`.
5. **Do not reintroduce superseded architecture.** No React/Vite SPA runtime, no Express, no
   Prisma, no PostgreSQL, no application-managed password hashing, no Google login. `reference-react/`
   is read-only visual reference — never wire it into production or edit it as production code.
6. **Avoid unnecessary dependencies.** Prefer WordPress primitives and vanilla PHP/CSS/JS. The theme
   ships zero JS libraries (no jQuery). Add a dependency only with a clear justification and approval.
7. **Validate changes.** Run `node plugin/hedayati-core/tests/verify-phase2a.js` and, where PHP is
   available, `php plugin/hedayati-core/tests/test-phase2a.php` and `php -l` on changed PHP files.
   Isolated/static tests passing is **not** proof of WordPress runtime behavior — say so.
8. **No destructive or outward-facing actions without explicit approval.** Do not deploy, do not run
   migrations against a real database, do not modify production, do not delete files, do not force-push,
   do not change DNS. Branch off `main` for changes; commit/push only when asked.
9. **Protect credentials and private data.** Never commit hosting credentials, DB passwords, API keys,
   WordPress salts, encryption keys, or any real student personal data (names, phone numbers, national
   IDs, documents). Never put personal data in URLs or logs. Private student documents must never be
   served as public Media Library URLs.
10. **Canonical data rules are non-negotiable.** Store Gregorian ISO dates and ASCII digits; Shamsi
    and Persian/Arabic digits are UI-layer only. Iranian phone numbers canonicalize to E.164
    `+989XXXXXXXXX`. Never hardcode the `wp_` table prefix.
11. **Do not invent institute facts.** No prices, capacities, statistics, certifications, years of
    operation, or verification benefits unless the institute has confirmed them.

## When to update documentation

Update the relevant `docs/*` file (and `CURRENT_STATE.md`'s date) in the **same change** that:

- adds or removes a feature, CPT, taxonomy, meta key, role, capability, DB table, or migration;
- changes a design token, a public template's structure, or the homepage section list;
- changes a normalization/validation rule, auth flow, or security constraint;
- changes the build/packaging or deployment workflow;
- makes or reverses an architectural/product decision (append to `docs/DECISIONS.md`).

Keep `docs/HANDOFF_LEGACY.md` unchanged.
