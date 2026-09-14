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

## Run on XAMPP / LAMP

1. Point the vhost DocumentRoot at `public/` (or copy the project into `htdocs/testimonials-manager` and open `/testimonials-manager/public/`).
2. Copy `.env.example` to `.env`; set `DB_HOST=127.0.0.1` and your MySQL credentials.
3. Import `database/schema.sql` then `database/seed.sql`.
4. Make sure `storage/uploads/` is writable by the web server.

The release ZIP ships a `vendor/` directory, so Composer is not required.

## Development

| Command | What |
|---|---|
| `make test` | all PHPUnit suites (unit, integration, api) |
| `make lint` / `make stan` | code style (PSR-12) / static analysis (level 6) |
| `make api` | API suite against a built-in PHP server inside the app container (mirrors CI) |
| `make e2e` | Playwright end-to-end tests |
| `make seed-large` | generate ~300 products × 20 countries × 50 testimonials |

Sync source: `POST /api/landings/sync` and `bin/sync.php` read from the upstream API using `LANDINGS_API_URL`/`LANDINGS_API_KEY`. Set `LANDINGS_API_FIXTURE=tests/fixtures/landings.json` to replay the captured response instead (this is what `make api` and CI do).

## Architecture

See [docs/superpowers/specs/2026-09-13-testimonials-manager-design.md](docs/superpowers/specs/2026-09-13-testimonials-manager-design.md) for the full design and [docs/adr](docs/adr) for individual decisions. Sections on the schema, API, shortcuts and time spent are filled in as phases land.

## Deliberate shortcuts

Filled in as phases land. Known so far:

- Frontend assets (Bootstrap, jQuery, fonts) are loaded from CDNs — the admin needs internet access; vendoring them is a one-line change if required.

## How this was built

Spec-driven and test-driven, with AI assistance (Claude Code) for planning, implementation and review. Every commit is co-authored; every phase started from failing tests.
