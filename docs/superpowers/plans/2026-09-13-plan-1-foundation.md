# Testimonials Manager — Plan 1: Foundation (Phases 0–3)

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Stand up the repo, Docker, CI, database schema/seed, the framework-less HTTP core, and landing synchronisation from the upstream API — the base every later phase builds on.

**Architecture:** PHP 8.1+ front controller (`public/index.php`) → hand-written `Kernel`/`Router`/middleware → thin controllers → services (own transactions) → PDO repositories. Frontend is a single `index.html` SPA (Bootstrap 5 + jQuery from CDN) talking only to `/api/*`. Everything runs in Docker locally and via `setup-php` + MySQL service in GitHub Actions.

**Tech Stack:** PHP 8.2 (8.1-compatible syntax), MySQL 8, PDO, PHPUnit 10, phpstan, php-cs-fixer, Docker Compose, GitHub Actions, Bootstrap 5.3, jQuery 3.7.

**Spec:** `docs/superpowers/specs/2026-09-13-testimonials-manager-design.md`

## Global Constraints

- PHP `>=8.1`, **no framework**, no runtime Composer packages (`composer.json` `require` lists only `php` and extensions).
- MySQL 8 / MariaDB 10.4+, PDO with prepared statements only, `utf8mb4_unicode_ci` on every table.
- Must run on XAMPP/LAMP: `.htaccess` routing, no build step, committed `vendor/` in the release ZIP.
- REST + JSON, real status codes; error body `{"error":{"code","message","fields"}}`.
- Upstream URL + key only in `.env` (`LANDINGS_API_URL`, `LANDINGS_API_KEY`).
- Sync = upsert by upstream `id`; never `TRUNCATE`; missing landings are soft-deleted via `removed_at`.
- All commits end with the attribution lines:
  ```
  Co-Authored-By: Claude Opus 5 <noreply@anthropic.com>
  Claude-Session: https://claude.ai/code/session_014Ux1NSdiKLKNGQHc4VFjuf
  ```
- Each phase is a branch `phase/NN-name`, merged into `main` with `--no-ff`; every phase appends a row to `docs/time-log.md`.
- All commands run inside Docker: `docker compose run --rm app <cmd>` (Makefile wraps these). Never assume a host PHP.

## File structure (this plan)

```
.editorconfig .gitignore .env.example Makefile Dockerfile docker-compose.yml
composer.json phpunit.xml phpstan.neon .php-cs-fixer.dist.php
docker/apache.conf docker/mysql-init/01-test-db.sql
.github/workflows/ci.yml
README.md docs/time-log.md docs/questions-for-dfvu.md
config/config.php            reads .env → array
config/container.php         wires container
config/routes.php            registers routes
database/schema.sql database/seed.sql database/build-seed.php database/seed-large.php
public/index.php public/.htaccess public/index.html public/assets/css/app.css public/assets/js/{api,toast,router,app}.js
src/Support/Env.php
src/Container.php
src/Http/{Request,Response,Router,RouteMatch,Kernel,MiddlewareInterface}.php
src/Http/Middleware/RequireXhrMiddleware.php
src/Http/Controller/{HealthController,HomeController,SyncController}.php
src/Domain/Exception/{HttpException,NotFoundException,ValidationException,UnauthorizedException,ForbiddenException,ConflictException,UpstreamException}.php
src/Infrastructure/Db/PdoFactory.php
src/Infrastructure/Upstream/{LandingsApiClientInterface,CurlLandingsApiClient,FixtureLandingsApiClient}.php
src/Infrastructure/Repository/{ProductRepository,LandingRepository,SyncRunRepository}.php
src/Application/LandingSyncService.php
tests/bootstrap.php
tests/Unit/... tests/Integration/DatabaseTestCase.php tests/Api/ApiTestCase.php
tests/fixtures/landings.json
```

---

## Phase 0 — Scaffold

### Task 1: Repository skeleton, Docker, Composer tooling, smoke test

**Files:**
- Create: `.gitignore`, `.editorconfig`, `.env.example`, `composer.json`, `phpunit.xml`, `phpstan.neon`, `.php-cs-fixer.dist.php`, `Dockerfile`, `docker/apache.conf`, `docker/mysql-init/01-test-db.sql`, `docker-compose.yml`, `Makefile`, `tests/bootstrap.php`, `tests/Unit/SmokeTest.php`, `src/Support/Env.php`, `tests/Unit/Support/EnvTest.php`, `public/index.php` (placeholder), `public/.htaccess`

**Interfaces:**
- Produces: `App\Support\Env::load(string $path): void` (sets `$_ENV`/`putenv` without overriding existing vars), `App\Support\Env::get(string $key, ?string $default = null): ?string`.

- [ ] **Step 1: Create branch and static config files**

```bash
git checkout -b phase/00-scaffold
```

`.gitignore`:
```
/vendor/
/.env
/storage/uploads/*
!/storage/uploads/.gitkeep
/tests/e2e/node_modules/
/tests/e2e/playwright-report/
/tests/e2e/test-results/
/.phpunit.cache/
/.php-cs-fixer.cache
/build/
.DS_Store
```

`.editorconfig`:
```
root = true
[*]
charset = utf-8
end_of_line = lf
insert_final_newline = true
indent_style = space
indent_size = 4
trim_trailing_whitespace = true
[*.{js,css,html,yml,yaml,json,md}]
indent_size = 2
[Makefile]
indent_style = tab
```

`.env.example`:
```
APP_ENV=dev
APP_DEBUG=1
DB_HOST=db
DB_PORT=3306
DB_NAME=testimonials
DB_USER=app
DB_PASS=app
TEST_DB_NAME=testimonials_test
UPLOAD_DIR=storage/uploads
UPLOAD_MAX_BYTES=5242880
LANDINGS_API_URL=https://develop.s-mania.com/it/testimonials/landings-api.php
LANDINGS_API_KEY=replace-me
SESSION_NAME=tm_session
API_BASE_URL=http://localhost
```

`composer.json`:
```json
{
  "name": "mitjafortuna/testimonials-manager",
  "description": "Admin for managing landing-page testimonials per product and country (DFVU assignment)",
  "type": "project",
  "license": "MIT",
  "require": {
    "php": ">=8.1",
    "ext-pdo": "*",
    "ext-pdo_mysql": "*",
    "ext-gd": "*",
    "ext-curl": "*",
    "ext-fileinfo": "*",
    "ext-json": "*"
  },
  "require-dev": {
    "phpunit/phpunit": "^10.5",
    "phpstan/phpstan": "^1.11",
    "friendsofphp/php-cs-fixer": "^3.58"
  },
  "autoload": { "psr-4": { "App\\": "src/" } },
  "autoload-dev": { "psr-4": { "Tests\\": "tests/" } },
  "scripts": {
    "test": "phpunit",
    "test:unit": "phpunit --testsuite Unit",
    "test:integration": "phpunit --testsuite Integration",
    "test:api": "phpunit --testsuite Api",
    "lint": "php-cs-fixer fix --dry-run --diff",
    "lint:fix": "php-cs-fixer fix",
    "stan": "phpstan analyse --memory-limit=512M"
  },
  "config": { "sort-packages": true, "optimize-autoloader": true }
}
```

`phpunit.xml`:
```xml
<?xml version="1.0" encoding="UTF-8"?>
<phpunit xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"
         xsi:noNamespaceSchemaLocation="vendor/phpunit/phpunit/phpunit.xsd"
         bootstrap="tests/bootstrap.php" colors="true" cacheDirectory=".phpunit.cache"
         failOnWarning="true" failOnRisky="true">
  <testsuites>
    <testsuite name="Unit"><directory>tests/Unit</directory></testsuite>
    <testsuite name="Integration"><directory>tests/Integration</directory></testsuite>
    <testsuite name="Api"><directory>tests/Api</directory></testsuite>
  </testsuites>
  <source><include><directory suffix=".php">src</directory></include></source>
</phpunit>
```

`phpstan.neon`:
```neon
parameters:
  level: 6
  paths: [src, tests, config, database]
  tmpDir: build/phpstan
```

`.php-cs-fixer.dist.php`:
```php
<?php

declare(strict_types=1);

$finder = PhpCsFixer\Finder::create()->in([__DIR__ . '/src', __DIR__ . '/tests', __DIR__ . '/config', __DIR__ . '/database', __DIR__ . '/public']);

return (new PhpCsFixer\Config())
    ->setRiskyAllowed(true)
    ->setRules([
        '@PSR12' => true,
        'declare_strict_types' => true,
        'array_syntax' => ['syntax' => 'short'],
        'no_unused_imports' => true,
        'ordered_imports' => true,
        'single_quote' => true,
        'trailing_comma_in_multiline' => true,
    ])
    ->setFinder($finder);
```

- [ ] **Step 2: Docker files**

`docker/apache.conf`:
```apache
<VirtualHost *:80>
    DocumentRoot /var/www/public
    <Directory /var/www/public>
        Options -Indexes +FollowSymLinks
        AllowOverride All
        Require all granted
    </Directory>
    ErrorLog ${APACHE_LOG_DIR}/error.log
    CustomLog ${APACHE_LOG_DIR}/access.log combined
</VirtualHost>
```

`docker/mysql-init/01-test-db.sql`:
```sql
CREATE DATABASE IF NOT EXISTS testimonials_test CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
GRANT ALL PRIVILEGES ON testimonials_test.* TO 'app'@'%';
FLUSH PRIVILEGES;
```

`Dockerfile`:
```dockerfile
FROM php:8.2-apache

ARG COMPOSER_FLAGS="--no-dev"

RUN apt-get update \
 && apt-get install -y --no-install-recommends git unzip libpng-dev libjpeg-dev libwebp-dev libfreetype6-dev libzip-dev default-mysql-client \
 && docker-php-ext-configure gd --with-jpeg --with-webp --with-freetype \
 && docker-php-ext-install -j"$(nproc)" gd pdo_mysql \
 && a2enmod rewrite headers \
 && rm -rf /var/lib/apt/lists/*

COPY docker/apache.conf /etc/apache2/sites-available/000-default.conf
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /var/www
COPY composer.json ./
RUN composer install ${COMPOSER_FLAGS} --no-interaction --no-progress --no-scripts --no-autoloader || true

COPY . .
RUN composer dump-autoload --optimize \
 && mkdir -p storage/uploads \
 && chown -R www-data:www-data storage

EXPOSE 80
```

`docker-compose.yml`:
```yaml
services:
  app:
    build:
      context: .
      args:
        COMPOSER_FLAGS: ""
    ports: ["8080:80"]
    volumes: [".:/var/www"]
    env_file: .env
    environment:
      API_BASE_URL: http://localhost
    depends_on:
      db:
        condition: service_healthy

  db:
    image: mysql:8.0
    command: --character-set-server=utf8mb4 --collation-server=utf8mb4_unicode_ci
    environment:
      MYSQL_ROOT_PASSWORD: root
      MYSQL_DATABASE: testimonials
      MYSQL_USER: app
      MYSQL_PASSWORD: app
    ports: ["3306:3306"]
    volumes:
      - dbdata:/var/lib/mysql
      - ./docker/mysql-init:/docker-entrypoint-initdb.d:ro
    healthcheck:
      test: ["CMD", "mysqladmin", "ping", "-h", "localhost", "-proot"]
      interval: 5s
      timeout: 3s
      retries: 30

  playwright:
    image: mcr.microsoft.com/playwright:v1.47.2-jammy
    profiles: ["e2e"]
    working_dir: /e2e
    volumes: ["./tests/e2e:/e2e"]
    environment:
      BASE_URL: http://app
    depends_on: [app]

volumes:
  dbdata: {}
```

`Makefile`:
```makefile
COMPOSE = docker compose
RUN     = $(COMPOSE) run --rm app

.PHONY: up down build sh install test unit integration api lint lint-fix stan e2e seed seed-large sync zip

up:            ## start app + db
	$(COMPOSE) up -d app
down:
	$(COMPOSE) down
build:
	$(COMPOSE) build
sh:
	$(COMPOSE) exec app bash
install:       ## composer install inside the container
	$(RUN) composer install
test:          ## all PHPUnit suites (needs `make up`)
	$(COMPOSE) exec app composer test
unit:
	$(RUN) composer test:unit
integration:
	$(RUN) composer test:integration
api:
	$(COMPOSE) exec app composer test:api
lint:
	$(RUN) composer lint
lint-fix:
	$(RUN) composer lint:fix
stan:
	$(RUN) composer stan
seed:          ## load schema + seed into the dev database
	$(COMPOSE) exec -T db mysql -uroot -proot testimonials < database/schema.sql
	$(COMPOSE) exec -T db mysql -uroot -proot testimonials < database/seed.sql
seed-large:    ## generate a large demo dataset (300 products)
	$(COMPOSE) exec app php database/seed-large.php
sync:          ## run landing sync from the CLI
	$(COMPOSE) exec app php bin/sync.php
e2e:
	$(COMPOSE) --profile e2e run --rm playwright sh -c "npm ci && npx playwright test"
zip:
	./scripts/build-zip.sh
```

`public/.htaccess`:
```apache
DirectoryIndex index.php
Options -Indexes
<IfModule mod_rewrite.c>
    RewriteEngine On
    RewriteCond %{REQUEST_FILENAME} !-f
    RewriteRule ^ index.php [QSA,L]
</IfModule>
<IfModule mod_headers.c>
    Header set X-Content-Type-Options "nosniff"
    Header set X-Frame-Options "SAMEORIGIN"
</IfModule>
```

`public/index.php` (placeholder until Task 8):
```php
<?php

declare(strict_types=1);

header('Content-Type: application/json');
echo json_encode(['status' => 'scaffold']);
```

- [ ] **Step 3: Test bootstrap and failing Env test**

`tests/bootstrap.php`:
```php
<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

\App\Support\Env::load(dirname(__DIR__) . '/.env');
```

`tests/Unit/SmokeTest.php`:
```php
<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

final class SmokeTest extends TestCase
{
    public function testPhpVersionIsAtLeast81(): void
    {
        self::assertTrue(version_compare(PHP_VERSION, '8.1.0', '>='));
    }
}
```

`tests/Unit/Support/EnvTest.php`:
```php
<?php

declare(strict_types=1);

namespace Tests\Unit\Support;

use App\Support\Env;
use PHPUnit\Framework\TestCase;

final class EnvTest extends TestCase
{
    private string $file;

    protected function setUp(): void
    {
        $this->file = tempnam(sys_get_temp_dir(), 'env');
        file_put_contents($this->file, "# comment\nFOO_TEST=bar\nQUOTED=\"hello world\"\nEMPTY=\n");
        putenv('PRESET_TEST=keep');
        file_put_contents($this->file, "PRESET_TEST=override\n", FILE_APPEND);
    }

    protected function tearDown(): void
    {
        unlink($this->file);
        putenv('FOO_TEST');
        putenv('QUOTED');
        putenv('EMPTY');
        putenv('PRESET_TEST');
    }

    public function testLoadsKeyValuePairs(): void
    {
        Env::load($this->file);
        self::assertSame('bar', Env::get('FOO_TEST'));
        self::assertSame('hello world', Env::get('QUOTED'));
        self::assertSame('', Env::get('EMPTY'));
    }

    public function testDoesNotOverrideExistingVariables(): void
    {
        Env::load($this->file);
        self::assertSame('keep', Env::get('PRESET_TEST'));
    }

    public function testMissingFileIsIgnored(): void
    {
        Env::load('/nonexistent/.env');
        self::assertSame('dflt', Env::get('NOPE_TEST', 'dflt'));
    }
}
```

- [ ] **Step 4: Build the image, install deps, run tests — expect EnvTest to fail**

```bash
cp .env.example .env
make build && make install
make unit
```
Expected: `SmokeTest` passes; `EnvTest` errors with `Class "App\Support\Env" not found`.

- [ ] **Step 5: Implement Env**

`src/Support/Env.php`:
```php
<?php

declare(strict_types=1);

namespace App\Support;

final class Env
{
    public static function load(string $path): void
    {
        if (!is_file($path)) {
            return;
        }
        $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) {
                continue;
            }
            [$key, $value] = explode('=', $line, 2);
            $key = trim($key);
            $value = trim($value);
            if (strlen($value) >= 2 && $value[0] === '"' && str_ends_with($value, '"')) {
                $value = substr($value, 1, -1);
            }
            if (getenv($key) !== false) {
                continue;
            }
            putenv($key . '=' . $value);
            $_ENV[$key] = $value;
        }
    }

    public static function get(string $key, ?string $default = null): ?string
    {
        $value = getenv($key);
        return $value === false ? $default : $value;
    }
}
```

- [ ] **Step 6: Run unit tests, lint, stan — all green**

```bash
make unit && make lint && make stan
```
Expected: `OK (4 tests)`; php-cs-fixer reports no diff; phpstan `No errors`.

- [ ] **Step 7: Commit**

```bash
git add -A
git commit -m "chore: scaffold repo with Docker, Composer tooling and Env loader"
```
(append attribution lines from Global Constraints to every commit message)

### Task 2: GitHub repo, CI workflow

**Files:**
- Create: `.github/workflows/ci.yml`

- [ ] **Step 1: Create the public GitHub repository and push**

```bash
gh repo create mitjafortuna/testimonials-manager --public --source=. --description "Testimonials Manager — DFVU full-stack assignment (PHP 8, MySQL, vanilla JS)" --push
git push -u origin phase/00-scaffold
```

- [ ] **Step 2: CI workflow**

`.github/workflows/ci.yml`:
```yaml
name: CI
on:
  push:
    branches: [main, "phase/**"]
  pull_request:

jobs:
  test:
    runs-on: ubuntu-latest
    services:
      mysql:
        image: mysql:8.0
        env:
          MYSQL_ROOT_PASSWORD: root
          MYSQL_DATABASE: testimonials
          MYSQL_USER: app
          MYSQL_PASSWORD: app
        ports: ["3306:3306"]
        options: >-
          --health-cmd "mysqladmin ping -h localhost -proot"
          --health-interval 5s --health-timeout 3s --health-retries 30
    env:
      DB_HOST: 127.0.0.1
      DB_PORT: 3306
      DB_NAME: testimonials
      DB_USER: app
      DB_PASS: app
      TEST_DB_NAME: testimonials_test
      APP_ENV: test
      APP_DEBUG: "1"
      UPLOAD_DIR: storage/uploads
      UPLOAD_MAX_BYTES: "5242880"
      LANDINGS_API_URL: https://example.invalid/landings
      LANDINGS_API_KEY: test-key
      SESSION_NAME: tm_session
      API_BASE_URL: http://127.0.0.1:8080
    steps:
      - uses: actions/checkout@v4
      - uses: shivammathur/setup-php@v2
        with:
          php-version: "8.2"
          extensions: pdo_mysql, gd, curl, fileinfo, mbstring
          coverage: none
      - run: composer install --no-interaction --no-progress
      - run: composer lint
      - run: composer stan
      - run: composer test:unit
      - name: Create test database
        run: mysql -h127.0.0.1 -uroot -proot < docker/mysql-init/01-test-db.sql
      - run: composer test:integration
      - name: Start app server
        run: |
          mkdir -p storage/uploads
          mysql -h127.0.0.1 -uroot -proot testimonials < database/schema.sql || true
          mysql -h127.0.0.1 -uroot -proot testimonials < database/seed.sql || true
          (php -S 127.0.0.1:8080 -t public public/index.php > /tmp/php-server.log 2>&1 &)
          sleep 2
      - run: composer test:api
      - name: Server log on failure
        if: failure()
        run: cat /tmp/php-server.log
```

- [ ] **Step 3: Push and verify CI passes**

```bash
git add .github && git commit -m "ci: add GitHub Actions workflow (lint, stan, unit, integration, api)"
git push
gh run watch --exit-status
```
Expected: green. (Integration/Api suites are empty at this point — PHPUnit exits 0 with "No tests executed" only when `failOnEmptyTestSuite` is unset; it is unset in `phpunit.xml`.)

### Task 3: README skeleton, time log, questions for DFVU

**Files:**
- Create: `README.md`, `docs/time-log.md`, `docs/questions-for-dfvu.md`, `storage/uploads/.gitkeep`

- [ ] **Step 1: Write docs**

`README.md`:
```markdown
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
| `make e2e` | Playwright end-to-end tests |
| `make seed-large` | generate ~300 products × 20 countries × 50 testimonials |

## Architecture

See [docs/superpowers/specs/2026-09-13-testimonials-manager-design.md](docs/superpowers/specs/2026-09-13-testimonials-manager-design.md) for the full design and [docs/adr](docs/adr) for individual decisions. Sections on the schema, API, shortcuts and time spent are filled in as phases land.

## How this was built

Spec-driven and test-driven, with AI assistance (Claude Code) for planning, implementation and review. Every commit is co-authored; every phase started from failing tests.
```

`docs/time-log.md`:
```markdown
# Time log

Rough time spent per phase (owner + AI pairing). Requested by the assignment brief.

| Phase | Description | Time |
|---|---|---|
| — | Brainstorming, spec, plan | 2h |
| 0 | Scaffold, Docker, CI | |
```

`docs/questions-for-dfvu.md`:
```markdown
# Questions for DFVU (kadrovska@dfvu.org)

Sent on: _(fill in)_

1. When a landing disappears from `GET /landings`, should its testimonials be hidden, kept as-is, or deleted? (Current assumption: the landing is soft-deleted and hidden; testimonials are preserved.)
2. For "random" ratings — should the admin API return an already-resolved value, or does the landing page resolve it at render time? (Current assumption: we store `NULL` and return a `rating_display` resolved between 4.0 and 5.0 per response, so either consumer works.)
3. Are the localised `title`/`description` fields needed in the admin UI, or is the English master's text enough for the product list? (Current assumption: stored for every landing, searched on the master's.)
4. Is there a preferred thumbnail size or aspect ratio for the list view? (Current assumption: 300 px on the longest side, aspect preserved.)
```

- [ ] **Step 2: Commit, open PR, merge phase 0**

```bash
touch storage/uploads/.gitkeep
git add -A && git commit -m "docs: README skeleton, time log and questions for DFVU"
git push
gh pr create --title "Phase 0: scaffold" --body "Docker, Composer tooling, CI, docs skeleton.

🤖 Generated with [Claude Code](https://claude.com/claude-code)

https://claude.ai/code/session_014Ux1NSdiKLKNGQHc4VFjuf"
gh pr merge --merge --delete-branch
git checkout main && git pull
```

---

## Phase 1 — Schema + seed

### Task 4: `schema.sql` + integration test harness

**Files:**
- Create: `database/schema.sql`, `src/Infrastructure/Db/PdoFactory.php`, `tests/Integration/DatabaseTestCase.php`, `tests/Integration/SchemaTest.php`

**Interfaces:**
- Produces: `App\Infrastructure\Db\PdoFactory::create(array{host:string,port:int,name:string,user:string,pass:string} $db): \PDO` (utf8mb4, `ERRMODE_EXCEPTION`, `FETCH_ASSOC`, `EMULATE_PREPARES=false`).
- Produces: `Tests\Integration\DatabaseTestCase` with `protected static \PDO $pdo`, `loadSql(string $file)`, `truncateAll()`; every test method starts with empty tables.

- [ ] **Step 1: Branch**

```bash
git checkout -b phase/01-schema
```

- [ ] **Step 2: Failing schema tests**

`tests/Integration/DatabaseTestCase.php`:
```php
<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Infrastructure\Db\PdoFactory;
use App\Support\Env;
use PHPUnit\Framework\TestCase;

abstract class DatabaseTestCase extends TestCase
{
    protected static \PDO $pdo;

    public static function setUpBeforeClass(): void
    {
        self::$pdo = PdoFactory::create([
            'host' => Env::get('DB_HOST', '127.0.0.1'),
            'port' => (int) Env::get('DB_PORT', '3306'),
            'name' => Env::get('TEST_DB_NAME', 'testimonials_test'),
            'user' => Env::get('DB_USER', 'app'),
            'pass' => Env::get('DB_PASS', 'app'),
        ]);
        self::loadSql(dirname(__DIR__, 2) . '/database/schema.sql');
    }

    protected function setUp(): void
    {
        self::truncateAll();
    }

    protected static function loadSql(string $file): void
    {
        $sql = file_get_contents($file);
        if ($sql === false) {
            throw new \RuntimeException("Cannot read $file");
        }
        self::$pdo->exec($sql);
        // Drain any remaining result sets so the connection is reusable.
        while (self::$pdo->query('SELECT 1')->nextRowset()) {
        }
    }

    protected static function truncateAll(): void
    {
        self::$pdo->exec('SET FOREIGN_KEY_CHECKS=0');
        $tables = self::$pdo->query('SHOW TABLES')->fetchAll(\PDO::FETCH_COLUMN);
        foreach ($tables as $table) {
            self::$pdo->exec("TRUNCATE TABLE `$table`");
        }
        self::$pdo->exec('SET FOREIGN_KEY_CHECKS=1');
    }

    /** @param array<string,mixed> $row */
    protected static function insert(string $table, array $row): int
    {
        $cols = implode(',', array_map(fn ($c) => "`$c`", array_keys($row)));
        $marks = implode(',', array_fill(0, count($row), '?'));
        self::$pdo->prepare("INSERT INTO `$table` ($cols) VALUES ($marks)")->execute(array_values($row));
        return (int) self::$pdo->lastInsertId();
    }
}
```

`tests/Integration/SchemaTest.php`:
```php
<?php

declare(strict_types=1);

namespace Tests\Integration;

final class SchemaTest extends DatabaseTestCase
{
    private const TABLES = ['users', 'products', 'landings', 'testimonials', 'testimonial_images', 'change_log', 'sync_runs'];

    public function testAllTablesExistWithUtf8mb4Unicode(): void
    {
        $stmt = self::$pdo->prepare('SELECT TABLE_NAME, TABLE_COLLATION, ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE()');
        $stmt->execute();
        $rows = [];
        foreach ($stmt->fetchAll() as $r) {
            $rows[$r['TABLE_NAME']] = $r;
        }
        foreach (self::TABLES as $t) {
            self::assertArrayHasKey($t, $rows, "table $t missing");
            self::assertSame('utf8mb4_unicode_ci', $rows[$t]['TABLE_COLLATION'], $t);
            self::assertSame('InnoDB', $rows[$t]['ENGINE'], $t);
        }
    }

    public function testSchemaIsIdempotent(): void
    {
        self::loadSql(dirname(__DIR__, 2) . '/database/schema.sql');
        self::assertTrue(true);
    }

    public function testDeletingTestimonialCascadesToImages(): void
    {
        [$landingId, $testimonialId] = $this->seedLandingAndTestimonial();
        self::insert('testimonial_images', ['testimonial_id' => $testimonialId, 'filename' => 'a.jpg', 'thumb_filename' => 'a_thumb.jpg', 'mime' => 'image/jpeg', 'size_bytes' => 10, 'width' => 1, 'height' => 1, 'sort_order' => 0]);
        self::$pdo->exec("DELETE FROM testimonials WHERE id = $testimonialId");
        self::assertSame(0, (int) self::$pdo->query('SELECT COUNT(*) FROM testimonial_images')->fetchColumn());
    }

    public function testDeletingLandingWithTestimonialsIsRestricted(): void
    {
        [$landingId] = $this->seedLandingAndTestimonial();
        $this->expectException(\PDOException::class);
        self::$pdo->exec("DELETE FROM landings WHERE id = $landingId");
    }

    public function testRatingOutsideRangeIsRejected(): void
    {
        [$landingId] = $this->seedLandingAndTestimonial();
        $this->expectException(\PDOException::class);
        self::insert('testimonials', ['landing_id' => $landingId, 'author_name' => 'x', 'text' => 'y', 'rating' => 6, 'gender' => 'unisex', 'sort_order' => 1]);
    }

    public function testStoresCyrillicGreekTurkish(): void
    {
        [$landingId] = $this->seedLandingAndTestimonial();
        $text = 'Отлично · Εξαιρετικό · Mükemmel · Čšž';
        $id = self::insert('testimonials', ['landing_id' => $landingId, 'author_name' => 'Đorđe', 'text' => $text, 'rating' => 5, 'gender' => 'male', 'sort_order' => 1]);
        self::assertSame($text, self::$pdo->query("SELECT text FROM testimonials WHERE id = $id")->fetchColumn());
    }

    /** @return array{0:int,1:int} */
    private function seedLandingAndTestimonial(): array
    {
        $productId = self::insert('products', ['parent_sku' => 'abforge', 'title' => 'AbForge', 'description' => 'd', 'image' => 'i']);
        self::insert('landings', ['id' => 61763, 'product_id' => $productId, 'country' => 'EN', 'is_master' => 1, 'url' => 'https://x/en', 'title' => 't', 'description' => 'd', 'image' => 'i', 'status' => 'READY', 'last_synced_at' => '2026-09-13 00:00:00']);
        $testimonialId = self::insert('testimonials', ['landing_id' => 61763, 'author_name' => 'A', 'text' => 'T', 'rating' => 5, 'gender' => 'female', 'sort_order' => 0]);
        return [61763, $testimonialId];
    }
}
```

- [ ] **Step 3: Run — expect failure (PdoFactory missing)**

```bash
make integration
```
Expected: error `Class "App\Infrastructure\Db\PdoFactory" not found`.

- [ ] **Step 4: Implement PdoFactory and schema.sql**

`src/Infrastructure/Db/PdoFactory.php`:
```php
<?php

declare(strict_types=1);

namespace App\Infrastructure\Db;

final class PdoFactory
{
    /** @param array{host:string,port:int,name:string,user:string,pass:string} $db */
    public static function create(array $db): \PDO
    {
        $dsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', $db['host'], $db['port'], $db['name']);
        return new \PDO($dsn, $db['user'], $db['pass'], [
            \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
            \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
            \PDO::ATTR_EMULATE_PREPARES => false,
            \PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci, time_zone = '+00:00'",
        ]);
    }
}
```

`database/schema.sql`:
```sql
-- Testimonials Manager — schema
-- MySQL 8 / MariaDB 10.4+. All timestamps are UTC.
SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

CREATE TABLE IF NOT EXISTS users (
  id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
  username      VARCHAR(64)  NOT NULL,
  password_hash VARCHAR(255) NOT NULL,
  display_name  VARCHAR(128) NOT NULL,
  created_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_users_username (username)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- One row per parent SKU; title/description/image are copied from the EN master landing on sync.
CREATE TABLE IF NOT EXISTS products (
  id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  parent_sku  VARCHAR(64)  NOT NULL,
  title       VARCHAR(255) NOT NULL DEFAULT '',
  description TEXT         NULL,
  image       VARCHAR(512) NULL,
  created_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_products_sku (parent_sku),
  KEY ix_products_title (title)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- id is the UPSTREAM landing id (stable sync key), not auto-increment.
-- removed_at marks landings that disappeared from the upstream feed; their testimonials are kept.
CREATE TABLE IF NOT EXISTS landings (
  id             INT UNSIGNED NOT NULL,
  product_id     INT UNSIGNED NOT NULL,
  country        CHAR(2)      NOT NULL,
  is_master      TINYINT(1)   NOT NULL DEFAULT 0,
  url            VARCHAR(512) NOT NULL,
  title          VARCHAR(255) NOT NULL DEFAULT '',
  description    TEXT         NULL,
  image          VARCHAR(512) NULL,
  status         VARCHAR(64)  NULL,
  removed_at     DATETIME     NULL,
  last_synced_at DATETIME     NOT NULL,
  created_at     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_landings_product_country (product_id, country),
  KEY ix_landings_country (country),
  KEY ix_landings_removed (removed_at),
  CONSTRAINT fk_landings_product FOREIGN KEY (product_id) REFERENCES products (id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- rating NULL means "random": resolved to 4.0–5.0 at display time.
CREATE TABLE IF NOT EXISTS testimonials (
  id          INT UNSIGNED     NOT NULL AUTO_INCREMENT,
  landing_id  INT UNSIGNED     NOT NULL,
  author_name VARCHAR(128)     NOT NULL,
  text        VARCHAR(2000)    NOT NULL,
  rating      TINYINT UNSIGNED NULL,
  gender      ENUM('male','female','unisex') NOT NULL DEFAULT 'unisex',
  url         VARCHAR(512)     NULL,
  is_active   TINYINT(1)       NOT NULL DEFAULT 1,
  sort_order  INT UNSIGNED     NOT NULL DEFAULT 0,
  created_at  DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at  DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  created_by  INT UNSIGNED     NULL,
  updated_by  INT UNSIGNED     NULL,
  PRIMARY KEY (id),
  KEY ix_testimonials_landing_sort (landing_id, sort_order),
  KEY ix_testimonials_landing_active (landing_id, is_active),
  CONSTRAINT chk_testimonials_rating CHECK (rating IS NULL OR rating BETWEEN 1 AND 5),
  CONSTRAINT fk_testimonials_landing FOREIGN KEY (landing_id) REFERENCES landings (id) ON DELETE RESTRICT,
  CONSTRAINT fk_testimonials_created_by FOREIGN KEY (created_by) REFERENCES users (id) ON DELETE SET NULL,
  CONSTRAINT fk_testimonials_updated_by FOREIGN KEY (updated_by) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Files live in storage/uploads/{filename}; filename is a generated UUID + extension.
CREATE TABLE IF NOT EXISTS testimonial_images (
  id             INT UNSIGNED      NOT NULL AUTO_INCREMENT,
  testimonial_id INT UNSIGNED      NOT NULL,
  filename       VARCHAR(64)       NOT NULL,
  thumb_filename VARCHAR(64)       NOT NULL,
  mime           VARCHAR(32)       NOT NULL,
  size_bytes     INT UNSIGNED      NOT NULL,
  width          SMALLINT UNSIGNED NOT NULL,
  height         SMALLINT UNSIGNED NOT NULL,
  sort_order     INT UNSIGNED      NOT NULL DEFAULT 0,
  created_at     DATETIME          NOT NULL DEFAULT CURRENT_TIMESTAMP,
  created_by     INT UNSIGNED      NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_images_filename (filename),
  KEY ix_images_testimonial_sort (testimonial_id, sort_order),
  CONSTRAINT fk_images_testimonial FOREIGN KEY (testimonial_id) REFERENCES testimonials (id) ON DELETE CASCADE,
  CONSTRAINT fk_images_created_by FOREIGN KEY (created_by) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS change_log (
  id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  entity_type VARCHAR(32)     NOT NULL,
  entity_id   INT UNSIGNED    NOT NULL,
  action      VARCHAR(32)     NOT NULL,
  changes     JSON            NULL,
  user_id     INT UNSIGNED    NULL,
  created_at  DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY ix_change_log_entity (entity_type, entity_id, created_at),
  CONSTRAINT fk_change_log_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS sync_runs (
  id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
  started_at    DATETIME     NOT NULL,
  finished_at   DATETIME     NULL,
  status        ENUM('running','ok','failed') NOT NULL DEFAULT 'running',
  added         INT UNSIGNED NOT NULL DEFAULT 0,
  updated       INT UNSIGNED NOT NULL DEFAULT 0,
  removed       INT UNSIGNED NOT NULL DEFAULT 0,
  error_message TEXT         NULL,
  PRIMARY KEY (id),
  KEY ix_sync_runs_started (started_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET FOREIGN_KEY_CHECKS = 1;
```

- [ ] **Step 5: Run integration tests — green**

```bash
make integration
```
Expected: `OK (6 tests)`. If `loadSql` fails on multi-statement execution, MySQL PDO supports it natively with `EMULATE_PREPARES=false` via `exec()`; the `nextRowset` drain handles the trailing result sets.

- [ ] **Step 6: Commit**

```bash
git add -A && git commit -m "feat(db): add schema.sql, PDO factory and integration test harness"
```

### Task 5: `seed.sql`, seed builder, large-dataset generator

**Files:**
- Create: `tests/fixtures/landings.json` (captured upstream response), `database/build-seed.php`, `database/seed.sql`, `database/seed-large.php`, `tests/Integration/SeedTest.php`

**Interfaces:**
- Produces: `tests/fixtures/landings.json` — raw upstream JSON `{data:[…170 rows…], meta:{…}}` used by sync tests in Task 12.
- Produces: seed user `admin` / password `admin123`.

- [ ] **Step 1: Capture the upstream fixture — OWNER ACTION**

The sandbox refuses to send the upstream API key. Ask the owner to run (from the repo root):
```
! curl -s -H "X-Api-Key: <key from .env>" "https://develop.s-mania.com/it/testimonials/landings-api.php?limit=1000" -o tests/fixtures/landings.json && python3 -c "import json;d=json.load(open('tests/fixtures/landings.json'));print(len(d['data']),'landings')"
```
Expected: `170 landings`. Do not proceed until the file exists.

- [ ] **Step 2: Failing seed test**

`tests/Integration/SeedTest.php`:
```php
<?php

declare(strict_types=1);

namespace Tests\Integration;

final class SeedTest extends DatabaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        self::loadSql(dirname(__DIR__, 2) . '/database/seed.sql');
    }

    public function testSeedContainsAdminUserWithHashedPassword(): void
    {
        $hash = self::$pdo->query("SELECT password_hash FROM users WHERE username = 'admin'")->fetchColumn();
        self::assertIsString($hash);
        self::assertTrue(password_verify('admin123', $hash));
    }

    public function testSeedContainsAllUpstreamLandings(): void
    {
        $fixture = json_decode((string) file_get_contents(dirname(__DIR__, 2) . '/tests/fixtures/landings.json'), true);
        self::assertSame(count($fixture['data']), (int) self::$pdo->query('SELECT COUNT(*) FROM landings')->fetchColumn());
        self::assertSame($fixture['meta']['products'], (int) self::$pdo->query('SELECT COUNT(*) FROM products')->fetchColumn());
        self::assertSame($fixture['meta']['products'], (int) self::$pdo->query('SELECT COUNT(*) FROM landings WHERE is_master = 1')->fetchColumn());
    }

    public function testSeedHasTestimonialsWithImagesOnSomeLandings(): void
    {
        self::assertGreaterThan(20, (int) self::$pdo->query('SELECT COUNT(*) FROM testimonials')->fetchColumn());
        self::assertGreaterThan(0, (int) self::$pdo->query('SELECT COUNT(*) FROM testimonials WHERE rating IS NULL')->fetchColumn(), 'some random ratings');
        self::assertGreaterThan(0, (int) self::$pdo->query('SELECT COUNT(DISTINCT landing_id) FROM testimonials t JOIN landings l ON l.id = t.landing_id WHERE l.is_master = 0')->fetchColumn(), 'some localised landings have own testimonials');
    }

    public function testSeedIsIdempotent(): void
    {
        self::loadSql(dirname(__DIR__, 2) . '/database/seed.sql');
        self::assertSame(1, (int) self::$pdo->query("SELECT COUNT(*) FROM users WHERE username = 'admin'")->fetchColumn());
    }
}
```

- [ ] **Step 3: Run — expect failure (seed.sql missing)**

```bash
make integration
```
Expected: `SeedTest` errors with `Cannot read .../database/seed.sql`.

- [ ] **Step 4: Seed builder (generates seed.sql from the fixture)**

`database/build-seed.php`:
```php
<?php

declare(strict_types=1);

/**
 * Regenerates database/seed.sql from tests/fixtures/landings.json.
 * Usage: php database/build-seed.php > database/seed.sql
 * Deterministic: uses a fixed RNG seed so the output is stable across runs.
 */

mt_srand(20260913);

$fixture = json_decode((string) file_get_contents(__DIR__ . '/../tests/fixtures/landings.json'), true, 512, JSON_THROW_ON_ERROR);
$rows = $fixture['data'];

$q = static fn (?string $v): string => $v === null ? 'NULL' : "'" . str_replace(["\\", "'"], ["\\\\", "\\'"], $v) . "'";

$out = [];
$out[] = '-- Testimonials Manager — demo seed. Generated by database/build-seed.php; safe to re-run (idempotent).';
$out[] = 'SET NAMES utf8mb4;';
$out[] = 'SET FOREIGN_KEY_CHECKS = 0;';
$out[] = "INSERT INTO users (id, username, password_hash, display_name) VALUES (1, 'admin', " . $q(password_hash('admin123', PASSWORD_DEFAULT)) . ", 'Demo Admin')\n  ON DUPLICATE KEY UPDATE password_hash = VALUES(password_hash), display_name = VALUES(display_name);";

// products from masters (fallback: first landing of the sku)
$bySku = [];
foreach ($rows as $r) {
    $bySku[$r['parent_sku']][] = $r;
}
$productId = 0;
$skuToId = [];
$productValues = [];
foreach ($bySku as $sku => $list) {
    $master = null;
    foreach ($list as $r) {
        if ($r['is_master']) {
            $master = $r;
        }
    }
    $master ??= $list[0];
    $skuToId[$sku] = ++$productId;
    $productValues[] = sprintf('(%d, %s, %s, %s, %s)', $productId, $q($sku), $q($master['title']), $q($master['description']), $q($master['image']));
}
$out[] = "INSERT INTO products (id, parent_sku, title, description, image) VALUES\n  " . implode(",\n  ", $productValues) . "\n  ON DUPLICATE KEY UPDATE title = VALUES(title), description = VALUES(description), image = VALUES(image);";

$landingValues = [];
foreach ($rows as $r) {
    $landingValues[] = sprintf('(%d, %d, %s, %d, %s, %s, %s, %s, %s, \'2026-09-13 12:00:00\')', $r['id'], $skuToId[$r['parent_sku']], $q($r['country']), $r['is_master'] ? 1 : 0, $q($r['url']), $q($r['title']), $q($r['description']), $q($r['image']), $q($r['status']));
}
$out[] = "INSERT INTO landings (id, product_id, country, is_master, url, title, description, image, status, last_synced_at) VALUES\n  " . implode(",\n  ", $landingValues) . "\n  ON DUPLICATE KEY UPDATE product_id = VALUES(product_id), url = VALUES(url), title = VALUES(title), description = VALUES(description), image = VALUES(image), status = VALUES(status), removed_at = NULL;";

// testimonials: every master gets 3–6, every third localised landing gets 1–3.
$names = [
    'EN' => [['James Miller', 'male'], ['Emily Clark', 'female'], ['Alex Morgan', 'unisex']],
    'SI' => [['Janez Novak', 'male'], ['Maja Kovač', 'female']],
    'IT' => [['Mario Rossi', 'male'], ['Giulia Bianchi', 'female']],
    'DE' => [['Lukas Schmidt', 'male'], ['Anna Müller', 'female']],
    'BG' => [['Георги Иванов', 'male'], ['Мария Петрова', 'female']],
    'GR' => [['Γιώργος Παπαδόπουλος', 'male'], ['Ελένη Νικολάου', 'female']],
    'TR' => [['Mehmet Yılmaz', 'male'], ['Ayşe Kaya', 'female']],
    'CZ' => [['Jan Novák', 'male'], ['Eva Dvořáková', 'female']],
];
$texts = [
    'Absolutely love it — arrived in two days and works exactly as described.',
    'Great value for money. My whole family uses it every day now.',
    'Solid build quality, easy setup. Would buy again.',
    'Not bad at all, though the manual could be clearer.',
    'Exceeded my expectations. Customer support was quick and friendly.',
];
$testimonialValues = [];
$imageValues = [];
$tid = 0;
$imgId = 0;
foreach ($rows as $i => $r) {
    $count = $r['is_master'] ? mt_rand(3, 6) : ($i % 3 === 0 ? mt_rand(1, 3) : 0);
    $pool = $names[$r['country']] ?? $names['EN'];
    for ($n = 0; $n < $count; $n++) {
        [$name, $gender] = $pool[$n % count($pool)];
        $rating = mt_rand(0, 3) === 0 ? 'NULL' : (string) mt_rand(3, 5);
        $active = mt_rand(0, 9) === 0 ? 0 : 1;
        $tid++;
        $testimonialValues[] = sprintf('(%d, %d, %s, %s, %s, %s, %s, %d, %d, 1, 1)', $tid, $r['id'], $q($name), $q($texts[($n + $i) % count($texts)]), $rating, $q($gender), $q($r['url']), $active, $n);
        if ($n === 0) {
            $imgId++;
            $uuid = sprintf('%08x-%04x-4%03x-8%03x-%012x', mt_rand(0, 0xffffffff), mt_rand(0, 0xffff), mt_rand(0, 0xfff), mt_rand(0, 0xfff), mt_rand(0, 0xffffffff));
            $imageValues[] = sprintf("(%d, %d, '%s.jpg', '%s_thumb.jpg', 'image/jpeg', 24576, 640, 480, 0, 1)", $imgId, $tid, $uuid, $uuid);
        }
    }
}
$out[] = "INSERT INTO testimonials (id, landing_id, author_name, text, rating, gender, url, is_active, sort_order, created_by, updated_by) VALUES\n  " . implode(",\n  ", $testimonialValues) . "\n  ON DUPLICATE KEY UPDATE author_name = VALUES(author_name), text = VALUES(text), rating = VALUES(rating), gender = VALUES(gender), url = VALUES(url), is_active = VALUES(is_active), sort_order = VALUES(sort_order);";
$out[] = "INSERT INTO testimonial_images (id, testimonial_id, filename, thumb_filename, mime, size_bytes, width, height, sort_order, created_by) VALUES\n  " . implode(",\n  ", $imageValues) . "\n  ON DUPLICATE KEY UPDATE sort_order = VALUES(sort_order);";
$out[] = 'SET FOREIGN_KEY_CHECKS = 1;';

echo implode("\n\n", $out), "\n";
```

Generate the file (the seed images reference files that do not exist yet; phase 6 adds placeholder JPEGs for them via `database/seed-images/` — the UI must tolerate a missing file with a broken-image fallback, noted in the README shortcuts):
```bash
docker compose run --rm app php database/build-seed.php > database/seed.sql
```

- [ ] **Step 5: Large dataset generator**

`database/seed-large.php`:
```php
<?php

declare(strict_types=1);

/**
 * Inserts a large synthetic dataset for performance checks:
 * ~300 products × up to 20 countries × up to 50 testimonials (~200k rows).
 * Usage: php database/seed-large.php [products=300] [countries=20] [testimonials=50]
 * Uses landing ids from 1_000_000 upwards so it never collides with upstream ids.
 */

require __DIR__ . '/../vendor/autoload.php';

use App\Infrastructure\Db\PdoFactory;
use App\Support\Env;

Env::load(__DIR__ . '/../.env');
$products = (int) ($argv[1] ?? 300);
$countries = (int) ($argv[2] ?? 20);
$perLanding = (int) ($argv[3] ?? 50);
$countryCodes = array_slice(['EN', 'SI', 'IT', 'DE', 'AT', 'HR', 'HU', 'CZ', 'SK', 'PL', 'RO', 'BG', 'GR', 'TR', 'FR', 'ES', 'PT', 'NL', 'BE', 'DK'], 0, $countries);

$pdo = PdoFactory::create([
    'host' => Env::get('DB_HOST', '127.0.0.1'), 'port' => (int) Env::get('DB_PORT', '3306'),
    'name' => Env::get('DB_NAME', 'testimonials'), 'user' => Env::get('DB_USER', 'app'), 'pass' => Env::get('DB_PASS', 'app'),
]);
$pdo->beginTransaction();
$insProduct = $pdo->prepare('INSERT INTO products (parent_sku, title, description, image) VALUES (?, ?, ?, ?) ON DUPLICATE KEY UPDATE id = LAST_INSERT_ID(id)');
$insLanding = $pdo->prepare('INSERT IGNORE INTO landings (id, product_id, country, is_master, url, title, description, image, status, last_synced_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())');
$landingId = 1_000_000;
$batch = [];
$flush = static function () use (&$batch, $pdo): void {
    if ($batch === []) {
        return;
    }
    $sql = 'INSERT INTO testimonials (landing_id, author_name, text, rating, gender, url, is_active, sort_order) VALUES ' . implode(',', array_fill(0, count($batch) / 8, '(?,?,?,?,?,?,?,?)'));
    $pdo->prepare($sql)->execute($batch);
    $batch = [];
};
for ($p = 1; $p <= $products; $p++) {
    $sku = sprintf('demo-product-%03d', $p);
    $insProduct->execute([$sku, "Demo product $p", "Synthetic description for product $p", null]);
    $productId = (int) $pdo->lastInsertId();
    foreach ($countryCodes as $i => $cc) {
        $landingId++;
        $insLanding->execute([$landingId, $productId, $cc, $cc === 'EN' ? 1 : 0, "https://example.com/$cc/$sku", "Demo product $p ($cc)", null, null, 'DEMO']);
        $n = mt_rand(0, $perLanding);
        for ($t = 0; $t < $n; $t++) {
            array_push($batch, $landingId, "Author $t", "Synthetic testimonial $t for $sku in $cc.", mt_rand(0, 4) === 0 ? null : mt_rand(3, 5), ['male', 'female', 'unisex'][$t % 3], null, 1, $t);
            if (count($batch) >= 8 * 500) {
                $flush();
            }
        }
    }
    if ($p % 25 === 0) {
        fwrite(STDERR, "products: $p\n");
    }
}
$flush();
$pdo->commit();
echo "Inserted $products products, up to $countries countries each, up to $perLanding testimonials per landing.\n";
```

- [ ] **Step 6: Run tests, lint, stan — green; try the large seed once**

```bash
make integration && make lint && make stan
make up && make seed && make seed-large
docker compose exec -T db mysql -uroot -proot testimonials -e "SELECT COUNT(*) FROM testimonials"
```
Expected: integration `OK (10 tests)`; count well above 100k; generator finishes in under a minute.

- [ ] **Step 7: Commit, merge phase 1, log time**

```bash
git add -A && git commit -m "feat(db): add seed.sql, seed builder and large dataset generator"
```
Append `| 1 | Schema, seed, generator | <time> |` to `docs/time-log.md`, commit `docs: time log phase 1`, push, `gh pr create --title "Phase 1: schema + seed"` (body as in Task 3), `gh pr merge --merge --delete-branch`, `git checkout main && git pull`.

---

## Phase 2 — HTTP core

### Task 6: Container and domain exceptions

**Files:**
- Create: `src/Container.php`, `tests/Unit/ContainerTest.php`, `src/Domain/Exception/{HttpException,NotFoundException,ValidationException,UnauthorizedException,ForbiddenException,ConflictException,UpstreamException}.php`, `tests/Unit/Domain/Exception/HttpExceptionTest.php`

**Interfaces:**
- Produces: `App\Container` — `set(string $id, callable(Container): object $factory): void`, `get(string $id): object` (singleton per id), `has(string $id): bool`.
- Produces: `App\Domain\Exception\HttpException extends \RuntimeException` with `public function __construct(int $status, string $code, string $message, array $fields = [])`, `getStatus(): int`, `getErrorCode(): string`, `getFields(): array<string,string>`. Subclasses fix status+code: `NotFoundException(404,'not_found')`, `ValidationException(422,'validation_failed', $fields)`, `UnauthorizedException(401,'unauthorized')`, `ForbiddenException(403,'forbidden')`, `ConflictException(409,'conflict')`, `UpstreamException(502,'upstream_error')`.

- [ ] **Step 1: Branch and failing tests**

```bash
git checkout -b phase/02-http-core
```

`tests/Unit/ContainerTest.php`:
```php
<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Container;
use PHPUnit\Framework\TestCase;

final class ContainerTest extends TestCase
{
    public function testResolvesFactoryOnceAndCaches(): void
    {
        $c = new Container();
        $calls = 0;
        $c->set('svc', function () use (&$calls) { $calls++; return new \stdClass(); });
        self::assertSame($c->get('svc'), $c->get('svc'));
        self::assertSame(1, $calls);
    }

    public function testFactoryReceivesContainer(): void
    {
        $c = new Container();
        $c->set('dep', fn () => new \ArrayObject(['x']));
        $c->set('svc', fn (Container $c) => (object) ['dep' => $c->get('dep')]);
        self::assertInstanceOf(\ArrayObject::class, $c->get('svc')->dep);
    }

    public function testUnknownIdThrows(): void
    {
        $this->expectException(\RuntimeException::class);
        (new Container())->get('nope');
    }

    public function testHas(): void
    {
        $c = new Container();
        self::assertFalse($c->has('a'));
        $c->set('a', fn () => new \stdClass());
        self::assertTrue($c->has('a'));
    }
}
```

`tests/Unit/Domain/Exception/HttpExceptionTest.php`:
```php
<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Exception;

use App\Domain\Exception\ConflictException;
use App\Domain\Exception\ForbiddenException;
use App\Domain\Exception\NotFoundException;
use App\Domain\Exception\UnauthorizedException;
use App\Domain\Exception\UpstreamException;
use App\Domain\Exception\ValidationException;
use PHPUnit\Framework\TestCase;

final class HttpExceptionTest extends TestCase
{
    public function testSubclassesCarryStatusAndCode(): void
    {
        self::assertSame([404, 'not_found'], [(new NotFoundException('x'))->getStatus(), (new NotFoundException('x'))->getErrorCode()]);
        self::assertSame([401, 'unauthorized'], [(new UnauthorizedException())->getStatus(), (new UnauthorizedException())->getErrorCode()]);
        self::assertSame([403, 'forbidden'], [(new ForbiddenException())->getStatus(), (new ForbiddenException())->getErrorCode()]);
        self::assertSame([409, 'conflict'], [(new ConflictException('busy'))->getStatus(), (new ConflictException('busy'))->getErrorCode()]);
        self::assertSame([502, 'upstream_error'], [(new UpstreamException('down'))->getStatus(), (new UpstreamException('down'))->getErrorCode()]);
    }

    public function testValidationExceptionCarriesFields(): void
    {
        $e = new ValidationException(['text' => 'Required']);
        self::assertSame(422, $e->getStatus());
        self::assertSame('validation_failed', $e->getErrorCode());
        self::assertSame(['text' => 'Required'], $e->getFields());
        self::assertSame('Validation failed', $e->getMessage());
    }
}
```

- [ ] **Step 2: Run — expect class-not-found failures**

```bash
make unit
```

- [ ] **Step 3: Implement**

`src/Container.php`:
```php
<?php

declare(strict_types=1);

namespace App;

/**
 * Minimal closure-based service container: each id resolves once and is cached.
 */
final class Container
{
    /** @var array<string, callable(Container): object> */
    private array $factories = [];
    /** @var array<string, object> */
    private array $instances = [];

    /** @param callable(Container): object $factory */
    public function set(string $id, callable $factory): void
    {
        $this->factories[$id] = $factory;
        unset($this->instances[$id]);
    }

    public function get(string $id): object
    {
        if (!isset($this->instances[$id])) {
            if (!isset($this->factories[$id])) {
                throw new \RuntimeException("No service registered for '$id'");
            }
            $this->instances[$id] = ($this->factories[$id])($this);
        }
        return $this->instances[$id];
    }

    public function has(string $id): bool
    {
        return isset($this->factories[$id]);
    }
}
```

`src/Domain/Exception/HttpException.php`:
```php
<?php

declare(strict_types=1);

namespace App\Domain\Exception;

class HttpException extends \RuntimeException
{
    /** @param array<string,string> $fields */
    public function __construct(
        private readonly int $status,
        private readonly string $errorCode,
        string $message,
        private readonly array $fields = [],
    ) {
        parent::__construct($message);
    }

    public function getStatus(): int
    {
        return $this->status;
    }

    public function getErrorCode(): string
    {
        return $this->errorCode;
    }

    /** @return array<string,string> */
    public function getFields(): array
    {
        return $this->fields;
    }
}
```

The six subclasses (one file each, same namespace):
```php
final class NotFoundException extends HttpException
{
    public function __construct(string $message = 'Not found') { parent::__construct(404, 'not_found', $message); }
}
final class ValidationException extends HttpException
{
    /** @param array<string,string> $fields */
    public function __construct(array $fields, string $message = 'Validation failed') { parent::__construct(422, 'validation_failed', $message, $fields); }
}
final class UnauthorizedException extends HttpException
{
    public function __construct(string $message = 'Authentication required') { parent::__construct(401, 'unauthorized', $message); }
}
final class ForbiddenException extends HttpException
{
    public function __construct(string $message = 'Forbidden') { parent::__construct(403, 'forbidden', $message); }
}
final class ConflictException extends HttpException
{
    public function __construct(string $message = 'Conflict') { parent::__construct(409, 'conflict', $message); }
}
final class UpstreamException extends HttpException
{
    public function __construct(string $message = 'Upstream service failed') { parent::__construct(502, 'upstream_error', $message); }
}
```
(Write each with the `<?php declare(strict_types=1); namespace App\Domain\Exception;` header and PSR-12 brace placement — php-cs-fixer will complain about the one-line bodies above; expand them to multi-line.)

- [ ] **Step 4: Green + commit**

```bash
make unit && make lint && make stan
git add -A && git commit -m "feat(http): add service container and HTTP-mapped domain exceptions"
```

### Task 7: Request, Response, Router

**Files:**
- Create: `src/Http/Request.php`, `src/Http/Response.php`, `src/Http/Router.php`, `src/Http/RouteMatch.php`, `tests/Unit/Http/RequestTest.php`, `tests/Unit/Http/ResponseTest.php`, `tests/Unit/Http/RouterTest.php`

**Interfaces:**
- Produces `App\Http\Request`:
  ```php
  final class Request {
      /** @param array<string,mixed> $query @param array<string,mixed> $body @param array<string,string> $headers @param array<string,mixed> $files @param array<string,string> $cookies @param array<string,string> $attributes */
      public function __construct(public readonly string $method, public readonly string $path, public readonly array $query = [], public readonly array $body = [], public readonly array $headers = [], public readonly array $files = [], public readonly array $cookies = [], public readonly array $attributes = []) {}
      public static function fromGlobals(): self;
      public function header(string $name): ?string;            // case-insensitive
      public function query(string $key, mixed $default = null): mixed;
      public function input(string $key, mixed $default = null): mixed;
      public function attribute(string $key): string;            // route param, throws if missing
      public function withAttributes(array $attributes): self;
      public function isXhr(): bool;                             // X-Requested-With: XMLHttpRequest
      public function isApi(): bool;                             // path starts with /api/
  }
  ```
- Produces `App\Http\Response`:
  ```php
  final class Response {
      /** @param array<string,string> $headers */
      public function __construct(public readonly int $status = 200, public readonly string $body = '', public readonly array $headers = []) {}
      public static function json(mixed $data, int $status = 200): self;
      public static function noContent(): self;
      /** @param array<string,string> $fields */
      public static function error(int $status, string $code, string $message, array $fields = []): self;
      public static function html(string $html, int $status = 200): self;
      public static function file(string $path, string $mime): self;   // body = file contents
      public function withHeader(string $name, string $value): self;
      public function send(): void;
  }
  ```
- Produces `App\Http\Router`: `add(string $method, string $pattern, array{0:class-string,1:string} $handler): void`, shortcuts `get/post/patch/delete`, `match(string $method, string $path): RouteMatch` — throws `NotFoundException` when no pattern matches, `HttpException(405,'method_not_allowed')` when the path matches but not the method. `App\Http\RouteMatch { public readonly array $handler; public readonly array<string,string> $params; }`. Patterns use `{name}` segments matching `[^/]+`.

- [ ] **Step 1: Failing tests**

`tests/Unit/Http/RequestTest.php`:
```php
<?php

declare(strict_types=1);

namespace Tests\Unit\Http;

use App\Http\Request;
use PHPUnit\Framework\TestCase;

final class RequestTest extends TestCase
{
    public function testHeaderLookupIsCaseInsensitive(): void
    {
        $r = new Request('GET', '/api/x', headers: ['X-Requested-With' => 'XMLHttpRequest']);
        self::assertSame('XMLHttpRequest', $r->header('x-requested-with'));
        self::assertTrue($r->isXhr());
        self::assertTrue($r->isApi());
    }

    public function testQueryAndInputDefaults(): void
    {
        $r = new Request('POST', '/api/x', query: ['page' => '2'], body: ['name' => 'a']);
        self::assertSame('2', $r->query('page'));
        self::assertSame(20, $r->query('per_page', 20));
        self::assertSame('a', $r->input('name'));
        self::assertNull($r->input('missing'));
    }

    public function testWithAttributesReturnsNewInstance(): void
    {
        $r = new Request('GET', '/api/testimonials/5');
        $r2 = $r->withAttributes(['id' => '5']);
        self::assertSame('5', $r2->attribute('id'));
        self::assertNotSame($r, $r2);
        $this->expectException(\OutOfBoundsException::class);
        $r->attribute('id');
    }

    public function testFromGlobalsParsesJsonBodyAndPath(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'PATCH';
        $_SERVER['REQUEST_URI'] = '/api/testimonials/7?x=1';
        $_SERVER['CONTENT_TYPE'] = 'application/json';
        $_SERVER['HTTP_X_REQUESTED_WITH'] = 'XMLHttpRequest';
        $_GET = ['x' => '1'];
        Request::$rawBodyProvider = fn () => '{"text":"hi"}';
        $r = Request::fromGlobals();
        self::assertSame('PATCH', $r->method);
        self::assertSame('/api/testimonials/7', $r->path);
        self::assertSame('hi', $r->input('text'));
        self::assertSame('1', $r->query('x'));
        self::assertTrue($r->isXhr());
        Request::$rawBodyProvider = null;
    }
}
```

`tests/Unit/Http/ResponseTest.php`:
```php
<?php

declare(strict_types=1);

namespace Tests\Unit\Http;

use App\Http\Response;
use PHPUnit\Framework\TestCase;

final class ResponseTest extends TestCase
{
    public function testJson(): void
    {
        $r = Response::json(['a' => 'č'], 201);
        self::assertSame(201, $r->status);
        self::assertSame('{"a":"č"}', $r->body);
        self::assertSame('application/json; charset=utf-8', $r->headers['Content-Type']);
    }

    public function testErrorShape(): void
    {
        $r = Response::error(422, 'validation_failed', 'Validation failed', ['text' => 'Required']);
        self::assertSame(['error' => ['code' => 'validation_failed', 'message' => 'Validation failed', 'fields' => ['text' => 'Required']]], json_decode($r->body, true));
    }

    public function testNoContent(): void
    {
        self::assertSame(204, Response::noContent()->status);
        self::assertSame('', Response::noContent()->body);
    }

    public function testWithHeaderIsImmutable(): void
    {
        $a = Response::json([]);
        $b = $a->withHeader('X-Test', '1');
        self::assertArrayNotHasKey('X-Test', $a->headers);
        self::assertSame('1', $b->headers['X-Test']);
    }
}
```

`tests/Unit/Http/RouterTest.php`:
```php
<?php

declare(strict_types=1);

namespace Tests\Unit\Http;

use App\Domain\Exception\HttpException;
use App\Domain\Exception\NotFoundException;
use App\Http\Router;
use PHPUnit\Framework\TestCase;

final class RouterTest extends TestCase
{
    private Router $router;

    protected function setUp(): void
    {
        $this->router = new Router();
        $this->router->get('/api/products', ['ProductsCtl', 'index']);
        $this->router->get('/api/products/{sku}/landings', ['ProductsCtl', 'landings']);
        $this->router->patch('/api/testimonials/{id}', ['TestimonialsCtl', 'update']);
    }

    public function testMatchesStaticRoute(): void
    {
        $m = $this->router->match('GET', '/api/products');
        self::assertSame(['ProductsCtl', 'index'], $m->handler);
        self::assertSame([], $m->params);
    }

    public function testMatchesParams(): void
    {
        $m = $this->router->match('GET', '/api/products/ab-forge/landings');
        self::assertSame(['sku' => 'ab-forge'], $m->params);
        self::assertSame(['id' => '42'], $this->router->match('PATCH', '/api/testimonials/42')->params);
    }

    public function testTrailingSlashIsTolerated(): void
    {
        self::assertSame(['ProductsCtl', 'index'], $this->router->match('GET', '/api/products/')->handler);
    }

    public function testUnknownPathIs404(): void
    {
        $this->expectException(NotFoundException::class);
        $this->router->match('GET', '/api/nope');
    }

    public function testWrongMethodIs405(): void
    {
        try {
            $this->router->match('DELETE', '/api/products');
            self::fail('expected 405');
        } catch (HttpException $e) {
            self::assertSame(405, $e->getStatus());
            self::assertSame('method_not_allowed', $e->getErrorCode());
        }
    }
}
```

- [ ] **Step 2: Run — expect failures**

```bash
make unit
```

- [ ] **Step 3: Implement**

`src/Http/Request.php`:
```php
<?php

declare(strict_types=1);

namespace App\Http;

final class Request
{
    /** @var (callable(): string)|null  test seam for php://input */
    public static $rawBodyProvider = null;

    /**
     * @param array<string,mixed>  $query
     * @param array<string,mixed>  $body
     * @param array<string,string> $headers
     * @param array<string,mixed>  $files
     * @param array<string,string> $cookies
     * @param array<string,string> $attributes
     */
    public function __construct(
        public readonly string $method,
        public readonly string $path,
        public readonly array $query = [],
        public readonly array $body = [],
        public readonly array $headers = [],
        public readonly array $files = [],
        public readonly array $cookies = [],
        public readonly array $attributes = [],
    ) {
    }

    public static function fromGlobals(): self
    {
        $method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
        $path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
        $headers = [];
        foreach ($_SERVER as $k => $v) {
            if (str_starts_with($k, 'HTTP_')) {
                $headers[str_replace('_', '-', substr($k, 5))] = (string) $v;
            }
        }
        if (isset($_SERVER['CONTENT_TYPE'])) {
            $headers['CONTENT-TYPE'] = (string) $_SERVER['CONTENT_TYPE'];
        }
        $body = $_POST;
        $contentType = $headers['CONTENT-TYPE'] ?? '';
        if (str_contains($contentType, 'application/json')) {
            $raw = self::$rawBodyProvider ? (self::$rawBodyProvider)() : (string) file_get_contents('php://input');
            $decoded = $raw === '' ? [] : json_decode($raw, true);
            $body = is_array($decoded) ? $decoded : [];
        }
        return new self($method, $path, $_GET, $body, $headers, $_FILES, $_COOKIE);
    }

    public function header(string $name): ?string
    {
        $name = strtoupper($name);
        foreach ($this->headers as $k => $v) {
            if (strtoupper($k) === $name) {
                return $v;
            }
        }
        return null;
    }

    public function query(string $key, mixed $default = null): mixed
    {
        return $this->query[$key] ?? $default;
    }

    public function input(string $key, mixed $default = null): mixed
    {
        return $this->body[$key] ?? $default;
    }

    public function attribute(string $key): string
    {
        if (!isset($this->attributes[$key])) {
            throw new \OutOfBoundsException("Missing route attribute '$key'");
        }
        return $this->attributes[$key];
    }

    /** @param array<string,string> $attributes */
    public function withAttributes(array $attributes): self
    {
        return new self($this->method, $this->path, $this->query, $this->body, $this->headers, $this->files, $this->cookies, $attributes + $this->attributes);
    }

    public function isXhr(): bool
    {
        return strcasecmp((string) $this->header('X-Requested-With'), 'XMLHttpRequest') === 0;
    }

    public function isApi(): bool
    {
        return str_starts_with($this->path, '/api/');
    }
}
```

`src/Http/Response.php`:
```php
<?php

declare(strict_types=1);

namespace App\Http;

final class Response
{
    /** @param array<string,string> $headers */
    public function __construct(
        public readonly int $status = 200,
        public readonly string $body = '',
        public readonly array $headers = [],
    ) {
    }

    public static function json(mixed $data, int $status = 200): self
    {
        $body = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        return new self($status, $body, ['Content-Type' => 'application/json; charset=utf-8']);
    }

    public static function noContent(): self
    {
        return new self(204);
    }

    /** @param array<string,string> $fields */
    public static function error(int $status, string $code, string $message, array $fields = []): self
    {
        $error = ['code' => $code, 'message' => $message];
        if ($fields !== []) {
            $error['fields'] = $fields;
        }
        return self::json(['error' => $error], $status);
    }

    public static function html(string $html, int $status = 200): self
    {
        return new self($status, $html, ['Content-Type' => 'text/html; charset=utf-8']);
    }

    public static function file(string $path, string $mime): self
    {
        return new self(200, (string) file_get_contents($path), ['Content-Type' => $mime, 'Cache-Control' => 'public, max-age=86400']);
    }

    public function withHeader(string $name, string $value): self
    {
        return new self($this->status, $this->body, [$name => $value] + $this->headers);
    }

    public function send(): void
    {
        http_response_code($this->status);
        foreach ($this->headers as $name => $value) {
            header("$name: $value");
        }
        echo $this->body;
    }
}
```

`src/Http/RouteMatch.php`:
```php
<?php

declare(strict_types=1);

namespace App\Http;

final class RouteMatch
{
    /**
     * @param array{0:class-string,1:string} $handler
     * @param array<string,string>           $params
     */
    public function __construct(public readonly array $handler, public readonly array $params)
    {
    }
}
```

`src/Http/Router.php`:
```php
<?php

declare(strict_types=1);

namespace App\Http;

use App\Domain\Exception\HttpException;
use App\Domain\Exception\NotFoundException;

final class Router
{
    /** @var list<array{method:string,regex:string,handler:array{0:class-string,1:string}}> */
    private array $routes = [];

    /** @param array{0:class-string,1:string} $handler */
    public function add(string $method, string $pattern, array $handler): void
    {
        $regex = '#^' . preg_replace('#\{([a-zA-Z_][a-zA-Z0-9_]*)\}#', '(?P<$1>[^/]+)', rtrim($pattern, '/')) . '/?$#';
        $this->routes[] = ['method' => strtoupper($method), 'regex' => $regex, 'handler' => $handler];
    }

    /** @param array{0:class-string,1:string} $handler */
    public function get(string $pattern, array $handler): void
    {
        $this->add('GET', $pattern, $handler);
    }

    /** @param array{0:class-string,1:string} $handler */
    public function post(string $pattern, array $handler): void
    {
        $this->add('POST', $pattern, $handler);
    }

    /** @param array{0:class-string,1:string} $handler */
    public function patch(string $pattern, array $handler): void
    {
        $this->add('PATCH', $pattern, $handler);
    }

    /** @param array{0:class-string,1:string} $handler */
    public function delete(string $pattern, array $handler): void
    {
        $this->add('DELETE', $pattern, $handler);
    }

    public function match(string $method, string $path): RouteMatch
    {
        $method = strtoupper($method);
        $pathMatched = false;
        foreach ($this->routes as $route) {
            if (!preg_match($route['regex'], $path, $m)) {
                continue;
            }
            $pathMatched = true;
            if ($route['method'] !== $method) {
                continue;
            }
            $params = [];
            foreach ($m as $k => $v) {
                if (is_string($k)) {
                    $params[$k] = rawurldecode($v);
                }
            }
            return new RouteMatch($route['handler'], $params);
        }
        if ($pathMatched) {
            throw new HttpException(405, 'method_not_allowed', "Method $method not allowed for $path");
        }
        throw new NotFoundException("No route for $method $path");
    }
}
```

- [ ] **Step 4: Green + commit**

```bash
make unit && make lint && make stan
git add -A && git commit -m "feat(http): add Request, Response and Router"
```

### Task 8: Kernel, middleware, config, front controller, `/api/health`, API test harness

**Files:**
- Create: `src/Http/MiddlewareInterface.php`, `src/Http/Middleware/RequireXhrMiddleware.php`, `src/Http/Kernel.php`, `src/Http/Controller/HealthController.php`, `src/Http/Controller/HomeController.php`, `config/config.php`, `config/container.php`, `config/routes.php`, `tests/Unit/Http/KernelTest.php`, `tests/Unit/Http/Middleware/RequireXhrMiddlewareTest.php`, `tests/Api/ApiTestCase.php`, `tests/Api/HealthTest.php`
- Modify: `public/index.php` (replace placeholder)

**Interfaces:**
- `App\Http\MiddlewareInterface { public function process(Request $request, callable $next): Response; }` where `$next(Request): Response`.
- `App\Http\Kernel::__construct(Container $container, Router $router, array<MiddlewareInterface> $middleware, bool $debug)`; `handle(Request): Response`. Handlers are `[ControllerClass, method]`, controller resolved from the container, method receives `Request` and returns `Response`. Exceptions: `HttpException` → `Response::error(status, code, message, fields)`; any other `\Throwable` → 500 `internal_error` (message included only when `$debug`), logged with `error_log`.
- `config/config.php` returns:
  ```php
  ['env' => string, 'debug' => bool,
   'db' => ['host','port','name','user','pass'],
   'upload' => ['dir' => absolute path, 'max_bytes' => int],
   'landings_api' => ['url' => string, 'key' => string],
   'session' => ['name' => string],
   'root' => project root path]
  ```
- `config/container.php` returns `function(array $config): Container`.
- `config/routes.php` returns `function(Router $router): void`.
- `Tests\Api\ApiTestCase`: `protected function request(string $method, string $path, ?array $json = null, array $headers = [], bool $xhr = true): array{status:int, json:mixed, headers:array<string,string>}` using curl against `API_BASE_URL`; keeps a cookie jar per test class in `sys_get_temp_dir()`.

- [ ] **Step 1: Failing tests**

`tests/Unit/Http/Middleware/RequireXhrMiddlewareTest.php`:
```php
<?php

declare(strict_types=1);

namespace Tests\Unit\Http\Middleware;

use App\Domain\Exception\ForbiddenException;
use App\Http\Middleware\RequireXhrMiddleware;
use App\Http\Request;
use App\Http\Response;
use PHPUnit\Framework\TestCase;

final class RequireXhrMiddlewareTest extends TestCase
{
    private RequireXhrMiddleware $mw;

    protected function setUp(): void
    {
        $this->mw = new RequireXhrMiddleware();
    }

    public function testGetPassesWithoutHeader(): void
    {
        $r = $this->mw->process(new Request('GET', '/api/products'), fn () => Response::noContent());
        self::assertSame(204, $r->status);
    }

    public function testMutatingApiRequestWithoutHeaderIsForbidden(): void
    {
        $this->expectException(ForbiddenException::class);
        $this->mw->process(new Request('POST', '/api/landings/sync'), fn () => Response::noContent());
    }

    public function testMutatingApiRequestWithHeaderPasses(): void
    {
        $r = $this->mw->process(new Request('DELETE', '/api/testimonials/1', headers: ['X-Requested-With' => 'XMLHttpRequest']), fn () => Response::noContent());
        self::assertSame(204, $r->status);
    }

    public function testNonApiPostIsNotGuarded(): void
    {
        $r = $this->mw->process(new Request('POST', '/other'), fn () => Response::noContent());
        self::assertSame(204, $r->status);
    }
}
```

`tests/Unit/Http/KernelTest.php`:
```php
<?php

declare(strict_types=1);

namespace Tests\Unit\Http;

use App\Container;
use App\Domain\Exception\ValidationException;
use App\Http\Kernel;
use App\Http\MiddlewareInterface;
use App\Http\Request;
use App\Http\Response;
use App\Http\Router;
use PHPUnit\Framework\TestCase;

final class KernelTest extends TestCase
{
    private function kernel(bool $debug = false, array $middleware = []): Kernel
    {
        $c = new Container();
        $c->set(FakeController::class, fn () => new FakeController());
        $router = new Router();
        $router->get('/api/ok', [FakeController::class, 'ok']);
        $router->get('/api/invalid', [FakeController::class, 'invalid']);
        $router->get('/api/boom', [FakeController::class, 'boom']);
        $router->get('/api/echo/{id}', [FakeController::class, 'echo']);
        return new Kernel($c, $router, $middleware, $debug);
    }

    public function testDispatchesToController(): void
    {
        $r = $this->kernel()->handle(new Request('GET', '/api/ok'));
        self::assertSame(200, $r->status);
        self::assertSame(['ok' => true], json_decode($r->body, true));
    }

    public function testRouteParamsArePassedAsAttributes(): void
    {
        $r = $this->kernel()->handle(new Request('GET', '/api/echo/99'));
        self::assertSame(['id' => '99'], json_decode($r->body, true));
    }

    public function testHttpExceptionBecomesErrorResponse(): void
    {
        $r = $this->kernel()->handle(new Request('GET', '/api/invalid'));
        self::assertSame(422, $r->status);
        self::assertSame('validation_failed', json_decode($r->body, true)['error']['code']);
        self::assertSame(['text' => 'Required'], json_decode($r->body, true)['error']['fields']);
    }

    public function testUnknownRouteIs404Json(): void
    {
        $r = $this->kernel()->handle(new Request('GET', '/api/nothing'));
        self::assertSame(404, $r->status);
        self::assertSame('not_found', json_decode($r->body, true)['error']['code']);
    }

    public function testUnexpectedExceptionIs500AndHidesMessageUnlessDebug(): void
    {
        $r = $this->kernel(false)->handle(new Request('GET', '/api/boom'));
        self::assertSame(500, $r->status);
        self::assertSame('Internal server error', json_decode($r->body, true)['error']['message']);
        $r = $this->kernel(true)->handle(new Request('GET', '/api/boom'));
        self::assertStringContainsString('kaboom', json_decode($r->body, true)['error']['message']);
    }

    public function testMiddlewareRunsInOrderAndCanShortCircuit(): void
    {
        $log = [];
        $mw1 = new class ($log) implements MiddlewareInterface {
            public function __construct(private array &$log) {}
            public function process(Request $request, callable $next): Response { $this->log[] = 'a'; return $next($request); }
        };
        $mw2 = new class implements MiddlewareInterface {
            public function process(Request $request, callable $next): Response { return Response::json(['short' => true], 418); }
        };
        $r = $this->kernel(false, [$mw1, $mw2])->handle(new Request('GET', '/api/ok'));
        self::assertSame(418, $r->status);
        self::assertSame(['a'], $log);
    }
}

final class FakeController
{
    public function ok(Request $r): Response { return Response::json(['ok' => true]); }
    public function echo(Request $r): Response { return Response::json(['id' => $r->attribute('id')]); }
    public function invalid(Request $r): Response { throw new ValidationException(['text' => 'Required']); }
    public function boom(Request $r): Response { throw new \RuntimeException('kaboom'); }
}
```

`tests/Api/ApiTestCase.php`:
```php
<?php

declare(strict_types=1);

namespace Tests\Api;

use App\Support\Env;
use PHPUnit\Framework\TestCase;

abstract class ApiTestCase extends TestCase
{
    private string $cookieJar;

    protected function setUp(): void
    {
        $this->cookieJar = sys_get_temp_dir() . '/tm-cookies-' . str_replace('\\', '_', static::class) . '.txt';
    }

    protected function baseUrl(): string
    {
        return rtrim(Env::get('API_BASE_URL', 'http://localhost') ?? 'http://localhost', '/');
    }

    /**
     * @param array<string,mixed>|null $json
     * @param array<string,string>     $headers
     * @return array{status:int,json:mixed,headers:array<string,string>}
     */
    protected function request(string $method, string $path, ?array $json = null, array $headers = [], bool $xhr = true): array
    {
        $ch = curl_init($this->baseUrl() . $path);
        $hdrs = ['Accept: application/json'];
        if ($xhr) {
            $hdrs[] = 'X-Requested-With: XMLHttpRequest';
        }
        foreach ($headers as $k => $v) {
            $hdrs[] = "$k: $v";
        }
        if ($json !== null) {
            $hdrs[] = 'Content-Type: application/json';
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($json, JSON_THROW_ON_ERROR));
        }
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_HTTPHEADER => $hdrs,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADER => true,
            CURLOPT_COOKIEJAR => $this->cookieJar,
            CURLOPT_COOKIEFILE => $this->cookieJar,
        ]);
        $raw = curl_exec($ch);
        if ($raw === false) {
            self::fail('curl: ' . curl_error($ch));
        }
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $headerSize = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        curl_close($ch);
        $rawHeaders = substr((string) $raw, 0, $headerSize);
        $body = substr((string) $raw, $headerSize);
        $parsed = [];
        foreach (explode("\r\n", $rawHeaders) as $line) {
            if (str_contains($line, ':')) {
                [$k, $v] = explode(':', $line, 2);
                $parsed[strtolower(trim($k))] = trim($v);
            }
        }
        return ['status' => $status, 'json' => $body === '' ? null : json_decode($body, true), 'headers' => $parsed];
    }

    /** Uploads files via multipart; $files = ['images[]' => '/abs/path.jpg', ...]. @param array<string,string> $files @param array<string,string> $fields @return array{status:int,json:mixed,headers:array<string,string>} */
    protected function upload(string $path, array $files, array $fields = []): array
    {
        $ch = curl_init($this->baseUrl() . $path);
        $post = $fields;
        foreach ($files as $field => $file) {
            $post[$field] = new \CURLFile($file);
        }
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $post,
            CURLOPT_HTTPHEADER => ['Accept: application/json', 'X-Requested-With: XMLHttpRequest'],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_COOKIEJAR => $this->cookieJar,
            CURLOPT_COOKIEFILE => $this->cookieJar,
        ]);
        $body = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);
        return ['status' => $status, 'json' => $body === '' || $body === false ? null : json_decode($body, true), 'headers' => []];
    }
}
```

`tests/Api/HealthTest.php`:
```php
<?php

declare(strict_types=1);

namespace Tests\Api;

final class HealthTest extends ApiTestCase
{
    public function testHealthReportsDb(): void
    {
        $r = $this->request('GET', '/api/health');
        self::assertSame(200, $r['status']);
        self::assertSame(['status' => 'ok', 'db' => true], $r['json']);
        self::assertStringStartsWith('application/json', $r['headers']['content-type']);
    }

    public function testUnknownApiRouteIsJson404(): void
    {
        $r = $this->request('GET', '/api/does-not-exist');
        self::assertSame(404, $r['status']);
        self::assertSame('not_found', $r['json']['error']['code']);
    }

    public function testMutationWithoutXhrHeaderIs403(): void
    {
        $r = $this->request('POST', '/api/landings/sync', [], [], xhr: false);
        self::assertSame(403, $r['status']);
    }

    public function testRootServesSpaShell(): void
    {
        $ch = curl_init($this->baseUrl() . '/');
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        $html = (string) curl_exec($ch);
        self::assertSame(200, curl_getinfo($ch, CURLINFO_RESPONSE_CODE));
        self::assertStringContainsString('Testimonials Manager', $html);
    }
}
```

- [ ] **Step 2: Run unit — expect failures; API suite will fail until server serves index.php**

```bash
make unit
```

- [ ] **Step 3: Implement middleware, kernel, controllers, config, front controller**

`src/Http/MiddlewareInterface.php`:
```php
<?php

declare(strict_types=1);

namespace App\Http;

interface MiddlewareInterface
{
    /** @param callable(Request): Response $next */
    public function process(Request $request, callable $next): Response;
}
```

`src/Http/Middleware/RequireXhrMiddleware.php`:
```php
<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Domain\Exception\ForbiddenException;
use App\Http\MiddlewareInterface;
use App\Http\Request;
use App\Http\Response;

/**
 * CSRF guard: mutating /api requests must carry X-Requested-With, which cross-site forms cannot set.
 */
final class RequireXhrMiddleware implements MiddlewareInterface
{
    public function process(Request $request, callable $next): Response
    {
        if ($request->isApi() && !in_array($request->method, ['GET', 'HEAD', 'OPTIONS'], true) && !$request->isXhr()) {
            throw new ForbiddenException('Missing X-Requested-With header');
        }
        return $next($request);
    }
}
```

`src/Http/Kernel.php`:
```php
<?php

declare(strict_types=1);

namespace App\Http;

use App\Container;
use App\Domain\Exception\HttpException;

final class Kernel
{
    /** @param list<MiddlewareInterface> $middleware */
    public function __construct(
        private readonly Container $container,
        private readonly Router $router,
        private readonly array $middleware,
        private readonly bool $debug,
    ) {
    }

    public function handle(Request $request): Response
    {
        try {
            $pipeline = array_reduce(
                array_reverse($this->middleware),
                fn (callable $next, MiddlewareInterface $mw) => fn (Request $r) => $mw->process($r, $next),
                fn (Request $r) => $this->dispatch($r),
            );
            return $pipeline($request);
        } catch (HttpException $e) {
            return Response::error($e->getStatus(), $e->getErrorCode(), $e->getMessage(), $e->getFields());
        } catch (\Throwable $e) {
            error_log(sprintf('[500] %s: %s in %s:%d', $e::class, $e->getMessage(), $e->getFile(), $e->getLine()));
            $message = $this->debug ? $e::class . ': ' . $e->getMessage() : 'Internal server error';
            return Response::error(500, 'internal_error', $message);
        }
    }

    private function dispatch(Request $request): Response
    {
        $match = $this->router->match($request->method, $request->path);
        [$class, $method] = $match->handler;
        $controller = $this->container->get($class);
        return $controller->$method($request->withAttributes($match->params));
    }
}
```

`src/Http/Controller/HealthController.php`:
```php
<?php

declare(strict_types=1);

namespace App\Http\Controller;

use App\Http\Request;
use App\Http\Response;

final class HealthController
{
    public function __construct(private readonly \PDO $pdo)
    {
    }

    public function show(Request $request): Response
    {
        try {
            $db = $this->pdo->query('SELECT 1')->fetchColumn() === 1;
        } catch (\Throwable) {
            $db = false;
        }
        return Response::json(['status' => $db ? 'ok' : 'degraded', 'db' => $db], $db ? 200 : 503);
    }
}
```

`src/Http/Controller/HomeController.php`:
```php
<?php

declare(strict_types=1);

namespace App\Http\Controller;

use App\Http\Request;
use App\Http\Response;

final class HomeController
{
    public function __construct(private readonly string $publicDir)
    {
    }

    public function index(Request $request): Response
    {
        return Response::html((string) file_get_contents($this->publicDir . '/index.html'));
    }
}
```

`config/config.php`:
```php
<?php

declare(strict_types=1);

use App\Support\Env;

$root = dirname(__DIR__);
Env::load($root . '/.env');

$uploadDir = Env::get('UPLOAD_DIR', 'storage/uploads') ?? 'storage/uploads';

return [
    'root' => $root,
    'env' => Env::get('APP_ENV', 'prod'),
    'debug' => in_array(Env::get('APP_DEBUG', '0'), ['1', 'true'], true),
    'db' => [
        'host' => Env::get('DB_HOST', '127.0.0.1'),
        'port' => (int) Env::get('DB_PORT', '3306'),
        'name' => Env::get('DB_NAME', 'testimonials'),
        'user' => Env::get('DB_USER', 'root'),
        'pass' => Env::get('DB_PASS', ''),
    ],
    'upload' => [
        'dir' => str_starts_with($uploadDir, '/') ? $uploadDir : $root . '/' . $uploadDir,
        'max_bytes' => (int) Env::get('UPLOAD_MAX_BYTES', '5242880'),
    ],
    'landings_api' => [
        'url' => Env::get('LANDINGS_API_URL', ''),
        'key' => Env::get('LANDINGS_API_KEY', ''),
    ],
    'session' => ['name' => Env::get('SESSION_NAME', 'tm_session')],
];
```

`config/container.php`:
```php
<?php

declare(strict_types=1);

use App\Container;
use App\Http\Controller\HealthController;
use App\Http\Controller\HomeController;
use App\Http\Kernel;
use App\Http\Middleware\RequireXhrMiddleware;
use App\Http\Router;
use App\Infrastructure\Db\PdoFactory;

/** @param array<string,mixed> $config */
return static function (array $config): Container {
    $c = new Container();

    $c->set('config', fn () => new ArrayObject($config));
    $c->set(PDO::class, fn () => PdoFactory::create($config['db']));

    $c->set(Router::class, function () {
        $router = new Router();
        (require __DIR__ . '/routes.php')($router);
        return $router;
    });
    $c->set(Kernel::class, fn (Container $c) => new Kernel(
        $c,
        $c->get(Router::class),
        [new RequireXhrMiddleware()],
        (bool) $config['debug'],
    ));

    $c->set(HealthController::class, fn (Container $c) => new HealthController($c->get(PDO::class)));
    $c->set(HomeController::class, fn () => new HomeController($config['root'] . '/public'));

    return $c;
};
```

`config/routes.php`:
```php
<?php

declare(strict_types=1);

use App\Http\Controller\HealthController;
use App\Http\Controller\HomeController;
use App\Http\Router;

return static function (Router $r): void {
    $r->get('/', [HomeController::class, 'index']);
    $r->get('/api/health', [HealthController::class, 'show']);
};
```

`public/index.php`:
```php
<?php

declare(strict_types=1);

// PHP built-in server (CI / dev without Apache): serve existing static files directly.
if (PHP_SAPI === 'cli-server') {
    $file = __DIR__ . (parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/');
    if ($file !== __DIR__ . '/' && is_file($file)) {
        return false;
    }
}

require dirname(__DIR__) . '/vendor/autoload.php';

$config = require dirname(__DIR__) . '/config/config.php';
$container = (require dirname(__DIR__) . '/config/container.php')($config);

$container->get(App\Http\Kernel::class)
    ->handle(App\Http\Request::fromGlobals())
    ->send();
```

- [ ] **Step 4: Minimal SPA shell so `/` passes** (full shell in Task 9)

`public/index.html`:
```html
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Testimonials Manager</title>
</head>
<body>
  <h1>Testimonials Manager</h1>
</body>
</html>
```

- [ ] **Step 5: Run everything**

```bash
make unit && make lint && make stan
make up && make seed && make api
```
Expected: unit green (`KernelTest`, middleware, router…); `make api` → `OK (4 tests)` against Apache in the container.

- [ ] **Step 6: Commit**

```bash
git add -A && git commit -m "feat(http): kernel, middleware pipeline, config wiring, health endpoint and API test harness"
```

### Task 9: SPA shell with DFVU look, api.js, toast, hash router

**Files:**
- Create: `public/assets/css/app.css`, `public/assets/js/api.js`, `public/assets/js/toast.js`, `public/assets/js/router.js`, `public/assets/js/app.js`
- Modify: `public/index.html`

**Interfaces (JS globals, no modules — must work from `file://`-free XAMPP without a bundler):**
- `window.Api = { get(path), post(path, body), patch(path, body), del(path), upload(path, formData) }` → each returns a Promise resolving to parsed JSON (or `null` for 204) and rejecting with `ApiError { status, code, message, fields }`. Adds `X-Requested-With: XMLHttpRequest` and `Accept: application/json`, `credentials: 'same-origin'`.
- `window.Toast = { success(msg), error(msg), info(msg) }` — Bootstrap toasts in a fixed container; errors stay 8 s, others 3 s.
- `window.Router = { register(pattern, handler), navigate(hash), start() }` — patterns like `#/products/{sku}`; handler receives `(params, query)` and renders into `#app`.
- `window.App = { el: document.getElementById('app'), setNav(html) }`.

- [ ] **Step 1: Files**

`public/index.html`:
```html
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Testimonials Manager</title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@600;800&family=Roboto:wght@400;500&display=swap" rel="stylesheet">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
  <link href="assets/css/app.css" rel="stylesheet">
</head>
<body>
  <nav class="navbar navbar-expand-lg navbar-dark tm-navbar">
    <div class="container-fluid">
      <a class="navbar-brand" href="#/products"><span class="tm-brand">DFVU</span><span class="tm-brand-sub">Testimonials Manager</span></a>
      <div id="nav-right" class="d-flex align-items-center gap-2"></div>
    </div>
  </nav>
  <main id="app" class="container-fluid py-4"></main>
  <div id="toasts" class="toast-container position-fixed bottom-0 end-0 p-3"></div>

  <script src="https://cdn.jsdelivr.net/npm/jquery@3.7.1/dist/jquery.min.js"></script>
  <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
  <script src="assets/js/api.js"></script>
  <script src="assets/js/toast.js"></script>
  <script src="assets/js/router.js"></script>
  <script src="assets/js/app.js"></script>
</body>
</html>
```

`public/assets/css/app.css`:
```css
:root {
  --tm-primary: #0060B8;
  --tm-primary-dark: #004C93;
  --tm-secondary: #FFD721;
  --tm-accent: #EE834D;
  --tm-dark: #282835;
  --tm-text: #3E3E3E;
  --tm-light: #F4F4F4;
  --bs-primary: var(--tm-primary);
  --bs-primary-rgb: 0, 96, 184;
  --bs-body-color: var(--tm-text);
  --bs-body-bg: #FDFDFD;
  --bs-body-font-family: "Roboto", system-ui, sans-serif;
  --bs-link-color: var(--tm-primary);
}
h1, h2, h3, h4, h5, .tm-heading { font-family: "Montserrat", system-ui, sans-serif; font-weight: 600; }
.tm-navbar { background: var(--tm-dark); }
.tm-brand { font-family: "Montserrat", sans-serif; font-weight: 800; letter-spacing: .12em; color: #fff; }
.tm-brand-sub { margin-left: .75rem; padding-left: .75rem; border-left: 1px solid rgba(255,255,255,.3); font-weight: 400; color: rgba(255,255,255,.85); }
.btn-primary { --bs-btn-bg: var(--tm-primary); --bs-btn-border-color: var(--tm-primary); --bs-btn-hover-bg: var(--tm-primary-dark); --bs-btn-hover-border-color: var(--tm-primary-dark); }
.btn-secondary-yellow { background: var(--tm-secondary); border-color: var(--tm-secondary); color: var(--tm-dark); font-weight: 500; }
.card { border: 0; box-shadow: 0 1px 3px rgba(40,40,53,.08); }
.table thead th { font-family: "Montserrat", sans-serif; font-weight: 600; font-size: .8rem; text-transform: uppercase; letter-spacing: .04em; color: #6c757d; }
.tm-sortable { cursor: pointer; user-select: none; }
.tm-sortable.active { color: var(--tm-primary); }
.tm-count-badge { background: var(--tm-light); color: var(--tm-text); font-weight: 500; }
.tm-save-status { font-size: .8rem; }
.tm-save-status.saving { color: #6c757d; }
.tm-save-status.saved { color: #198754; }
.tm-save-status.failed { color: #dc3545; }
```

`public/assets/js/api.js`:
```js
(function () {
  'use strict';

  class ApiError extends Error {
    constructor(status, code, message, fields) {
      super(message);
      this.status = status;
      this.code = code;
      this.fields = fields || {};
    }
  }

  async function send(method, path, body, isForm) {
    const headers = { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' };
    const init = { method, headers, credentials: 'same-origin' };
    if (body !== undefined && body !== null) {
      if (isForm) {
        init.body = body;
      } else {
        headers['Content-Type'] = 'application/json';
        init.body = JSON.stringify(body);
      }
    }
    let res;
    try {
      res = await fetch(path, init);
    } catch (e) {
      throw new ApiError(0, 'network_error', 'Network error — the server could not be reached');
    }
    if (res.status === 204) return null;
    let json = null;
    try { json = await res.json(); } catch (e) { /* non-JSON body */ }
    if (!res.ok) {
      const err = (json && json.error) || {};
      const ex = new ApiError(res.status, err.code || 'http_error', err.message || res.statusText, err.fields);
      if (res.status === 401 && window.App && window.App.onUnauthorized) window.App.onUnauthorized();
      throw ex;
    }
    return json;
  }

  window.ApiError = ApiError;
  window.Api = {
    get: (path) => send('GET', path),
    post: (path, body) => send('POST', path, body === undefined ? {} : body),
    patch: (path, body) => send('PATCH', path, body),
    del: (path) => send('DELETE', path),
    upload: (path, formData) => send('POST', path, formData, true),
  };
})();
```

`public/assets/js/toast.js`:
```js
(function () {
  'use strict';
  const container = document.getElementById('toasts');
  function show(message, variant, delay) {
    const el = document.createElement('div');
    el.className = 'toast align-items-center text-bg-' + variant + ' border-0';
    el.setAttribute('role', 'alert');
    el.innerHTML = '<div class="d-flex"><div class="toast-body"></div><button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast" aria-label="Close"></button></div>';
    el.querySelector('.toast-body').textContent = message;
    container.appendChild(el);
    const t = new bootstrap.Toast(el, { delay });
    el.addEventListener('hidden.bs.toast', () => el.remove());
    t.show();
  }
  window.Toast = {
    success: (m) => show(m, 'success', 3000),
    info: (m) => show(m, 'dark', 3000),
    error: (m) => show(m, 'danger', 8000),
  };
})();
```

`public/assets/js/router.js`:
```js
(function () {
  'use strict';
  const routes = [];
  function compile(pattern) {
    const names = [];
    const re = new RegExp('^' + pattern.replace(/\{(\w+)\}/g, (_, n) => { names.push(n); return '([^/?]+)'; }) + '$');
    return { re, names };
  }
  function parse(hash) {
    const h = hash || '#/products';
    const [path, qs] = h.split('?');
    const query = {};
    if (qs) new URLSearchParams(qs).forEach((v, k) => { query[k] = v; });
    return { path, query };
  }
  function dispatch() {
    const { path, query } = parse(location.hash);
    for (const r of routes) {
      const m = r.re.exec(path);
      if (m) {
        const params = {};
        r.names.forEach((n, i) => { params[n] = decodeURIComponent(m[i + 1]); });
        r.handler(params, query);
        return;
      }
    }
    document.getElementById('app').innerHTML = '<div class="alert alert-warning">Page not found.</div>';
  }
  window.Router = {
    register(pattern, handler) { routes.push(Object.assign(compile(pattern), { handler })); },
    navigate(hash) { if (location.hash === hash) dispatch(); else location.hash = hash; },
    start() { window.addEventListener('hashchange', dispatch); dispatch(); },
  };
})();
```

`public/assets/js/app.js`:
```js
(function () {
  'use strict';
  window.App = {
    el: document.getElementById('app'),
    setNav(html) { document.getElementById('nav-right').innerHTML = html; },
    onUnauthorized: null,
  };

  Router.register('#/products', function () {
    App.el.innerHTML = '<h1 class="h3">Products</h1><p class="text-muted">Product search arrives in phase 4.</p>';
  });

  Router.start();
})();
```

- [ ] **Step 2: Verify in a browser and via the API suite**

```bash
make up && make api
```
Open http://localhost:8080 — dark navbar with the DFVU wordmark, "Products" heading. `make api` still green.

- [ ] **Step 3: Commit, merge phase 2, log time**

```bash
git add -A && git commit -m "feat(ui): SPA shell with DFVU styling, api/toast/router helpers"
```
Append phase 2 row to `docs/time-log.md`, commit, push, `gh pr create --title "Phase 2: HTTP core"`, merge, back to `main`.

---

## Phase 3 — Landing sync

### Task 10: Upstream client (interface, curl, fixture)

**Files:**
- Create: `src/Infrastructure/Upstream/LandingsApiClientInterface.php`, `src/Infrastructure/Upstream/CurlLandingsApiClient.php`, `src/Infrastructure/Upstream/FixtureLandingsApiClient.php`, `src/Infrastructure/Upstream/HttpTransportInterface.php`, `src/Infrastructure/Upstream/CurlTransport.php`, `tests/Unit/Infrastructure/Upstream/CurlLandingsApiClientTest.php`, `tests/Unit/Infrastructure/Upstream/FixtureLandingsApiClientTest.php`

**Interfaces:**
- `LandingRow` shape (array, documented via phpstan type alias in the interface): `array{id:int, parent_sku:string, country:string, is_master:bool, url:string, title:string, description:?string, image:?string, status:?string}`.
- `LandingsApiClientInterface { /** @return list<LandingRow> */ public function fetchAll(): array; }`
- `HttpTransportInterface { /** @return array{status:int, body:string} */ public function get(string $url, array $headers): array; }` — `CurlTransport` implements it with a 20 s timeout; the client is tested with a fake transport.
- `CurlLandingsApiClient::__construct(HttpTransportInterface $transport, string $baseUrl, string $apiKey, int $pageSize = 1000)` — pages with `limit`/`offset` until `count < limit`; throws `UpstreamException` on non-200, invalid JSON, or missing `data`.
- `FixtureLandingsApiClient::__construct(array $rows)` and `static fromFile(string $path)`.

- [ ] **Step 1: Branch and failing tests**

```bash
git checkout -b phase/03-sync
```

`tests/Unit/Infrastructure/Upstream/CurlLandingsApiClientTest.php`:
```php
<?php

declare(strict_types=1);

namespace Tests\Unit\Infrastructure\Upstream;

use App\Domain\Exception\UpstreamException;
use App\Infrastructure\Upstream\CurlLandingsApiClient;
use App\Infrastructure\Upstream\HttpTransportInterface;
use PHPUnit\Framework\TestCase;

final class CurlLandingsApiClientTest extends TestCase
{
    /** @param list<array{status:int,body:string}> $responses */
    private function transport(array $responses, array &$calls): HttpTransportInterface
    {
        return new class ($responses, $calls) implements HttpTransportInterface {
            public function __construct(private array $responses, private array &$calls) {}
            public function get(string $url, array $headers): array
            {
                $this->calls[] = ['url' => $url, 'headers' => $headers];
                return array_shift($this->responses) ?? ['status' => 500, 'body' => ''];
            }
        };
    }

    private function page(array $rows, int $limit, int $offset, int $total): string
    {
        return json_encode(['data' => $rows, 'meta' => ['count' => count($rows), 'total' => $total, 'limit' => $limit, 'offset' => $offset]], JSON_THROW_ON_ERROR);
    }

    private function row(int $id, string $cc = 'EN'): array
    {
        return ['id' => $id, 'parent_sku' => 'sku', 'country' => $cc, 'is_master' => $cc === 'EN', 'url' => "https://x/$cc", 'title' => 't', 'description' => 'd', 'image' => 'i', 'status' => 's'];
    }

    public function testPagesUntilShortPageAndSendsApiKey(): void
    {
        $calls = [];
        $t = $this->transport([
            ['status' => 200, 'body' => $this->page([$this->row(1), $this->row(2, 'SI')], 2, 0, 3)],
            ['status' => 200, 'body' => $this->page([$this->row(3, 'IT')], 2, 2, 3)],
        ], $calls);
        $client = new CurlLandingsApiClient($t, 'https://api.test/landings.php', 'secret', 2);
        $rows = $client->fetchAll();
        self::assertSame([1, 2, 3], array_column($rows, 'id'));
        self::assertCount(2, $calls);
        self::assertStringContainsString('limit=2&offset=0', $calls[0]['url']);
        self::assertStringContainsString('limit=2&offset=2', $calls[1]['url']);
        self::assertContains('X-Api-Key: secret', $calls[0]['headers']);
        self::assertTrue($rows[0]['is_master']);
        self::assertIsInt($rows[0]['id']);
    }

    public function testNon200IsUpstreamException(): void
    {
        $calls = [];
        $client = new CurlLandingsApiClient($this->transport([['status' => 401, 'body' => 'nope']], $calls), 'https://api.test', 'k');
        $this->expectException(UpstreamException::class);
        $client->fetchAll();
    }

    public function testInvalidJsonIsUpstreamException(): void
    {
        $calls = [];
        $client = new CurlLandingsApiClient($this->transport([['status' => 200, 'body' => '<html>']], $calls), 'https://api.test', 'k');
        $this->expectException(UpstreamException::class);
        $client->fetchAll();
    }
}
```

`tests/Unit/Infrastructure/Upstream/FixtureLandingsApiClientTest.php`:
```php
<?php

declare(strict_types=1);

namespace Tests\Unit\Infrastructure\Upstream;

use App\Infrastructure\Upstream\FixtureLandingsApiClient;
use PHPUnit\Framework\TestCase;

final class FixtureLandingsApiClientTest extends TestCase
{
    public function testLoadsCapturedFixture(): void
    {
        $client = FixtureLandingsApiClient::fromFile(dirname(__DIR__, 4) . '/tests/fixtures/landings.json');
        $rows = $client->fetchAll();
        self::assertCount(170, $rows);
        self::assertSame(10, count(array_filter($rows, fn ($r) => $r['is_master'])));
        self::assertSame(['id', 'parent_sku', 'country', 'is_master', 'url', 'title', 'description', 'image', 'status'], array_keys($rows[0]));
    }
}
```

- [ ] **Step 2: Run — expect class-not-found**

```bash
make unit
```

- [ ] **Step 3: Implement**

`src/Infrastructure/Upstream/LandingsApiClientInterface.php`:
```php
<?php

declare(strict_types=1);

namespace App\Infrastructure\Upstream;

/**
 * @phpstan-type LandingRow array{id:int, parent_sku:string, country:string, is_master:bool, url:string, title:string, description:?string, image:?string, status:?string}
 */
interface LandingsApiClientInterface
{
    /** @return list<LandingRow> */
    public function fetchAll(): array;
}
```

`src/Infrastructure/Upstream/HttpTransportInterface.php`:
```php
<?php

declare(strict_types=1);

namespace App\Infrastructure\Upstream;

interface HttpTransportInterface
{
    /**
     * @param list<string> $headers  "Name: value" lines
     * @return array{status:int, body:string}
     */
    public function get(string $url, array $headers): array;
}
```

`src/Infrastructure/Upstream/CurlTransport.php`:
```php
<?php

declare(strict_types=1);

namespace App\Infrastructure\Upstream;

use App\Domain\Exception\UpstreamException;

final class CurlTransport implements HttpTransportInterface
{
    public function __construct(private readonly int $timeoutSeconds = 20)
    {
    }

    public function get(string $url, array $headers): array
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_TIMEOUT => $this->timeoutSeconds,
            CURLOPT_FOLLOWLOCATION => false,
        ]);
        $body = curl_exec($ch);
        if ($body === false) {
            $err = curl_error($ch);
            curl_close($ch);
            throw new UpstreamException("Upstream request failed: $err");
        }
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);
        return ['status' => $status, 'body' => (string) $body];
    }
}
```

`src/Infrastructure/Upstream/CurlLandingsApiClient.php`:
```php
<?php

declare(strict_types=1);

namespace App\Infrastructure\Upstream;

use App\Domain\Exception\UpstreamException;

/**
 * @phpstan-import-type LandingRow from LandingsApiClientInterface
 */
final class CurlLandingsApiClient implements LandingsApiClientInterface
{
    public function __construct(
        private readonly HttpTransportInterface $transport,
        private readonly string $baseUrl,
        private readonly string $apiKey,
        private readonly int $pageSize = 1000,
    ) {
    }

    public function fetchAll(): array
    {
        $all = [];
        $offset = 0;
        do {
            $sep = str_contains($this->baseUrl, '?') ? '&' : '?';
            $url = $this->baseUrl . $sep . http_build_query(['limit' => $this->pageSize, 'offset' => $offset]);
            $res = $this->transport->get($url, ['X-Api-Key: ' . $this->apiKey, 'Accept: application/json']);
            if ($res['status'] !== 200) {
                throw new UpstreamException("Upstream returned HTTP {$res['status']}");
            }
            $json = json_decode($res['body'], true);
            if (!is_array($json) || !isset($json['data']) || !is_array($json['data'])) {
                throw new UpstreamException('Upstream returned invalid JSON');
            }
            foreach ($json['data'] as $raw) {
                $all[] = self::normalise($raw);
            }
            $count = count($json['data']);
            $offset += $count;
        } while ($count === $this->pageSize);
        return $all;
    }

    /**
     * @param array<string,mixed> $raw
     * @return LandingRow
     */
    public static function normalise(array $raw): array
    {
        foreach (['id', 'parent_sku', 'country', 'url'] as $required) {
            if (!isset($raw[$required])) {
                throw new UpstreamException("Upstream landing is missing '$required'");
            }
        }
        return [
            'id' => (int) $raw['id'],
            'parent_sku' => (string) $raw['parent_sku'],
            'country' => strtoupper((string) $raw['country']),
            'is_master' => (bool) ($raw['is_master'] ?? false),
            'url' => (string) $raw['url'],
            'title' => (string) ($raw['title'] ?? ''),
            'description' => isset($raw['description']) ? (string) $raw['description'] : null,
            'image' => isset($raw['image']) ? (string) $raw['image'] : null,
            'status' => isset($raw['status']) ? (string) $raw['status'] : null,
        ];
    }
}
```

`src/Infrastructure/Upstream/FixtureLandingsApiClient.php`:
```php
<?php

declare(strict_types=1);

namespace App\Infrastructure\Upstream;

/**
 * Replays a captured upstream response. Used by tests and by `APP_ENV=test` deployments without upstream access.
 * @phpstan-import-type LandingRow from LandingsApiClientInterface
 */
final class FixtureLandingsApiClient implements LandingsApiClientInterface
{
    /** @param list<LandingRow> $rows */
    public function __construct(private readonly array $rows)
    {
    }

    public static function fromFile(string $path): self
    {
        $json = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        return new self(array_map([CurlLandingsApiClient::class, 'normalise'], $json['data']));
    }

    public function fetchAll(): array
    {
        return $this->rows;
    }
}
```

- [ ] **Step 4: Green + commit**

```bash
make unit && make lint && make stan
git add -A && git commit -m "feat(sync): upstream landings client with curl transport and fixture replay"
```

### Task 11: Product, Landing and SyncRun repositories

**Files:**
- Create: `src/Infrastructure/Repository/ProductRepository.php`, `src/Infrastructure/Repository/LandingRepository.php`, `src/Infrastructure/Repository/SyncRunRepository.php`, `tests/Integration/Repository/LandingRepositoryTest.php`, `tests/Integration/Repository/SyncRunRepositoryTest.php`

**Interfaces:**
- `ProductRepository::__construct(\PDO $pdo)`
  - `upsertMany(list<array{parent_sku:string,title:string,description:?string,image:?string}> $products): void` — `INSERT … ON DUPLICATE KEY UPDATE title, description, image`.
  - `idsBySku(): array<string,int>`.
- `LandingRepository::__construct(\PDO $pdo)`
  - `upsertMany(list<LandingRow & array{product_id:int}> $rows, string $syncedAt): array{added:int, updated:int}` — uses `rowCount()` (1 = inserted, 2 = updated, 0 = unchanged); update sets `removed_at = NULL`.
  - `markRemovedExcept(list<int> $ids, string $now): int` — sets `removed_at` on active landings not in `$ids`; returns affected count.
  - `find(int $id): ?array` (raw row incl. `removed_at`).
- `SyncRunRepository::__construct(\PDO $pdo)`: `start(string $startedAt): int`, `finish(int $id, string $status, string $finishedAt, int $added, int $updated, int $removed, ?string $error): void`, `last(): ?array`.

- [ ] **Step 1: Failing tests**

`tests/Integration/Repository/LandingRepositoryTest.php`:
```php
<?php

declare(strict_types=1);

namespace Tests\Integration\Repository;

use App\Infrastructure\Repository\LandingRepository;
use App\Infrastructure\Repository\ProductRepository;
use Tests\Integration\DatabaseTestCase;

final class LandingRepositoryTest extends DatabaseTestCase
{
    private LandingRepository $landings;
    private ProductRepository $products;

    protected function setUp(): void
    {
        parent::setUp();
        $this->landings = new LandingRepository(self::$pdo);
        $this->products = new ProductRepository(self::$pdo);
        $this->products->upsertMany([['parent_sku' => 'abforge', 'title' => 'AbForge', 'description' => null, 'image' => null]]);
    }

    private function row(int $id, string $cc, string $url = 'https://x'): array
    {
        return ['id' => $id, 'product_id' => $this->products->idsBySku()['abforge'], 'country' => $cc, 'is_master' => $cc === 'EN', 'url' => $url, 'title' => 't', 'description' => null, 'image' => null, 'status' => null];
    }

    public function testUpsertCountsAddedUpdatedUnchanged(): void
    {
        $r1 = $this->landings->upsertMany([$this->row(1, 'EN'), $this->row(2, 'SI')], '2026-09-13 10:00:00');
        self::assertSame(['added' => 2, 'updated' => 0], $r1);
        $r2 = $this->landings->upsertMany([$this->row(1, 'EN'), $this->row(2, 'SI', 'https://changed')], '2026-09-13 11:00:00');
        self::assertSame(['added' => 0, 'updated' => 1], $r2);
        self::assertSame('https://changed', $this->landings->find(2)['url']);
    }

    public function testMarkRemovedExceptAndReappear(): void
    {
        $this->landings->upsertMany([$this->row(1, 'EN'), $this->row(2, 'SI')], '2026-09-13 10:00:00');
        self::assertSame(1, $this->landings->markRemovedExcept([1], '2026-09-13 10:00:00'));
        self::assertNotNull($this->landings->find(2)['removed_at']);
        $this->landings->upsertMany([$this->row(2, 'SI')], '2026-09-13 12:00:00');
        self::assertNull($this->landings->find(2)['removed_at']);
    }

    public function testProductIdsBySku(): void
    {
        $this->products->upsertMany([['parent_sku' => 'zeta', 'title' => 'Z', 'description' => 'd', 'image' => 'i'], ['parent_sku' => 'abforge', 'title' => 'AbForge v2', 'description' => null, 'image' => null]]);
        $ids = $this->products->idsBySku();
        self::assertSame(['abforge', 'zeta'], array_keys($ids));
        self::assertSame('AbForge v2', self::$pdo->query("SELECT title FROM products WHERE parent_sku='abforge'")->fetchColumn());
        self::assertSame(2, (int) self::$pdo->query('SELECT COUNT(*) FROM products')->fetchColumn());
    }
}
```

`tests/Integration/Repository/SyncRunRepositoryTest.php`:
```php
<?php

declare(strict_types=1);

namespace Tests\Integration\Repository;

use App\Infrastructure\Repository\SyncRunRepository;
use Tests\Integration\DatabaseTestCase;

final class SyncRunRepositoryTest extends DatabaseTestCase
{
    public function testStartFinishLast(): void
    {
        $repo = new SyncRunRepository(self::$pdo);
        self::assertNull($repo->last());
        $id = $repo->start('2026-09-13 10:00:00');
        $repo->finish($id, 'ok', '2026-09-13 10:00:02', 3, 2, 1, null);
        $last = $repo->last();
        self::assertSame($id, $last['id']);
        self::assertSame('ok', $last['status']);
        self::assertSame(3, $last['added']);
        self::assertSame('2026-09-13 10:00:02', $last['finished_at']);
    }
}
```

- [ ] **Step 2: Run — expect failures**

```bash
make integration
```

- [ ] **Step 3: Implement**

`src/Infrastructure/Repository/ProductRepository.php`:
```php
<?php

declare(strict_types=1);

namespace App\Infrastructure\Repository;

final class ProductRepository
{
    public function __construct(private readonly \PDO $pdo)
    {
    }

    /** @param list<array{parent_sku:string,title:string,description:?string,image:?string}> $products */
    public function upsertMany(array $products): void
    {
        if ($products === []) {
            return;
        }
        $stmt = $this->pdo->prepare(
            'INSERT INTO products (parent_sku, title, description, image) VALUES (:sku, :title, :description, :image)
             ON DUPLICATE KEY UPDATE title = VALUES(title), description = VALUES(description), image = VALUES(image)',
        );
        foreach ($products as $p) {
            $stmt->execute(['sku' => $p['parent_sku'], 'title' => $p['title'], 'description' => $p['description'], 'image' => $p['image']]);
        }
    }

    /** @return array<string,int> */
    public function idsBySku(): array
    {
        $map = [];
        foreach ($this->pdo->query('SELECT id, parent_sku FROM products ORDER BY parent_sku') as $row) {
            $map[$row['parent_sku']] = (int) $row['id'];
        }
        return $map;
    }
}
```

`src/Infrastructure/Repository/LandingRepository.php`:
```php
<?php

declare(strict_types=1);

namespace App\Infrastructure\Repository;

final class LandingRepository
{
    public function __construct(private readonly \PDO $pdo)
    {
    }

    /**
     * @param list<array{id:int,product_id:int,country:string,is_master:bool,url:string,title:string,description:?string,image:?string,status:?string}> $rows
     * @return array{added:int,updated:int}
     */
    public function upsertMany(array $rows, string $syncedAt): array
    {
        $before = $this->snapshot(array_column($rows, 'id'));
        $stmt = $this->pdo->prepare(
            'INSERT INTO landings (id, product_id, country, is_master, url, title, description, image, status, last_synced_at)
             VALUES (:id, :product_id, :country, :is_master, :url, :title, :description, :image, :status, :synced_at)
             ON DUPLICATE KEY UPDATE
               product_id = VALUES(product_id), country = VALUES(country), is_master = VALUES(is_master),
               url = VALUES(url), title = VALUES(title), description = VALUES(description), image = VALUES(image),
               status = VALUES(status), removed_at = NULL, last_synced_at = VALUES(last_synced_at)',
        );
        $added = 0;
        $updated = 0;
        foreach ($rows as $r) {
            $stmt->execute([
                'id' => $r['id'], 'product_id' => $r['product_id'], 'country' => $r['country'], 'is_master' => (int) $r['is_master'],
                'url' => $r['url'], 'title' => $r['title'], 'description' => $r['description'], 'image' => $r['image'],
                'status' => $r['status'], 'synced_at' => $syncedAt,
            ]);
            if (!isset($before[$r['id']])) {
                $added++;
            } elseif ($before[$r['id']] !== $this->comparable($r) || $before[$r['id']]['removed'] === true) {
                $updated++;
            }
        }
        return ['added' => $added, 'updated' => $updated];
    }

    /** @param list<int> $ids @return array<int, array<string,mixed>> */
    private function snapshot(array $ids): array
    {
        if ($ids === []) {
            return [];
        }
        $marks = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $this->pdo->prepare("SELECT id, product_id, country, is_master, url, title, description, image, status, removed_at FROM landings WHERE id IN ($marks)");
        $stmt->execute($ids);
        $map = [];
        foreach ($stmt->fetchAll() as $row) {
            $map[(int) $row['id']] = $this->comparable($row) + ['removed' => $row['removed_at'] !== null];
        }
        return $map;
    }

    /** @param array<string,mixed> $r @return array<string,mixed> */
    private function comparable(array $r): array
    {
        return [
            'product_id' => (int) $r['product_id'], 'country' => (string) $r['country'], 'is_master' => (bool) $r['is_master'],
            'url' => (string) $r['url'], 'title' => (string) $r['title'], 'description' => $r['description'] === null ? null : (string) $r['description'],
            'image' => $r['image'] === null ? null : (string) $r['image'], 'status' => $r['status'] === null ? null : (string) $r['status'],
            'removed' => false,
        ];
    }

    /** @param list<int> $keepIds */
    public function markRemovedExcept(array $keepIds, string $now): int
    {
        if ($keepIds === []) {
            $stmt = $this->pdo->prepare('UPDATE landings SET removed_at = ? WHERE removed_at IS NULL');
            $stmt->execute([$now]);
            return $stmt->rowCount();
        }
        $marks = implode(',', array_fill(0, count($keepIds), '?'));
        $stmt = $this->pdo->prepare("UPDATE landings SET removed_at = ? WHERE removed_at IS NULL AND id NOT IN ($marks)");
        $stmt->execute([$now, ...$keepIds]);
        return $stmt->rowCount();
    }

    /** @return array<string,mixed>|null */
    public function find(int $id): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM landings WHERE id = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        return $row === false ? null : $row;
    }
}
```
(In `comparable()` the `'removed' => false` entry exists so that the `!==` comparison in `upsertMany` has identical keys on both sides; `snapshot()` overrides it.)

`src/Infrastructure/Repository/SyncRunRepository.php`:
```php
<?php

declare(strict_types=1);

namespace App\Infrastructure\Repository;

final class SyncRunRepository
{
    public function __construct(private readonly \PDO $pdo)
    {
    }

    public function start(string $startedAt): int
    {
        $this->pdo->prepare('INSERT INTO sync_runs (started_at, status) VALUES (?, ?)')->execute([$startedAt, 'running']);
        return (int) $this->pdo->lastInsertId();
    }

    public function finish(int $id, string $status, string $finishedAt, int $added, int $updated, int $removed, ?string $error): void
    {
        $this->pdo->prepare('UPDATE sync_runs SET status = ?, finished_at = ?, added = ?, updated = ?, removed = ?, error_message = ? WHERE id = ?')
            ->execute([$status, $finishedAt, $added, $updated, $removed, $error, $id]);
    }

    /** @return array<string,mixed>|null */
    public function last(): ?array
    {
        $row = $this->pdo->query('SELECT * FROM sync_runs ORDER BY id DESC LIMIT 1')->fetch();
        if ($row === false) {
            return null;
        }
        foreach (['id', 'added', 'updated', 'removed'] as $k) {
            $row[$k] = (int) $row[$k];
        }
        return $row;
    }
}
```

- [ ] **Step 4: Green + commit**

```bash
make integration && make lint && make stan
git add -A && git commit -m "feat(sync): product, landing and sync-run repositories with upsert semantics"
```

### Task 12: `LandingSyncService`

**Files:**
- Create: `src/Application/LandingSyncService.php`, `src/Support/Clock.php`, `tests/Integration/Application/LandingSyncServiceTest.php`

**Interfaces:**
- `App\Support\Clock { public function now(): \DateTimeImmutable; }` (UTC) — `FrozenClock` for tests lives in the test file.
- `LandingSyncService::__construct(\PDO $pdo, LandingsApiClientInterface $client, ProductRepository $products, LandingRepository $landings, SyncRunRepository $runs, Clock $clock)`
- `run(): array{id:int, added:int, updated:int, removed:int, duration_ms:int}` — throws `ConflictException` when another run holds the lock, `UpstreamException` when fetch fails (run recorded as `failed`).

- [ ] **Step 1: Failing tests**

`tests/Integration/Application/LandingSyncServiceTest.php`:
```php
<?php

declare(strict_types=1);

namespace Tests\Integration\Application;

use App\Application\LandingSyncService;
use App\Domain\Exception\UpstreamException;
use App\Infrastructure\Repository\LandingRepository;
use App\Infrastructure\Repository\ProductRepository;
use App\Infrastructure\Repository\SyncRunRepository;
use App\Infrastructure\Upstream\FixtureLandingsApiClient;
use App\Infrastructure\Upstream\LandingsApiClientInterface;
use App\Support\Clock;
use Tests\Integration\DatabaseTestCase;

final class LandingSyncServiceTest extends DatabaseTestCase
{
    /** @var list<array<string,mixed>> */
    private array $fixture;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fixture = FixtureLandingsApiClient::fromFile(dirname(__DIR__, 3) . '/tests/fixtures/landings.json')->fetchAll();
    }

    private function service(LandingsApiClientInterface $client): LandingSyncService
    {
        $clock = new class implements Clock {
            public function now(): \DateTimeImmutable { return new \DateTimeImmutable('2026-09-13 10:00:00', new \DateTimeZone('UTC')); }
        };
        return new LandingSyncService(self::$pdo, $client, new ProductRepository(self::$pdo), new LandingRepository(self::$pdo), new SyncRunRepository(self::$pdo), $clock);
    }

    public function testFirstRunImportsEverything(): void
    {
        $result = $this->service(new FixtureLandingsApiClient($this->fixture))->run();
        self::assertSame(170, $result['added']);
        self::assertSame(0, $result['updated']);
        self::assertSame(0, $result['removed']);
        self::assertSame(10, (int) self::$pdo->query('SELECT COUNT(*) FROM products')->fetchColumn());
        self::assertSame(170, (int) self::$pdo->query('SELECT COUNT(*) FROM landings')->fetchColumn());
        $master = self::$pdo->query("SELECT p.title FROM products p WHERE p.parent_sku = 'abforge'")->fetchColumn();
        self::assertSame('Strengthen your abs with smart rebound power!', $master);
        self::assertSame('ok', (new SyncRunRepository(self::$pdo))->last()['status']);
    }

    public function testSecondRunIsIdempotent(): void
    {
        $this->service(new FixtureLandingsApiClient($this->fixture))->run();
        $result = $this->service(new FixtureLandingsApiClient($this->fixture))->run();
        self::assertSame(['added' => 0, 'updated' => 0, 'removed' => 0], array_intersect_key($result, ['added' => 1, 'updated' => 1, 'removed' => 1]));
    }

    public function testResyncKeepsIdsAndTestimonials(): void
    {
        $this->service(new FixtureLandingsApiClient($this->fixture))->run();
        $landingId = $this->fixture[5]['id'];
        $testimonialId = self::insert('testimonials', ['landing_id' => $landingId, 'author_name' => 'A', 'text' => 'T', 'rating' => 5, 'gender' => 'male', 'sort_order' => 0]);

        $changed = $this->fixture;
        $changed[5]['url'] = 'https://changed.example/x';
        $removed = array_pop($changed);
        $changed[] = ['id' => 999999, 'parent_sku' => 'newprod', 'country' => 'EN', 'is_master' => true, 'url' => 'https://n', 'title' => 'New', 'description' => null, 'image' => null, 'status' => null];

        $result = $this->service(new FixtureLandingsApiClient($changed))->run();
        self::assertSame(1, $result['added']);
        self::assertSame(1, $result['updated']);
        self::assertSame(1, $result['removed']);

        self::assertSame('https://changed.example/x', self::$pdo->query("SELECT url FROM landings WHERE id = $landingId")->fetchColumn());
        self::assertSame($landingId, (int) self::$pdo->query("SELECT landing_id FROM testimonials WHERE id = $testimonialId")->fetchColumn());
        self::assertNotNull(self::$pdo->query("SELECT removed_at FROM landings WHERE id = {$removed['id']}")->fetchColumn());
        self::assertSame(11, (int) self::$pdo->query('SELECT COUNT(*) FROM products')->fetchColumn());
    }

    public function testUpstreamFailureIsRecordedAndRethrown(): void
    {
        $client = new class implements LandingsApiClientInterface {
            public function fetchAll(): array { throw new UpstreamException('down'); }
        };
        try {
            $this->service($client)->run();
            self::fail('expected UpstreamException');
        } catch (UpstreamException) {
        }
        $last = (new SyncRunRepository(self::$pdo))->last();
        self::assertSame('failed', $last['status']);
        self::assertSame('down', $last['error_message']);
        self::assertSame(0, (int) self::$pdo->query('SELECT COUNT(*) FROM landings')->fetchColumn());
    }
}
```

- [ ] **Step 2: Run — expect failures**

```bash
make integration
```

- [ ] **Step 3: Implement**

`src/Support/Clock.php`:
```php
<?php

declare(strict_types=1);

namespace App\Support;

interface Clock
{
    public function now(): \DateTimeImmutable;
}
```
plus `src/Support/SystemClock.php`:
```php
<?php

declare(strict_types=1);

namespace App\Support;

final class SystemClock implements Clock
{
    public function now(): \DateTimeImmutable
    {
        return new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
    }
}
```

`src/Application/LandingSyncService.php`:
```php
<?php

declare(strict_types=1);

namespace App\Application;

use App\Domain\Exception\ConflictException;
use App\Infrastructure\Repository\LandingRepository;
use App\Infrastructure\Repository\ProductRepository;
use App\Infrastructure\Repository\SyncRunRepository;
use App\Infrastructure\Upstream\LandingsApiClientInterface;
use App\Support\Clock;

/**
 * Synchronises the local landings/products tables with the upstream GET /landings feed.
 * Upsert by upstream id — never truncates — so testimonials keep their landing reference.
 */
final class LandingSyncService
{
    private const LOCK_NAME = 'testimonials_landing_sync';

    public function __construct(
        private readonly \PDO $pdo,
        private readonly LandingsApiClientInterface $client,
        private readonly ProductRepository $products,
        private readonly LandingRepository $landings,
        private readonly SyncRunRepository $runs,
        private readonly Clock $clock,
    ) {
    }

    /** @return array{id:int,added:int,updated:int,removed:int,duration_ms:int} */
    public function run(): array
    {
        $lock = $this->pdo->query("SELECT GET_LOCK('" . self::LOCK_NAME . "', 0)")->fetchColumn();
        if ((int) $lock !== 1) {
            throw new ConflictException('A sync is already running');
        }
        $startedAt = $this->clock->now();
        $runId = $this->runs->start($startedAt->format('Y-m-d H:i:s'));
        $t0 = hrtime(true);
        try {
            $rows = $this->client->fetchAll();
            $this->pdo->beginTransaction();
            try {
                $stats = $this->apply($rows, $startedAt->format('Y-m-d H:i:s'));
                $this->pdo->commit();
            } catch (\Throwable $e) {
                $this->pdo->rollBack();
                throw $e;
            }
            $this->runs->finish($runId, 'ok', $this->clock->now()->format('Y-m-d H:i:s'), $stats['added'], $stats['updated'], $stats['removed'], null);
            return ['id' => $runId, 'duration_ms' => (int) ((hrtime(true) - $t0) / 1_000_000)] + $stats;
        } catch (\Throwable $e) {
            $this->runs->finish($runId, 'failed', $this->clock->now()->format('Y-m-d H:i:s'), 0, 0, 0, $e->getMessage());
            throw $e;
        } finally {
            $this->pdo->query("SELECT RELEASE_LOCK('" . self::LOCK_NAME . "')");
        }
    }

    /**
     * @param list<array{id:int,parent_sku:string,country:string,is_master:bool,url:string,title:string,description:?string,image:?string,status:?string}> $rows
     * @return array{added:int,updated:int,removed:int}
     */
    private function apply(array $rows, string $now): array
    {
        // 1. products: one per parent_sku, described by its master landing (fallback: first landing seen)
        $products = [];
        foreach ($rows as $r) {
            $sku = $r['parent_sku'];
            if (!isset($products[$sku]) || $r['is_master']) {
                $products[$sku] = ['parent_sku' => $sku, 'title' => $r['title'], 'description' => $r['description'], 'image' => $r['image']];
            }
        }
        $this->products->upsertMany(array_values($products));
        $ids = $this->products->idsBySku();

        // 2. landings: upsert by upstream id
        $landingRows = [];
        foreach ($rows as $r) {
            $landingRows[] = $r + ['product_id' => $ids[$r['parent_sku']]];
        }
        $result = $this->landings->upsertMany($landingRows, $now);

        // 3. anything not in the feed any more is soft-deleted; its testimonials stay
        $removed = $this->landings->markRemovedExcept(array_column($rows, 'id'), $now);

        return ['added' => $result['added'], 'updated' => $result['updated'], 'removed' => $removed];
    }
}
```

- [ ] **Step 4: Green + commit**

```bash
make integration && make lint && make stan
git add -A && git commit -m "feat(sync): LandingSyncService with locking, transaction and soft-delete of vanished landings"
```

### Task 13: Sync endpoints, CLI, navbar button

**Files:**
- Create: `src/Http/Controller/SyncController.php`, `bin/sync.php`, `tests/Api/SyncTest.php`, `public/assets/js/sync.js`
- Modify: `config/container.php`, `config/routes.php`, `public/index.html`, `public/assets/js/app.js`

**Interfaces:**
- `POST /api/landings/sync` → 200 `{run:{id,added,updated,removed,duration_ms}}`; 409 when locked; 502 when upstream fails.
- `GET /api/sync/last` → 200 `{run:{id,started_at,finished_at,status,added,updated,removed,error_message}}` or 404.
- Container: `LandingsApiClientInterface::class` resolves to `FixtureLandingsApiClient::fromFile(root/tests/fixtures/landings.json)` when `APP_ENV=test`, else `CurlLandingsApiClient(new CurlTransport(), url, key)`. CI sets `APP_ENV=test` so API tests never hit the real upstream.

- [ ] **Step 1: Failing API test**

`tests/Api/SyncTest.php`:
```php
<?php

declare(strict_types=1);

namespace Tests\Api;

final class SyncTest extends ApiTestCase
{
    public function testSyncRunsAndReportsCounts(): void
    {
        $r = $this->request('POST', '/api/landings/sync');
        self::assertSame(200, $r['status'], json_encode($r['json']));
        self::assertArrayHasKey('added', $r['json']['run']);
        self::assertSame(0, $r['json']['run']['removed']);

        $last = $this->request('GET', '/api/sync/last');
        self::assertSame(200, $last['status']);
        self::assertSame('ok', $last['json']['run']['status']);
    }
}
```

- [ ] **Step 2: Implement**

`src/Http/Controller/SyncController.php`:
```php
<?php

declare(strict_types=1);

namespace App\Http\Controller;

use App\Application\LandingSyncService;
use App\Domain\Exception\NotFoundException;
use App\Http\Request;
use App\Http\Response;
use App\Infrastructure\Repository\SyncRunRepository;

final class SyncController
{
    public function __construct(private readonly LandingSyncService $sync, private readonly SyncRunRepository $runs)
    {
    }

    public function run(Request $request): Response
    {
        return Response::json(['run' => $this->sync->run()]);
    }

    public function last(Request $request): Response
    {
        $run = $this->runs->last();
        if ($run === null) {
            throw new NotFoundException('No sync has run yet');
        }
        return Response::json(['run' => $run]);
    }
}
```

Add to `config/container.php` (imports at top; entries before `return $c;`):
```php
use App\Application\LandingSyncService;
use App\Http\Controller\SyncController;
use App\Infrastructure\Repository\LandingRepository;
use App\Infrastructure\Repository\ProductRepository;
use App\Infrastructure\Repository\SyncRunRepository;
use App\Infrastructure\Upstream\CurlLandingsApiClient;
use App\Infrastructure\Upstream\CurlTransport;
use App\Infrastructure\Upstream\FixtureLandingsApiClient;
use App\Infrastructure\Upstream\LandingsApiClientInterface;
use App\Support\Clock;
use App\Support\SystemClock;

    $c->set(Clock::class, fn () => new SystemClock());
    $c->set(ProductRepository::class, fn (Container $c) => new ProductRepository($c->get(PDO::class)));
    $c->set(LandingRepository::class, fn (Container $c) => new LandingRepository($c->get(PDO::class)));
    $c->set(SyncRunRepository::class, fn (Container $c) => new SyncRunRepository($c->get(PDO::class)));
    $c->set(LandingsApiClientInterface::class, function () use ($config) {
        if ($config['env'] === 'test') {
            return FixtureLandingsApiClient::fromFile($config['root'] . '/tests/fixtures/landings.json');
        }
        return new CurlLandingsApiClient(new CurlTransport(), $config['landings_api']['url'], $config['landings_api']['key']);
    });
    $c->set(LandingSyncService::class, fn (Container $c) => new LandingSyncService(
        $c->get(PDO::class),
        $c->get(LandingsApiClientInterface::class),
        $c->get(ProductRepository::class),
        $c->get(LandingRepository::class),
        $c->get(SyncRunRepository::class),
        $c->get(Clock::class),
    ));
    $c->set(SyncController::class, fn (Container $c) => new SyncController($c->get(LandingSyncService::class), $c->get(SyncRunRepository::class)));
```

Add to `config/routes.php`:
```php
    $r->post('/api/landings/sync', [SyncController::class, 'run']);
    $r->get('/api/sync/last', [SyncController::class, 'last']);
```

`bin/sync.php`:
```php
#!/usr/bin/env php
<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

$config = require dirname(__DIR__) . '/config/config.php';
$container = (require dirname(__DIR__) . '/config/container.php')($config);

$result = $container->get(App\Application\LandingSyncService::class)->run();
printf("Sync #%d: %d added, %d updated, %d removed (%d ms)\n", $result['id'], $result['added'], $result['updated'], $result['removed'], $result['duration_ms']);
```

`public/assets/js/sync.js`:
```js
(function () {
  'use strict';
  function fmt(run) {
    if (!run) return 'never synced';
    const when = run.finished_at || run.started_at;
    return (run.status === 'ok' ? 'Last sync ' : 'Last sync FAILED ') + when + ' UTC';
  }
  async function refresh() {
    const label = document.getElementById('sync-status');
    if (!label) return;
    try {
      const res = await Api.get('/api/sync/last');
      label.textContent = fmt(res.run);
    } catch (e) {
      label.textContent = e.status === 404 ? 'never synced' : '';
    }
  }
  async function runSync(btn) {
    btn.disabled = true;
    btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>Syncing…';
    try {
      const res = await Api.post('/api/landings/sync');
      Toast.success(`Sync done: ${res.run.added} added, ${res.run.updated} updated, ${res.run.removed} removed`);
      document.dispatchEvent(new CustomEvent('tm:synced'));
    } catch (e) {
      Toast.error('Sync failed: ' + e.message);
    } finally {
      btn.disabled = false;
      btn.innerHTML = '<i class="bi bi-arrow-repeat me-1"></i>Sync landings';
      refresh();
    }
  }
  window.Sync = {
    mount() {
      App.setNav('<span id="sync-status" class="text-white-50 small me-2"></span><button id="sync-btn" class="btn btn-sm btn-secondary-yellow"><i class="bi bi-arrow-repeat me-1"></i>Sync landings</button>');
      document.getElementById('sync-btn').addEventListener('click', (e) => runSync(e.currentTarget));
      refresh();
    },
  };
})();
```

In `public/index.html` add `<script src="assets/js/sync.js"></script>` before `app.js`; in `app.js` call `Sync.mount();` before `Router.start();`.

- [ ] **Step 3: Run everything**

```bash
make unit && make integration && make lint && make stan
make up && make api
```
Expected: all green (`APP_ENV` in the container comes from `.env`; set `APP_ENV=test` there while running the API suite, or export it in `docker-compose.yml` for the `app` service — pick the compose route so `make api` needs no manual step and document it in the README dev table). In the browser: the yellow **Sync landings** button runs, toasts the counts, and the status text updates.

- [ ] **Step 4: Commit, merge phase 3, log time**

```bash
git add -A && git commit -m "feat(sync): sync endpoints, CLI runner and navbar sync button"
```
Append phase 3 row to `docs/time-log.md`, commit, push, `gh pr create --title "Phase 3: landing sync"`, merge, `git checkout main && git pull`.

---

## Self-review notes

- **Spec coverage (phases 0–3):** Docker/CI/README (Task 1–3), schema + audit fields + utf8mb4 + indexes + seed + large generator (Task 4–5), container/router/error mapping/CSRF header/health/SPA shell + DFVU look (Task 6–9), upstream client with config-driven URL/key, upsert-by-id, soft-delete, sync_runs, endpoint + button (Task 10–13). Deploy, login, testimonials, images, e2e, bonuses are in later plans as decided.
- **Known follow-ups for plan 2:** `Auth` middleware slot in `Kernel` (phase 7) — the middleware array in `config/container.php` is where it goes; `TestimonialRepository` counts for product search (phase 4).
- **Type consistency check:** `HttpException::getErrorCode()` used in Router, Kernel and tests; `LandingRow` shape identical in client, repository and service; `Request::attribute()` returns `string` — controllers cast ids with `(int)`.
