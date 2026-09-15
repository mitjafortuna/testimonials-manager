# Testimonials Manager

Admin application for managing customer testimonials on landing pages — per product (parent SKU) and per country.
Built as the DFVU full-stack practical assignment. **PHP 8.1+ without a framework, MySQL 8, vanilla JS + Bootstrap.**

> **Live demo:** https://tm-dfvu.fly.dev — sign in with `admin` / `admin123`. (Free-tier machine: the first request after idle takes a few seconds.)

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
3. Make sure `storage/uploads/` and `storage/ratelimit/` are writable by the web server.

The release ZIP ships a `vendor/` directory, so Composer is not required.

## Development

| Command | What |
|---|---|
| `make test` | unit + integration + API suites (API runs against a fixture-backed built-in server; never calls the real upstream) |
| `make lint` / `make stan` | code style (PSR-12) / static analysis (level 6) |
| `make api` | API suite against a built-in PHP server inside the app container (mirrors CI) |
| `make e2e` | Playwright happy path + error visibility (Chromium in Docker) |
| `make seed-large` | run once on a fresh DB (not idempotent); generates up to 50 testimonials per landing (~150k rows by default) |

Sync source: `POST /api/landings/sync` and `bin/sync.php` read from the upstream API using `LANDINGS_API_URL`/`LANDINGS_API_KEY`. Set `LANDINGS_API_FIXTURE=tests/fixtures/landings.json` to replay the captured response instead (this is what `make api` and CI do).

## Deployment

See [deploy/README.md](deploy/README.md) for hosting on Fly.io: two apps (`tm-dfvu` for the PHP/Apache image, `tm-dfvu-db` for MySQL on a private network), `bin/install.php` as an idempotent release command, and `.github/workflows/deploy.yml` for continuous deployment on push to `main`.

## Architecture

See [docs/adr](docs/adr) for individual architecture decisions. Sections on the schema, API, shortcuts and time spent are filled in as phases land.

## Bonus features

Built on top of the core assignment brief:

- **AI mock providers.** `POST /api/testimonials/{id}/ai/translate` and `.../ai/suggest-name` run against one of three mock providers (selectable per request), used to translate testimonial text and suggest an author display name/gender. The providers are mocks (no real HTTP calls out) but implement the same interface a real provider would.
- **Drag-and-drop reorder.** Testimonials within a country landing, and images within a testimonial, can be reordered by dragging in the admin UI; the new order is persisted via a dedicated reorder endpoint per resource.
- **Copy testimonials between countries.** A country landing's testimonials can be copied onto another country's landing, in either replace mode (clears the destination first) or append mode, with a preview step before committing.
- **Bulk actions.** Multiple testimonials can be selected at once in the list view and activated, deactivated, or deleted together.
- **Change log / audit trail.** Each testimonial has a history panel showing who created/updated/deleted it and when, plus image add/remove events (see the scope note under "Deliberate shortcuts").
- **Image processing.** Uploads are downscaled to a sane maximum dimension automatically; conversion to WebP and cropping to a square are both opt-in per upload.

## One improvement

Beyond the assignment brief and its 7 bonus options, one thing was added on my own initiative: **per-IP rate limiting** (`RateLimitMiddleware`, `FileRateLimiter`). Every `/api/*` route now sits behind a fixed-window request counter — a tight bucket on `POST /api/auth/login` (10 attempts/minute per IP) and a looser general bucket on everything else (120 requests/minute per IP), both configurable via env vars.

**Why:** the login endpoint had no throttling at all — a single seeded admin account (`admin`/`admin123`) behind an internet-reachable form is a plain invitation to brute-force, and it was an explicitly documented shortcut in this README until now. Fixing it doesn't need Redis or a queue: a small file-backed counter (one JSON file per IP+bucket, guarded with `flock`) is enough for the single-machine Fly deployment this app actually runs on, and it's disabled automatically under `APP_ENV=test` so the API/e2e suites — which log in once per test, back-to-back — aren't throttled by their own speed.

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
- SKU is derived from the landing URL's last path segment (works for the current upstream URL shape; brittle if it changes).
- `ImageService::upload()` is not transactional across a multi-file batch (validation-first makes this reachable only on a disk/GD failure mid-batch).
- `ImageRepository::insert()`'s `sort_order` allocation (`SELECT MAX+1` then `INSERT`) isn't atomic under concurrent uploads to the same testimonial.
- `session.use_strict_mode` isn't set (session-fixation is still covered by `session_regenerate_id()` on login).
- Rate limiting is a simple per-IP fixed-window counter backed by local files (`storage/ratelimit/`), not a sliding window or a shared store — fine for a single small machine, would need Redis (or similar) behind a load balancer with multiple instances.
- `database/seed-images.php` always writes JPEG bytes regardless of the target filename's extension (safe today because every seeded row's filename ends `.jpg`).
- Copying testimonials between countries (phase 12) copies testimonial fields only, not their images — a copied testimonial starts with zero images.
- Bulk delete (phase 13) is not transactional across the selected ids — a failure partway through can leave a partial delete.
- The AI providers (phase 10) are mocks: `translate()` only tags text with `[CC]`, and all three providers produce byte-identical translations (only their `authorName()` sample pools and `name()` differ) — swapping in real HTTP-backed providers later only touches `AbstractMockProvider`'s subclasses.
- Image "WebP conversion" and "crop to square" (phase 15) are opt-in per upload, not applied retroactively to already-stored images.
- The change-log audit trail only covers single-record testimonial create/update/delete and image add/remove; bulk actions, copy-between-countries, and reorder mutate data directly and do not write audit entries.
- `ImageStorage::store()` (phase 15) always decodes and re-encodes the main uploaded image via GD to enforce a 1600px maximum dimension, even when no crop/WebP-conversion/downscaling is actually necessary — previously the original bytes passed through untouched. For uploads that didn't need any processing this means: JPEGs get re-encoded at a fixed quality setting (may differ slightly from the original encoding); EXIF metadata (e.g. camera orientation tags) is dropped since GD doesn't preserve it; an animated WebP upload would lose its animation and become a static single frame.

## How this was built

Spec-driven and test-driven, with AI assistance (Claude Code) for planning, implementation and review. Every commit is co-authored; every phase started from failing tests.
