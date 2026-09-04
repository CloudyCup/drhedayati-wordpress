# LOCAL_TESTING.md — disposable local integration-test environment

A fully disposable local WordPress backend that simulates staging (`mystik.ir`)
closely enough to run **automated Phase 2A + Phase 2B integration/acceptance
tests** without needing access to the Iran-restricted staging domain.

> This is an **additional** integration/acceptance layer. It does **not** replace
> the static/unit suites (`plugin/hedayati-core/tests/verify-*.js`,
> `plugin/hedayati-core/tests/test-*.php`) — keep running those too.
>
> It is **not** a deployment target. Nothing here deploys, pushes, or touches
> `mystik.ir` or `drhedayati.com`.

---

## One-command usage

Prerequisite: **Docker Desktop / Docker Engine with the Compose plugin**
(`docker compose version` must work). Nothing else — no local PHP, MySQL or
WordPress.

```bash
# bash / macOS / Linux / WSL / Git Bash
./scripts/run-acceptance.sh
```

```powershell
# Windows PowerShell
.\scripts\run-acceptance.ps1
```

That single command will, idempotently:

1. build the WP-CLI image and start the `db` + `wordpress` containers,
2. install WordPress and activate **Hedayati Core** + the **Hedayati** theme
   (only if not already done),
3. run the integration/acceptance suite (`docker/wp-tests/run.php`),
4. reset the synthetic data to a clean state,
5. exit `0` if every assertion passed, non-zero otherwise.

The lower-level `docker compose up -d` + `./scripts/run-acceptance.sh` flow also
works — `run-acceptance` detects an already-running stack.

When you are done:

```bash
./scripts/env-down.sh      # or  .\scripts\env-down.ps1
```

`env-down` removes the containers **and volumes** — the database and WordPress
core are erased. It is fully disposable; next `run-acceptance` rebuilds from
scratch.

---

## What it simulates (and where it differs from mystik.ir)

| Aspect | Local environment | mystik.ir (staging) |
|---|---|---|
| WordPress | `wordpress:6.6-php8.3-apache` (pinned) | whatever is installed on staging |
| PHP | 8.3 (Docker image) | 8.3+ (host) — patch version differs |
| Database | MySQL 8.0 container, `utf8mb4` / `utf8mb4_unicode_ci` | shared MySQL on the host; collation may be `utf8mb4_general_ci` |
| Table prefix | `mystik_` (configurable; deliberately not `wp_`) | the real staging prefix |
| Plugin / theme | bind-mounted from `plugin/hedayati-core` + `theme/hedayati` (live source) | packaged build from `staging-export/` |
| Object cache | none (transients in the options table) | may have a persistent object cache |
| HTTPS / domain / email | `http://localhost:8080`, `--skip-email` | real domain, real TLS, real mail |
| Cron | `DISABLE_WP_CRON` | real WP-Cron |
| Users / data | synthetic, wiped every run | disposable QA accounts, operator-driven |
| Other plugins / mu-plupins | none | whatever staging carries |
| Web server | Apache + `mod_php` | staging's real stack |
| Migrations trigger | forced via WP-CLI (`admin_init` never fires in CLI) | real `admin_init` on first admin request |

Because of these differences, **a green local run is not a substitute for the
`docs/PHASE_2B_ACCEPTANCE.md` staging matrix** — it is a fast, deterministic
pre-check that catches regressions in migrations, constraints, capability
mapping, REST exposure, cascades and the service-layer guards long before the
staging window.

---

## The scripts

All scripts exist as both `*.sh` and `*.ps1` and are safe to re-run.

| Script | What it does |
|---|---|
| `scripts/env-up` | Build the WP-CLI image; start `db` + `wordpress`. No WP install. |
| `scripts/wp-install` | Install WordPress automatically, then activate plugin + theme. Idempotent. |
| `scripts/activate` | Activate **Hedayati Core** + the **Hedayati** theme and force the schema (`Hedayati_DB_Schema::migrate()`) + role (`Hedayati_Roles::register_roles()`) migrations to run now. |
| `scripts/reset` | Drop **every** table (`wp db reset`), reinstall WordPress, re-activate. The "known clean state" reset. |
| `scripts/run-acceptance` | The one-command entry point (see above). Exit code = suite result. |
| `scripts/env-down` | Stop and remove containers **and volumes**. Full wipe. |

Ad-hoc WP-CLI against the running environment:

```bash
docker compose -f docker/docker-compose.yml run --rm wpcli option get hedayati_core_db_version
docker compose -f docker/docker-compose.yml run --rm wpcli cap list hedayati_manager
```

Browse the site (sanity only) at <http://localhost:8080/wp-admin> —
`admin` / `admin_local_only` (override in `docker/.env`).

---

## The test suite

`docker/wp-tests/` — loaded inside a fully-booted WordPress via `wp eval-file`,
so it sees the real `$wpdb`, roles, hooks, REST server and UNIQUE constraints.

| File | Scope |
|---|---|
| `helpers.php` | Assertion recorder (`HDIT`) + synthetic-data factory & hard reset (`HDIT_Env`). |
| `test-phase-2a.php` | migrations/version markers · tables/indexes/charset · live role matrix · phone normalization + service **and** DB uniqueness · username & phone auth through `wp_authenticate()` · rate limiter + pipeline enforcement. |
| `test-phase-2b.php` | Teacher CPT authorization (authorized **and** unauthorized roles; the 1.5.2 meta-cap fix) · Teacher REST route privacy · Teacher↔user 1:1 link + unlink-on-delete · Course Run create/validate · Session service + DB uniqueness + time rules · staff assignment rules (F1–F7) · enrollment duplicate/capacity/closed-run guards · attendance upsert + cross-run **IDOR** guard + bulk · Shamsi→Gregorian conversion **and** canonical persistence · audit-log creation / failure-silence / append-only / no-PII · run/course/user deletion cascades. |
| `run.php` | Entry point. Prints an environment banner, resets, runs both phases, resets again, prints `ACCEPTANCE TOTAL: N passed, M failed` and `RESULT: PASS/FAIL`, exits `0` / `1` / `2`. |

### Test guarantees

- **Synthetic disposable data only** — users prefixed `hdit_`, e-mails
  `@hedayati.test`, posts tagged with a private `_hdit_synthetic` meta.
- **Known clean state** — `HDIT_Env::reset()` runs before and after the suite
  (and at two mid-points), deleting all synthetic posts/users (firing the real
  cascade hooks) and emptying the Hedayati tables.
- **Deterministic & repeatable** — no reliance on wall-clock dates, random data
  or external services; two consecutive runs produce identical output.
- **Fails loudly** — any failed assertion → `RESULT: FAIL` and process exit `1`;
  an inactive plugin or fatal error → exit `2`.
- **Public-API first** — behaviour is checked through
  `Hedayati_*_Service` / `Hedayati_Phone` / `Hedayati_Auth` / `wp_authenticate()`
  / the REST server / capability checks, not private internals. The few
  schema-shape assertions (`SHOW INDEX`, `SHOW COLUMNS`, `information_schema`)
  verify the migration's *observable* result, not its code.

---

## Secrets

- `docker/.env` is **gitignored**. It is seeded from `docker/.env.example` on
  first run and contains only **local-only throwaway** credentials.
- Never point this environment at a real database and never copy a real secret
  into `docker/.env`.
- The suite never prints credentials, phone numbers, e-mails or national IDs;
  one audit-log assertion explicitly checks that no synthetic PII leaked into an
  audit `note`.

---

## Troubleshooting

| Symptom | Fix |
|---|---|
| `Docker Compose not found` | Install Docker Desktop / the `docker-compose-plugin`. |
| `timed out waiting for wp-config.php` | `docker compose -f docker/docker-compose.yml logs wordpress` — usually the DB was still initialising; re-run. |
| WP-CLI "Error establishing a database connection" | The `db` healthcheck passed but MySQL is still warming up; re-run `run-acceptance`. |
| Permission errors on `wp-content` | `./scripts/env-down.sh` then `./scripts/run-acceptance.sh` to recreate volumes. |
| Tests fail after editing the plugin | Expected — the plugin is bind-mounted live. Fix the code (or the test) and re-run; no rebuild needed. |
| Want a totally fresh DB but keep containers | `./scripts/reset.sh` / `.ps1`. |
