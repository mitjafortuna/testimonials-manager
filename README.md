# Testimonials Manager

Admin application for managing customer testimonials on landing pages — per product (parent SKU) and per country.
Built as the DFVU full-stack practical assignment. **PHP 8.1+ without a framework, MySQL 8, vanilla JS + Bootstrap.**

> Status: in progress — see [docs/time-log.md](docs/time-log.md). Live demo link and credentials will appear here after phase 9.

## Quick start (Docker)

```bash
cp .env.example .env          # set LANDINGS_API_KEY
make build && make install
make up && make seed
open http://localhost:8080
```

Log in with **admin / admin123** (seeded demo user).

## Run on XAMPP / LAMP

Two deployment layouts are supported:

- **Vhost (recommended).** Point the vhost's `DocumentRoot` at `public/`. Routing then relies only on `public/.htaccess`.
- **Sub-folder.** Copy the whole project into `htdocs/testimonials-manager` (so the whole repo, not just `public/`, sits under the webroot) and open `http://localhost/testimonials-manager/`. The repo-root `.htaccess` rewrites everything into `public/`, blocks direct access to non-public folders (`src`, `config`, `vendor`, …), and `App\Http\Request` strips the sub-folder prefix from the request path so routes still match `/api/...`.

Either way:

1. Copy `.env.example` to `.env`; set `DB_HOST=127.0.0.1` and your MySQL credentials.
2. Import `database/schema.sql`, then `database/seed.sql`, then run `php database/seed-images.php` (creates the demo photos).
3. Make sure `storage/uploads/` is writable by the web server.

The release ZIP ships a `vendor/` directory, so Composer is not required.

## Development

| Command | What |
|---|---|
| `make test` | unit + integration + API suites (API runs against a fixture-backed built-in server; never calls the real upstream) |
| `make lint` / `make stan` | code style (PSR-12) / static analysis (level 6) |
| `make api` | API suite against a built-in PHP server inside the app container (mirrors CI) |
| `make e2e` | Playwright end-to-end tests |
| `make seed-large` | run once on a fresh DB (not idempotent); generates up to 50 testimonials per landing (~150k rows by default) |

Sync source: `POST /api/landings/sync` and `bin/sync.php` read from the upstream API using `LANDINGS_API_URL`/`LANDINGS_API_KEY`. Set `LANDINGS_API_FIXTURE=tests/fixtures/landings.json` to replay the captured response instead (this is what `make api` and CI do).

## Architecture

See [docs/superpowers/specs/2026-09-13-testimonials-manager-design.md](docs/superpowers/specs/2026-09-13-testimonials-manager-design.md) for the full design and [docs/adr](docs/adr) for individual decisions. Sections on the schema, API, shortcuts and time spent are filled in as phases land.

## Deliberate shortcuts

Filled in as phases land. Known so far:

- Frontend assets (Bootstrap, jQuery, fonts) are loaded from CDNs — the admin needs internet access; vendoring them is a one-line change if required.
- Sync is full-table, not incremental: every run re-fetches and re-upserts all upstream landings. Fine at today's scale (170 rows, thousands at most); an incremental sync (e.g. an upstream `updated_since` filter) would be the next step at real scale.
- `ON DUPLICATE KEY UPDATE ... VALUES(col)` is deprecated (without an alias) as of MySQL 8.0.20, but is kept because it also has to run on MariaDB 10.4+, which does not support the `AS` alias form.
- CSRF protection is a custom `X-Requested-With` header instead of synchroniser tokens: a custom header forces the browser to preflight the request, and since we emit no CORS headers, a cross-origin request can never carry it. Simpler than per-form tokens for a single-origin admin SPA.
- `landings.updated_at` bumps on every sync run because `last_synced_at` is one of the columns MySQL's `ON UPDATE CURRENT_TIMESTAMP` watches — so "updated" in the schema doesn't mean "content changed", only "last touched by sync".
- Single seeded user, no registration/roles/password reset (per brief); PHP file sessions.
- Docker/CI run PHP 8.2 even though the code is written to stay 8.1-compatible; the CI matrix now runs both 8.1 and 8.2 so the compatibility claim is actually checked (see `.github/workflows/ci.yml`).
- Media will be served through a PHP passthrough (`GET /media/{filename}`) rather than directly by Apache, once the images phase lands — portability (works the same under XAMPP, Docker, Fly) over raw static-file throughput.
- Product/landing counts are computed per request rather than cached/denormalised — simpler and correct-by-construction; revisit only if the counts query shows up as a bottleneck.
- Product and country counters include inactive testimonials — the admin wants to see everything that exists, not only what is currently shown.

## How this was built

Spec-driven and test-driven, with AI assistance (Claude Code) for planning, implementation and review. Every commit is co-authored; every phase started from failing tests.
