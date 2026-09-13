# Testimonials Manager — Design Spec

**Date:** 2026-09-13 · **Deadline:** 2026-09-20 (7 days) · **Status:** approved by owner, pending written review

Practical assignment for a Full-stack developer position at DFVU (source: https://develop.s-mania.com/it/testimonials/). Build a standalone admin application for managing customer testimonials on landing pages — per product (parent SKU) and per country.

## 1. Goals and evaluation criteria

What the reviewers explicitly assess:

1. Data model design (their #1 criterion).
2. Code structure without a framework.
3. Security (server-side validation, uploads, auth, prepared statements).
4. Deliberate, explained shortcuts.
5. "Few features done well" beats "everything done badly".

What we add on top (owner's goals): spec-driven + test-driven development, one commit per phase, public GitHub repo, Docker, CI, a live hosted link, thorough README, transparent AI-assisted workflow.

## 2. Hard constraints (from the brief)

| Area | Requirement |
|---|---|
| Backend | PHP 8.1+, **no framework**, PDO with prepared statements |
| Database | MySQL 8 / MariaDB 10.4+, `utf8mb4_unicode_ci` |
| Frontend | Plain JS/HTML/CSS; jQuery and Bootstrap allowed; UI language English |
| Runtime | Must run on WampServer / XAMPP / LAMP |
| API | REST + JSON, real HTTP status codes (no `200 {"error"}`) |
| Search/paging | Server-side |
| Sync | Upsert by upstream `id`; never truncate; testimonials keep their `landing_id` |
| Images | JPG/PNG/WebP, size limit enforced server-side, UUID filenames, files outside DB, thumbnails |
| Config | Upstream URL + API key in `.env`, not in code |
| Deliverables | ZIP, `schema.sql`, `seed.sql`, time spent |

Consequences: zero runtime Composer dependencies (dev-only tooling is fine), `.htaccess` routing, no frontend build step, uploads via a PHP passthrough rather than relying on Apache config.

## 3. Upstream data source

`GET https://develop.s-mania.com/it/testimonials/landings-api.php` with header `X-Api-Key`. Params: `sku` (csv), `country`, `limit` (default 500, max 1000), `offset`, `pretty`.

Observed response (2026-09-13): 170 landings, 10 products.

```json
{ "data": [ { "id": 61763, "parent_sku": "abforge", "country": "EN", "is_master": true,
              "url": "https://s-mania.com/v1/en/abforge",
              "title": "…", "description": "…",
              "image": "https://…/var-1.webp", "status": "READY FOR ADVERTISEMENT" } ],
  "meta": { "count": 3, "total": 170, "limit": 3, "offset": 0, "products": 10, "generated_at": "…" } }
```

`id` is stable and is our sync key. `status` is stored verbatim as an informational field.

## 4. Data model

All tables InnoDB, `utf8mb4_unicode_ci`. Timestamps are `DATETIME` in UTC.

```
users               id PK AI, username VARCHAR(64) UNIQUE, password_hash VARCHAR(255),
                    display_name VARCHAR(128), created_at, updated_at

products            id PK AI, parent_sku VARCHAR(64) UNIQUE, title VARCHAR(255),
                    description TEXT, image VARCHAR(512), created_at, updated_at
                    -- title/description/image copied from the EN master landing at sync time

landings            id PK (= upstream id, NOT auto-increment), product_id FK→products,
                    country CHAR(2), is_master TINYINT(1), url VARCHAR(512),
                    title VARCHAR(255), description TEXT, image VARCHAR(512), status VARCHAR(64),
                    removed_at DATETIME NULL, last_synced_at DATETIME,
                    created_at, updated_at
                    UNIQUE(product_id, country), INDEX(country), INDEX(removed_at)

testimonials        id PK AI, landing_id FK→landings (ON DELETE RESTRICT),
                    author_name VARCHAR(128), text VARCHAR(2000),
                    rating TINYINT UNSIGNED NULL CHECK (rating BETWEEN 1 AND 5),
                      -- NULL = "random": resolved to 4.0–5.0 at display time
                    gender ENUM('male','female','unisex'), url VARCHAR(512) NULL,
                    is_active TINYINT(1) DEFAULT 1, sort_order INT UNSIGNED,
                    created_at, updated_at, created_by FK→users NULL, updated_by FK→users NULL
                    INDEX(landing_id, sort_order), INDEX(landing_id, is_active)

testimonial_images  id PK AI, testimonial_id FK→testimonials (ON DELETE CASCADE),
                    filename VARCHAR(64) (uuid.ext), thumb_filename VARCHAR(64),
                    mime VARCHAR(32), size_bytes INT UNSIGNED, width SMALLINT, height SMALLINT,
                    sort_order INT UNSIGNED, created_at, created_by FK→users NULL
                    INDEX(testimonial_id, sort_order)

change_log          id PK AI, entity_type VARCHAR(32), entity_id INT UNSIGNED,
                    action VARCHAR(32) (create|update|delete|reorder|bulk|copy|image_add|image_delete),
                    changes JSON NULL (before/after diff), user_id FK→users NULL, created_at
                    INDEX(entity_type, entity_id, created_at)

sync_runs           id PK AI, started_at, finished_at NULL, status ENUM('running','ok','failed'),
                    added INT, updated INT, removed INT, error_message TEXT NULL
```

Decisions (each becomes an ADR):

- **Upstream id as `landings.id`.** Sync is a pure `INSERT … ON DUPLICATE KEY UPDATE`; testimonials never lose their reference. `(product_id, country)` is a secondary unique key that guards against upstream inconsistencies.
- **Soft-delete on disappearance.** A landing missing from a full sync gets `removed_at` set; it is hidden from the UI but its testimonials are preserved. Hard delete is a manual operation we do not build.
- **`products` is a real table**, populated from master landings during sync. The search page becomes one indexed query over `products` with LEFT JOINs for counts, instead of grouping over `landings`.
- **Inheritance is resolved at read time.** A landing with zero testimonials is served the EN master's set, flagged `inherited: true`. Nothing to keep in sync; the "copy from EN" bonus materialises the inherited set into real rows.
- **Counts are computed, not cached.** With the indexes above, a paginated `GROUP BY` over ~300k rows is well within budget; denormalised counters are a documented shortcut we consciously did not take.
- **Random rating is a `NULL`** rather than a separate mode column: no contradictory states possible.
- **Audit fields on every mutable table**; `change_log` adds the per-record history for the bonus.

## 5. REST API

Base path `/api`. JSON in/out. Auth via PHP session cookie. Uniform error body:

```json
{ "error": { "code": "validation_failed", "message": "Validation failed", "fields": { "text": "Required" } } }
```

| Method | Path | Success | Errors |
|---|---|---|---|
| POST | `/api/auth/login` `{username,password}` | 200 `{user}` | 401 |
| POST | `/api/auth/logout` | 204 | |
| GET | `/api/auth/me` | 200 `{user}` | 401 |
| GET | `/api/products?search&page&per_page&sort&dir` | 200 `{data:[{sku,title,image,landing_count,testimonial_count}],meta:{page,per_page,total}}` | 400 |
| GET | `/api/products/{sku}/landings` | 200 `{data:[{id,country,is_master,url,title,status,testimonial_count,inherits_from_master}]}` | 404 |
| GET | `/api/landings/{id}/testimonials` | 200 `{data:[…],meta:{inherited,source_landing_id}}` | 404 |
| POST | `/api/landings/{id}/testimonials` | 201 `{testimonial}` | 404, 422 |
| POST | `/api/landings/{id}/testimonials/reorder` `{ids}` | 204 | 404, 422 |
| POST | `/api/landings/{id}/testimonials/copy` `{from_landing_id,mode:replace\|append}` | 200 `{copied}` | 404, 422 |
| POST | `/api/landings/{id}/testimonials/bulk` `{ids,action}` | 200 `{affected}` | 404, 422 |
| GET | `/api/testimonials/{id}` | 200 `{testimonial, images[], change_log[]}` | 404 |
| PATCH | `/api/testimonials/{id}` | 200 `{testimonial}` | 404, 422 |
| DELETE | `/api/testimonials/{id}` | 204 | 404 |
| POST | `/api/testimonials/{id}/images` (multipart, 1..n files) | 201 `{images:[…]}` | 404, 413, 415, 422 |
| POST | `/api/testimonials/{id}/images/reorder` `{ids}` | 204 | 404, 422 |
| DELETE | `/api/images/{id}` | 204 | 404 |
| POST | `/api/landings/sync` | 200 `{run:{added,updated,removed,duration_ms}}` | 502 upstream, 409 already running |
| GET | `/api/sync/last` | 200 `{run}` | 404 |
| GET | `/api/ai/providers` | 200 `{data:[…]}` | |
| POST | `/api/ai/translate` `{provider,text,target_country}` | 200 `{text}` | 422 |
| POST | `/api/ai/author-name` `{provider,country,gender}` | 200 `{name}` | 422 |
| GET | `/media/{filename}` | 200 image stream | 404 |
| GET | `/api/health` | 200 `{status:"ok",db:true}` | 503 |

Rules:

- `per_page` ≤ 100, default 20. `sort` ∈ `{sku,title,landings,testimonials}`, whitelisted server-side. `search` matches `parent_sku LIKE %q%` OR `title/description LIKE %q%`.
- Testimonial payload: `author_name` (required, ≤128), `text` (required, ≤2000), `rating` (`1..5` or `null`), `gender` (enum), `url` (optional, valid `http(s)` URL, ≤512), `is_active` (bool), `sort_order` (int ≥0, default = max+1).
- Every testimonial response includes `rating_display`: the stored rating, or a fresh random value in `[4.0, 5.0]` (one decimal) when `rating` is `null`.
- Uploads: max 5 MB per file (configurable), MIME sniffed with `finfo` and cross-checked with `getimagesize`; extension derived from detected type; stored as `storage/uploads/{uuid}.{ext}` with `{uuid}_thumb.{ext}` (300 px longest side). Served by `/media/{filename}` which validates the filename against `^[0-9a-f-]{36}(_thumb)?\.(jpg|png|webp)$` before touching disk.
- CSRF: session cookie `SameSite=Lax; HttpOnly`; all non-GET `/api` requests must carry `X-Requested-With: XMLHttpRequest` else 403.
- Unauthenticated `/api/*` (except `login`, `health`) → 401; the SPA redirects to `#/login`.
- Sync: acquires a DB-level lock (`GET_LOCK`) to prevent concurrent runs; pages through upstream with `limit=1000`; one transaction: upsert landings by id → soft-delete missing ones → upsert products from masters → record `sync_runs`. Upstream errors roll back and return 502.

## 6. Backend architecture

```
public/
  index.php          front controller: load config, build container, dispatch
  .htaccess          RewriteRule . index.php  (XAMPP/LAMP)
  index.html         SPA shell
  assets/css/app.css assets/js/…
src/
  Http/              Router, Request, Response/JsonResponse, Middleware/{Auth,RequireXhr,JsonErrors}, Controller/*
  Application/       ProductSearchService, LandingSyncService, TestimonialService, ImageService,
                     AiService, ChangeLogger, RatingResolver
  Domain/            Testimonial, TestimonialImage, Landing (value objects / DTOs)
                     Validation/{TestimonialValidator, ImageValidator, ValidationResult}
                     Ai/{AiProviderInterface, OpenAiProvider, GeminiProvider, ClaudeProvider, ProviderRegistry}
                     Exception/{NotFound, Validation, Unauthorized, Conflict, Upstream}
  Infrastructure/    Db/PdoFactory, Repository/{Product,Landing,Testimonial,Image,ChangeLog,SyncRun,User}Repository,
                     Upstream/{LandingsApiClientInterface, CurlLandingsApiClient, FixtureLandingsApiClient},
                     Storage/{ImageStorage (GD)}, Auth/SessionAuth
  Container.php      ~40-line closure-based DI container
config/config.php    reads .env (vlucas-style parser written by us, no dependency)
database/            schema.sql, seed.sql, seed-large.php
storage/uploads/     git-ignored, volume in Docker/Fly
tests/               Unit/, Integration/, Api/, e2e/ (Playwright)
```

Principles: controllers are thin (parse → service → respond); services own transactions and change-logging; repositories hold all SQL; Domain has no I/O. PSR-4 autoload via Composer; `composer.json` has **no `require`**, only `require-dev` (phpunit, phpstan, php-cs-fixer). The ZIP includes a committed `vendor/autoload.php` generated with `--no-dev` so XAMPP users need no Composer.

## 7. Frontend

Single `index.html` with Bootstrap 5.3 and jQuery 3.7 from CDN, hash router.

- Routes: `#/login`, `#/products` (search, sort headers, pagination), `#/products/{sku}` (country cards/tabs with counts + "inherits from EN" badge), `#/landings/{id}` (testimonial list + form modal + image uploader).
- Modules: `api.js` (fetch wrapper adding the XHR header, maps errors to toasts and field errors), `router.js`, `views/*.js`, `components/{testimonialForm, imageUploader, confirmDialog, saveStatus, aiAssist}.js`.
- Saving: explicit Save button in the form modal; inline toggles (active) save immediately with an inline status `Saving… → Saved ✓ / Failed ✗ (Retry)`; every failed request produces a visible toast with the server message. No silent failures.
- Delete always goes through a confirm dialog (testimonials, images, bulk).
- Sync button in the navbar shows last run and result.
- Look: DFVU tokens — primary `#0060B8`, secondary `#FFD721`, dark `#282835`, text `#3E3E3E`, light `#F4F4F4`, accent `#EE834D`; Montserrat (headings, 600/800) and Roboto (body). Dark navbar with "DFVU · Testimonials Manager" wordmark. Admin tool aesthetics, responsive down to tablet.

## 8. AI mock providers

`AiProviderInterface { translate(string $text, string $targetCountry): string; authorName(string $country, string $gender): string; name(): string }`. Three implementations (`OpenAiProvider`, `GeminiProvider`, `ClaudeProvider`) share an abstract base that produces `[CC] …` output; each subclass only differs in `name()` and a per-provider sample-name table, so swapping in a real HTTP client later touches one class. `ProviderRegistry` maps ids → instances; the UI offers a provider dropdown in the testimonial form with "Translate from EN" and "Suggest name" buttons that fill the fields (still requiring an explicit Save).

## 9. Testing strategy

| Level | Tool | Runs where | Covers |
|---|---|---|---|
| Unit | PHPUnit 10 | `app` container, CI | validators, router, AI providers, sync diff, rating resolver, change-log diff, container |
| Integration | PHPUnit + MySQL | `app`+`db`, CI service | repositories, sync upsert idempotence & id stability, search query/paging/sort, image storage |
| API | PHPUnit HTTP (curl against `app`) | compose, CI | status codes, auth/CSRF guards, error shapes, upload rejections |
| E2E | Playwright (TS) | `playwright` container, CI | login → search → country → create testimonial + image → edit → reorder → delete; visible save error |
| Static | phpstan lvl 6, php-cs-fixer PSR-12 | CI | |

TDD: every phase starts with failing tests. Fixtures: a captured upstream response (`tests/fixtures/landings.json`) drives sync tests; a mutated copy (renamed URL, one landing removed, one added) proves upsert semantics.

## 10. Tooling, CI, hosting

- **Docker**: `docker-compose.yml` with `app` (php:8.2-apache + gd + pdo_mysql), `db` (mysql:8, utf8mb4), `playwright` (profile `e2e`). `Makefile`: `up, down, sh, test, unit, integration, lint, stan, e2e, seed, seed-large, sync, zip`.
- **GitHub**: public repo `mitjafortuna/testimonials-manager`, `main` protected by CI; each phase on a `phase/NN-name` branch merged with a merge commit.
- **CI** (`.github/workflows/ci.yml`): lint → phpstan → unit → integration/API with MySQL service → e2e → Playwright report artifact.
- **Release** (`release.yml`, on `v*` tags): validate `schema.sql` + `seed.sql` on a fresh MySQL, build `testimonials-manager-<tag>.zip` (excludes `.git`, `tests`, `node_modules`, `storage/uploads/*`; includes `vendor/` no-dev), attach to GitHub Release.
- **Deploy** (`deploy.yml`, on push to `main`): `flyctl deploy`. Fly setup: app from the same Dockerfile, MySQL 8 as a second Fly app (`mysql:8` image + volume, internal network only), volume at `/var/www/storage`, secrets for `.env` values, release command applies schema+seed if the DB is empty.

## 11. Documentation deliverables

- `README.md`: what it is, live link + demo credentials, quick start (Docker), XAMPP/LAMP install, architecture overview with a diagram, schema rationale, API reference, testing, deliberate shortcuts & known limitations, time log, "how this was built" (spec/TDD/AI-assisted).
- `docs/adr/000N-*.md`: id-as-PK, read-time inheritance, media passthrough, zero runtime deps, computed counts.
- `docs/questions-for-dfvu.md`: questions the owner emails to `kadrovska@dfvu.org`.
- `docs/time-log.md`: running log per phase.
- `docs/superpowers/plans/`: implementation plan.

## 12. Phases

Each phase: branch → failing tests → implementation → green CI → merge commit → time-log entry. The app is demoable after every phase.

| # | Phase | Ships |
|---|---|---|
| 0 | Scaffold | GitHub repo, Docker, Makefile, composer dev tooling, CI green with a smoke test, README skeleton, time log |
| 1 | Schema + seed | `schema.sql`, `seed.sql`, `seed-large.php`, integration test harness, schema validation in CI |
| 2 | HTTP core | container, router, request/response, error + XHR middleware, `/api/health`, SPA shell served |
| 3 | Sync | upstream client + fixture, `LandingSyncService`, `POST /api/landings/sync`, `GET /api/sync/last`, sync button |
| 4 | Products + countries | search/paging/sort, landings with counts + inheritance flag, first two views |
| 5 | Testimonials CRUD | validator, endpoints, read-time inheritance, rating resolver, form modal, unambiguous save, delete confirm |
| 6 | Images | upload validation, UUID storage, thumbnails, `/media` passthrough, uploader UI, delete + order |
| 7 | Login | seed user, `password_hash`, session auth, guards, login view |
| 8 | E2E | Playwright happy path + error visibility in CI |
| 9 | Deploy | Fly.io live link, deploy workflow, demo credentials in README |
| 10 | AI mocks | interface, 3 providers, registry, endpoints, form buttons |
| 11 | Drag & drop | testimonials + images reorder with one call |
| 12 | Copy between countries | replace/append modes with preview |
| 13 | Bulk actions | select → activate/deactivate/delete |
| 14 | Change log | logger on all mutations, per-record history panel |
| 15 | Image processing | downscale to max 1600 px, WebP conversion, optional aspect crop |
| 16 | Release | ZIP workflow, final README/time log, submission checklist |

Phases 10–15 are cut from the bottom if time runs short; phases 0–9 are the committed core.

## 13. Deliberate shortcuts (to be stated in the README)

- File-based PHP sessions instead of a DB session store.
- Media served through PHP, not Apache — portability over throughput.
- No rate limiting, no roles, no password reset (out of scope per brief).
- Counts computed per request rather than cached.
- Upstream sync is full-table, not incremental — 170 rows today, thousands at most.
- Playwright covers one happy path, not every UI branch.

## 14. Open questions for DFVU (owner to email)

1. When a landing disappears from `GET /landings`, should its testimonials be hidden, kept as-is, or deleted?
2. For "random" ratings — is the random value resolved by the landing page at render time, or should our API return a resolved value? (We do both: store `NULL`, return `rating_display`.)
3. Are `title`/`description` from localised landings needed in the admin, or only the master's?
4. Preferred thumbnail size / aspect ratio for the list view?
