# Testimonials Manager — Plan 2: Core features (Phases 4–9)

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Deliver every *required* part of the assignment on top of the plan-1 foundation: product search with counts, country overview, testimonial CRUD with EN inheritance and unambiguous saving, multi-image upload with thumbnails, login, a Playwright happy path, and a live Fly.io deployment.

**Architecture:** Same layering as plan 1 — thin controllers in `src/Http/Controller`, services in `src/Application` own validation orchestration and transactions, all SQL in `src/Infrastructure/Repository`, pure validation/resolution logic in `src/Domain`. The SPA gains three views (products → countries → testimonials) plus login, all talking only to `/api/*` through `Api` (api.js). Auth is a PHP session behind an `AuthMiddleware` slot in the existing `Kernel` pipeline.

**Tech Stack:** PHP 8.1+ (no framework, zero runtime Composer deps), MySQL 8 via PDO, GD for thumbnails, Bootstrap 5.3 + jQuery 3.7 from CDN, PHPUnit 10, Playwright 1.47 (TS), Docker, GitHub Actions, Fly.io.

**Spec:** `docs/superpowers/specs/2026-09-13-testimonials-manager-design.md` (sections 4 data model, 5 API, 6 backend, 7 frontend, 9 testing, 10 tooling/hosting). Note §4 was amended in plan 1: `landings (product_id, country)` is an index, not unique (ADR-0003).

## Global Constraints

- PHP `>=8.1` syntax (no `readonly class`, no DNF types), no framework, `composer.json` `require` stays php + extensions only.
- PDO prepared statements for every query that carries a value; identifiers from whitelists only.
- REST + JSON; real status codes; error body `{"error":{"code","message","fields"}}` (fields only on 422).
- Every mutating `/api` request needs `X-Requested-With: XMLHttpRequest` (existing `RequireXhrMiddleware`); from phase 7 every `/api` and `/media` request except `POST /api/auth/login` and `GET /api/health` needs a session.
- Uploads: JPG/PNG/WebP, max `config['upload']['max_bytes']` (5 MB), MIME sniffed server-side, stored as `{uuid}.{ext}` + `{uuid}_thumb.{ext}` in `config['upload']['dir']`, served only through `GET /media/{filename}` after regex + `is_file` checks.
- Rating: `NULL` = random; every API response carries `rating_display` (stored value, or a fresh random in `[4.0, 5.0]` with one decimal).
- Inheritance is resolved at read time: a landing with zero own testimonials returns the EN master's set with `meta.inherited = true`.
- UI: English, Bootstrap 5, DFVU palette tokens already in `public/assets/css/app.css`; explicit Save button in forms; inline toggles show `Saving… / Saved ✓ / Failed ✗`; every failed request → visible toast; deletes confirm first.
- Must still run on XAMPP (`.htaccess` routing, relative asset URLs, `Api` resolves paths against `document.baseURI`).
- All commands run in Docker: `make unit`, `make integration`, `make api`, `make lint`/`make lint-fix`, `make stan`, `make up`, `make seed`. phpstan level 6 (array shapes documented), PSR-12.
- Every commit ends with:
  ```
  Co-Authored-By: Claude Opus 5 <noreply@anthropic.com>
  Claude-Session: https://claude.ai/code/session_014Ux1NSdiKLKNGQHc4VFjuf
  ```
- One branch per phase (`phase/NN-name`), PR titled `Phase N: …`, body ending with the two attribution lines for PRs, merged with `gh pr merge --merge --delete-branch` after CI is green; each phase appends a row to `docs/time-log.md`.

## Existing interfaces this plan builds on (from plan 1)

- `App\Http\Request` — `public readonly string $method, $path; array $query, $body, $headers, $files, $cookies, $attributes`; `query(string $k, mixed $d = null)`, `input(string $k, mixed $d = null)`, `attribute(string $k): string`, `header(string)`, `isXhr()`, `isApi()`, `withAttributes(array)`.
- `App\Http\Response` — `json(mixed, int = 200)`, `noContent()`, `error(int, string, string, array = [])`, `html(string)`, `file(string $path, string $mime)`, `withHeader()`.
- `App\Http\Router` — `get/post/patch/delete(string $pattern, array{0:string,1:string} $handler)`; `{name}` params; HEAD→GET.
- `App\Http\Kernel::__construct(Container, Router, list<MiddlewareInterface>, bool $debug)`; `App\Http\MiddlewareInterface::process(Request, callable $next): Response`.
- `App\Domain\Exception\{HttpException(status, code, message, fields), NotFoundException, ValidationException(array $fields), UnauthorizedException, ForbiddenException, ConflictException, UpstreamException}`.
- `App\Container::set(string $id, callable $factory)`, `get(string $id)`.
- `config/container.php` returns `fn(array $config): Container`; `config/routes.php` returns `fn(Router): void`; `$config` keys: `root, env, debug, db, upload{dir,max_bytes}, landings_api{url,key,fixture}, session{name}`.
- `App\Infrastructure\Repository\ProductRepository(\PDO)`: `upsertMany`, `idsBySku`. `LandingRepository(\PDO)`: `upsertMany`, `markRemovedExcept`, `find(int): ?array`.
- `App\Support\Clock::now(): \DateTimeImmutable` (UTC), `SystemClock`.
- Tests: `Tests\Integration\DatabaseTestCase` (`self::$pdo`, `truncateAll()` in `setUp`, `insert(string $table, array $row): int`, `loadSql()`); `Tests\Api\ApiTestCase` (`request(string $method, string $path, ?array $json = null, array $headers = [], bool $xhr = true): array{status,json,headers}`, `upload(string $path, array $files, array $fields = []): array{status,json,headers}` — `$files` maps field name → absolute path; cookie jar per test class in `sys_get_temp_dir()`).
- Frontend globals: `Api.get/post/patch/del/upload`, `ApiError{status,code,message,fields}`, `Toast.success/info/error`, `Router.register(pattern, handler(params, query))`, `Router.navigate(hash)`, `App.el`, `App.setNav(html)`, `App.onUnauthorized`, `Sync.mount()`.
- Seed: user `admin` / `admin123`; 10 products, 170 landings (ids = upstream ids, e.g. `abforge` EN = 61763, BG = 63138, CZ = 63133); testimonials on every master and on every third localised landing; 65 `testimonial_images` rows whose files do not exist yet.

## File structure (this plan)

```
src/Domain/Testimonial/{TestimonialValidator,RatingResolver}.php
src/Domain/Image/ImageValidator.php
src/Domain/Auth/CurrentUser.php                       interface: id(): ?int
src/Application/{ProductSearchService,LandingOverviewService,TestimonialService,ImageService,AuthService}.php
src/Infrastructure/Repository/{TestimonialRepository,ImageRepository,UserRepository}.php   (+ methods added to Product/LandingRepository)
src/Infrastructure/Storage/ImageStorage.php
src/Infrastructure/Auth/SessionAuth.php               implements CurrentUser
src/Http/Middleware/AuthMiddleware.php
src/Http/UploadedFiles.php
src/Http/Controller/{ProductController,TestimonialController,ImageController,MediaController,AuthController}.php
public/assets/js/views/{products,countries,testimonials,login}.js
public/assets/js/components/{testimonialForm,imageUploader,confirm,saveStatus}.js
database/seed-images.php
bin/install.php
tests/e2e/{package.json,playwright.config.ts,tests/happy-path.spec.ts,tests/errors.spec.ts}
fly.toml deploy/mysql/fly.toml .github/workflows/{ci.yml (e2e job), deploy.yml}
```

---

## Phase 4 — Products + countries

### Task 1: Product search endpoint (server-side search, paging, sorting, counts)

**Files:**
- Modify: `src/Infrastructure/Repository/ProductRepository.php` (add `search`, `countSearch`)
- Create: `src/Application/ProductSearchService.php`, `src/Http/Controller/ProductController.php`
- Modify: `config/container.php`, `config/routes.php`
- Test: `tests/Integration/Repository/ProductRepositorySearchTest.php`, `tests/Unit/Application/ProductSearchServiceTest.php`, `tests/Api/ProductsTest.php`

**Interfaces:**
- Produces `ProductRepository::search(string $q, string $sortColumn, string $dir, int $limit, int $offset): list<array{id:int,parent_sku:string,title:string,image:?string,landing_count:int,testimonial_count:int}>` — `$sortColumn` is one of the SQL expressions from `ProductRepository::SORT_COLUMNS`; `$dir` is `ASC|DESC` (already validated by the service).
- Produces `ProductRepository::countSearch(string $q): int`.
- Produces `ProductSearchService::search(array $query): array{data: list<...>, meta: array{page:int, per_page:int, total:int, sort:string, dir:string, search:string}}` — validates `search`, `page` (≥1), `per_page` (1..100, default 20), `sort` ∈ `sku|title|landings|testimonials` (default `sku`), `dir` ∈ `asc|desc`; throws `ValidationException` with field-keyed messages on bad input.
- Produces route `GET /api/products`.

- [ ] **Step 1: Branch, failing tests**

```bash
git checkout -b phase/04-products
```

`tests/Integration/Repository/ProductRepositorySearchTest.php`:
```php
<?php

declare(strict_types=1);

namespace Tests\Integration\Repository;

use App\Infrastructure\Repository\ProductRepository;
use Tests\Integration\DatabaseTestCase;

final class ProductRepositorySearchTest extends DatabaseTestCase
{
    private ProductRepository $repo;

    protected function setUp(): void
    {
        parent::setUp();
        $this->repo = new ProductRepository(self::$pdo);
        $a = self::insert('products', ['parent_sku' => 'abforge', 'title' => 'Strengthen your abs', 'description' => 'core workouts', 'image' => 'a.webp']);
        $b = self::insert('products', ['parent_sku' => 'drivewaypro', 'title' => 'Clean driveway', 'description' => 'pressure washer', 'image' => null]);
        $c = self::insert('products', ['parent_sku' => 'zenmat', 'title' => 'Yoga mat', 'description' => 'abs and core', 'image' => null]);
        self::insert('landings', ['id' => 1, 'product_id' => $a, 'country' => 'EN', 'is_master' => 1, 'url' => 'u', 'last_synced_at' => '2026-01-01 00:00:00']);
        self::insert('landings', ['id' => 2, 'product_id' => $a, 'country' => 'SI', 'is_master' => 0, 'url' => 'u', 'last_synced_at' => '2026-01-01 00:00:00']);
        self::insert('landings', ['id' => 3, 'product_id' => $a, 'country' => 'IT', 'is_master' => 0, 'url' => 'u', 'last_synced_at' => '2026-01-01 00:00:00', 'removed_at' => '2026-01-02 00:00:00']);
        self::insert('landings', ['id' => 4, 'product_id' => $b, 'country' => 'EN', 'is_master' => 1, 'url' => 'u', 'last_synced_at' => '2026-01-01 00:00:00']);
        foreach ([1, 1, 2] as $landingId) {
            self::insert('testimonials', ['landing_id' => $landingId, 'author_name' => 'A', 'text' => 'T', 'rating' => 5, 'gender' => 'unisex', 'sort_order' => 0]);
        }
        self::insert('testimonials', ['landing_id' => 3, 'author_name' => 'A', 'text' => 'on removed landing', 'rating' => 5, 'gender' => 'unisex', 'sort_order' => 0]);
    }

    public function testCountsExcludeRemovedLandings(): void
    {
        $rows = $this->repo->search('', 'p.parent_sku', 'ASC', 10, 0);
        self::assertSame(['abforge', 'drivewaypro', 'zenmat'], array_column($rows, 'parent_sku'));
        self::assertSame(2, $rows[0]['landing_count']);
        self::assertSame(3, $rows[0]['testimonial_count']);
        self::assertSame(1, $rows[1]['landing_count']);
        self::assertSame(0, $rows[1]['testimonial_count']);
        self::assertSame(0, $rows[2]['landing_count']);
    }

    public function testSearchMatchesSkuTitleAndDescription(): void
    {
        self::assertSame(['abforge', 'zenmat'], array_column($this->repo->search('abs', 'p.parent_sku', 'ASC', 10, 0), 'parent_sku'));
        self::assertSame(['drivewaypro'], array_column($this->repo->search('DRIVEWAY', 'p.parent_sku', 'ASC', 10, 0), 'parent_sku'));
        self::assertSame(2, $this->repo->countSearch('abs'));
        self::assertSame(3, $this->repo->countSearch(''));
    }

    public function testSortByTestimonialsDescAndPaging(): void
    {
        $rows = $this->repo->search('', 'testimonial_count', 'DESC', 2, 0);
        self::assertSame(['abforge', 'drivewaypro'], array_column($rows, 'parent_sku'));
        $rows = $this->repo->search('', 'testimonial_count', 'DESC', 2, 2);
        self::assertSame(['zenmat'], array_column($rows, 'parent_sku'));
    }

    public function testLikeWildcardsInSearchAreLiteral(): void
    {
        self::assertSame([], $this->repo->search('%', 'p.parent_sku', 'ASC', 10, 0));
        self::assertSame([], $this->repo->search('_', 'p.parent_sku', 'ASC', 10, 0));
    }
}
```

`tests/Unit/Application/ProductSearchServiceTest.php`:
```php
<?php

declare(strict_types=1);

namespace Tests\Unit\Application;

use App\Application\ProductSearchService;
use App\Domain\Exception\ValidationException;
use App\Infrastructure\Repository\ProductRepository;
use PHPUnit\Framework\TestCase;

final class ProductSearchServiceTest extends TestCase
{
    /** @var array<string,mixed> */
    private array $captured = [];

    private function service(): ProductSearchService
    {
        $repo = $this->createMock(ProductRepository::class);
        $repo->method('search')->willReturnCallback(function (string $q, string $col, string $dir, int $limit, int $offset) {
            $this->captured = compact('q', 'col', 'dir', 'limit', 'offset');
            return [];
        });
        $repo->method('countSearch')->willReturn(42);
        return new ProductSearchService($repo);
    }

    public function testDefaults(): void
    {
        $result = $this->service()->search([]);
        self::assertSame(['q' => '', 'col' => 'p.parent_sku', 'dir' => 'ASC', 'limit' => 20, 'offset' => 0], $this->captured);
        self::assertSame(['page' => 1, 'per_page' => 20, 'total' => 42, 'sort' => 'sku', 'dir' => 'asc', 'search' => ''], $result['meta']);
    }

    public function testMapsSortAndPaging(): void
    {
        $this->service()->search(['search' => ' abs ', 'page' => '3', 'per_page' => '10', 'sort' => 'testimonials', 'dir' => 'desc']);
        self::assertSame(['q' => 'abs', 'col' => 'testimonial_count', 'dir' => 'DESC', 'limit' => 10, 'offset' => 20], $this->captured);
    }

    public function testRejectsBadInput(): void
    {
        foreach ([['page' => '0'], ['per_page' => '101'], ['sort' => 'id'], ['dir' => 'sideways'], ['page' => 'x']] as $bad) {
            try {
                $this->service()->search($bad);
                self::fail('expected ValidationException for ' . json_encode($bad));
            } catch (ValidationException $e) {
                self::assertSame(array_keys($bad), array_keys($e->getFields()));
            }
        }
    }
}
```

`tests/Api/ProductsTest.php`:
```php
<?php

declare(strict_types=1);

namespace Tests\Api;

final class ProductsTest extends ApiTestCase
{
    public function testListsSeededProductsWithCounts(): void
    {
        $r = $this->request('GET', '/api/products?per_page=5&sort=sku');
        self::assertSame(200, $r['status']);
        self::assertCount(5, $r['json']['data']);
        self::assertSame(10, $r['json']['meta']['total']);
        $first = $r['json']['data'][0];
        self::assertSame('abforge', $first['sku']);
        self::assertGreaterThan(10, $first['landing_count']);
        self::assertGreaterThan(0, $first['testimonial_count']);
        self::assertArrayHasKey('title', $first);
        self::assertArrayHasKey('image', $first);
    }

    public function testSearchAndValidation(): void
    {
        $r = $this->request('GET', '/api/products?search=abforge');
        self::assertSame(1, $r['json']['meta']['total']);
        $r = $this->request('GET', '/api/products?sort=nope');
        self::assertSame(422, $r['status']);
        self::assertSame('validation_failed', $r['json']['error']['code']);
        self::assertArrayHasKey('sort', $r['json']['error']['fields']);
    }
}
```

- [ ] **Step 2: Run — expect failures**

```bash
make integration; make unit
```
Expected: `ProductRepositorySearchTest` errors (method `search` missing); unit test errors (`ProductSearchService` missing).

- [ ] **Step 3: Implement**

Add to `src/Infrastructure/Repository/ProductRepository.php` (keep existing methods):
```php
    /** Whitelisted sort expressions, keyed by API sort name. */
    public const SORT_COLUMNS = [
        'sku' => 'p.parent_sku',
        'title' => 'p.title',
        'landings' => 'landing_count',
        'testimonials' => 'testimonial_count',
    ];

    /**
     * @return list<array{id:int,parent_sku:string,title:string,image:?string,landing_count:int,testimonial_count:int}>
     */
    public function search(string $q, string $sortColumn, string $dir, int $limit, int $offset): array
    {
        if (!in_array($sortColumn, self::SORT_COLUMNS, true) || !in_array($dir, ['ASC', 'DESC'], true)) {
            throw new \InvalidArgumentException('Invalid sort');
        }
        $sql = 'SELECT p.id, p.parent_sku, p.title, p.image,
                  (SELECT COUNT(*) FROM landings l WHERE l.product_id = p.id AND l.removed_at IS NULL) AS landing_count,
                  (SELECT COUNT(*) FROM testimonials t JOIN landings l2 ON l2.id = t.landing_id
                     WHERE l2.product_id = p.id AND l2.removed_at IS NULL) AS testimonial_count
                FROM products p ' . $this->whereSearch($q) . "
                ORDER BY $sortColumn $dir, p.id ASC
                LIMIT :limit OFFSET :offset";
        $stmt = $this->pdo->prepare($sql);
        $this->bindSearch($stmt, $q);
        $stmt->bindValue(':limit', $limit, \PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, \PDO::PARAM_INT);
        $stmt->execute();
        $rows = [];
        foreach ($stmt->fetchAll() as $row) {
            $rows[] = [
                'id' => (int) $row['id'],
                'parent_sku' => (string) $row['parent_sku'],
                'title' => (string) $row['title'],
                'image' => $row['image'] === null ? null : (string) $row['image'],
                'landing_count' => (int) $row['landing_count'],
                'testimonial_count' => (int) $row['testimonial_count'],
            ];
        }
        return $rows;
    }

    public function countSearch(string $q): int
    {
        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM products p ' . $this->whereSearch($q));
        $this->bindSearch($stmt, $q);
        $stmt->execute();
        return (int) $stmt->fetchColumn();
    }

    private function whereSearch(string $q): string
    {
        return $q === '' ? '' : 'WHERE p.parent_sku LIKE :q1 OR p.title LIKE :q2 OR p.description LIKE :q3';
    }

    private function bindSearch(\PDOStatement $stmt, string $q): void
    {
        if ($q === '') {
            return;
        }
        // Escape LIKE metacharacters so user input is matched literally.
        $like = '%' . addcslashes($q, '%_\\') . '%';
        foreach ([':q1', ':q2', ':q3'] as $p) {
            $stmt->bindValue($p, $like);
        }
    }
```

`src/Application/ProductSearchService.php`:
```php
<?php

declare(strict_types=1);

namespace App\Application;

use App\Domain\Exception\ValidationException;
use App\Infrastructure\Repository\ProductRepository;

final class ProductSearchService
{
    private const MAX_PER_PAGE = 100;
    private const DEFAULT_PER_PAGE = 20;

    public function __construct(private readonly ProductRepository $products)
    {
    }

    /**
     * @param array<string,mixed> $query  raw query-string values
     * @return array{data: list<array{sku:string,title:string,image:?string,landing_count:int,testimonial_count:int}>, meta: array{page:int,per_page:int,total:int,sort:string,dir:string,search:string}}
     */
    public function search(array $query): array
    {
        $errors = [];
        $search = trim((string) ($query['search'] ?? ''));
        $page = $this->int($query, 'page', 1, 1, PHP_INT_MAX, $errors);
        $perPage = $this->int($query, 'per_page', self::DEFAULT_PER_PAGE, 1, self::MAX_PER_PAGE, $errors);
        $sort = (string) ($query['sort'] ?? 'sku');
        if (!isset(ProductRepository::SORT_COLUMNS[$sort])) {
            $errors['sort'] = 'Must be one of: ' . implode(', ', array_keys(ProductRepository::SORT_COLUMNS));
        }
        $dir = strtolower((string) ($query['dir'] ?? 'asc'));
        if (!in_array($dir, ['asc', 'desc'], true)) {
            $errors['dir'] = 'Must be asc or desc';
        }
        if ($errors !== []) {
            throw new ValidationException($errors);
        }

        $rows = $this->products->search($search, ProductRepository::SORT_COLUMNS[$sort], strtoupper($dir), $perPage, ($page - 1) * $perPage);
        $data = [];
        foreach ($rows as $row) {
            $data[] = [
                'sku' => $row['parent_sku'],
                'title' => $row['title'],
                'image' => $row['image'],
                'landing_count' => $row['landing_count'],
                'testimonial_count' => $row['testimonial_count'],
            ];
        }
        return [
            'data' => $data,
            'meta' => ['page' => $page, 'per_page' => $perPage, 'total' => $this->products->countSearch($search), 'sort' => $sort, 'dir' => $dir, 'search' => $search],
        ];
    }

    /** @param array<string,mixed> $query @param array<string,string> $errors */
    private function int(array $query, string $key, int $default, int $min, int $max, array &$errors): int
    {
        if (!isset($query[$key]) || $query[$key] === '') {
            return $default;
        }
        $raw = $query[$key];
        if (!is_numeric($raw) || (string) (int) $raw !== (string) $raw || (int) $raw < $min || (int) $raw > $max) {
            $errors[$key] = "Must be an integer between $min and $max";
            return $default;
        }
        return (int) $raw;
    }
}
```

`src/Http/Controller/ProductController.php`:
```php
<?php

declare(strict_types=1);

namespace App\Http\Controller;

use App\Application\ProductSearchService;
use App\Http\Request;
use App\Http\Response;

final class ProductController
{
    public function __construct(private readonly ProductSearchService $search)
    {
    }

    public function index(Request $request): Response
    {
        return Response::json($this->search->search($request->query));
    }
}
```

`config/container.php` — add imports and entries:
```php
use App\Application\ProductSearchService;
use App\Http\Controller\ProductController;

    $c->set(ProductSearchService::class, fn (Container $c) => new ProductSearchService($c->get(ProductRepository::class)));
    $c->set(ProductController::class, fn (Container $c) => new ProductController($c->get(ProductSearchService::class)));
```
`config/routes.php` — add `use App\Http\Controller\ProductController;` and `$r->get('/api/products', [ProductController::class, 'index']);`.

- [ ] **Step 4: Green + commit**

```bash
make unit && make integration && make lint && make stan && make up && make seed && make api
git add -A && git commit -m "feat(products): server-side product search with paging, sorting and counts"
```


### Task 2: Landings of a product with testimonial counts and inheritance flag

**Files:**
- Modify: `src/Infrastructure/Repository/LandingRepository.php` (add `listByProductSku`, `findMasterFor`)
- Create: `src/Application/LandingOverviewService.php`
- Modify: `src/Http/Controller/ProductController.php` (add `landings`), `config/container.php`, `config/routes.php`
- Test: `tests/Integration/Repository/LandingRepositoryOverviewTest.php`, `tests/Api/ProductLandingsTest.php`

**Interfaces:**
- Produces `LandingRepository::listByProductSku(string $sku): list<array{id:int,country:string,is_master:bool,url:string,title:string,status:?string,testimonial_count:int}>` — active landings only, master first then by country.
- Produces `LandingRepository::findMasterFor(int $landingId): ?array` — the EN master landing row (`is_master = 1`, not removed) of the same product; used by Task 5.
- Produces `LandingOverviewService::forProduct(string $sku): array{data: list<array{id:int,country:string,is_master:bool,url:string,title:string,status:?string,testimonial_count:int,inherits_from_master:bool,inherited_count:int}>}` — throws `NotFoundException` when no product has that SKU.
- Produces route `GET /api/products/{sku}/landings`.

- [ ] **Step 1: Failing tests**

`tests/Integration/Repository/LandingRepositoryOverviewTest.php`:
```php
<?php

declare(strict_types=1);

namespace Tests\Integration\Repository;

use App\Infrastructure\Repository\LandingRepository;
use Tests\Integration\DatabaseTestCase;

final class LandingRepositoryOverviewTest extends DatabaseTestCase
{
    private LandingRepository $repo;

    protected function setUp(): void
    {
        parent::setUp();
        $this->repo = new LandingRepository(self::$pdo);
        $p = self::insert('products', ['parent_sku' => 'abforge', 'title' => 'AbForge']);
        self::insert('landings', ['id' => 10, 'product_id' => $p, 'country' => 'SI', 'is_master' => 0, 'url' => 'u/si', 'title' => 'SI title', 'status' => 'ADVERTISING', 'last_synced_at' => '2026-01-01 00:00:00']);
        self::insert('landings', ['id' => 11, 'product_id' => $p, 'country' => 'EN', 'is_master' => 1, 'url' => 'u/en', 'title' => 'EN title', 'last_synced_at' => '2026-01-01 00:00:00']);
        self::insert('landings', ['id' => 12, 'product_id' => $p, 'country' => 'DE', 'is_master' => 0, 'url' => 'u/de', 'last_synced_at' => '2026-01-01 00:00:00', 'removed_at' => '2026-01-02 00:00:00']);
        self::insert('landings', ['id' => 13, 'product_id' => $p, 'country' => 'BG', 'is_master' => 0, 'url' => 'u/bg', 'last_synced_at' => '2026-01-01 00:00:00']);
        foreach ([11, 11, 10] as $l) {
            self::insert('testimonials', ['landing_id' => $l, 'author_name' => 'A', 'text' => 'T', 'gender' => 'unisex', 'sort_order' => 0]);
        }
    }

    public function testListsActiveLandingsMasterFirstWithCounts(): void
    {
        $rows = $this->repo->listByProductSku('abforge');
        self::assertSame([11, 13, 10], array_column($rows, 'id'));
        self::assertTrue($rows[0]['is_master']);
        self::assertSame(2, $rows[0]['testimonial_count']);
        self::assertSame(0, $rows[1]['testimonial_count']);
        self::assertSame(1, $rows[2]['testimonial_count']);
        self::assertSame('ADVERTISING', $rows[2]['status']);
        self::assertSame([], $this->repo->listByProductSku('nope'));
    }

    public function testFindMasterFor(): void
    {
        self::assertSame(11, $this->repo->findMasterFor(10)['id']);
        self::assertSame(11, $this->repo->findMasterFor(11)['id']);
        self::assertNull($this->repo->findMasterFor(999));
    }
}
```

`tests/Api/ProductLandingsTest.php`:
```php
<?php

declare(strict_types=1);

namespace Tests\Api;

final class ProductLandingsTest extends ApiTestCase
{
    public function testLandingsOfSeededProduct(): void
    {
        $r = $this->request('GET', '/api/products/abforge/landings');
        self::assertSame(200, $r['status']);
        $data = $r['json']['data'];
        self::assertGreaterThan(10, count($data));
        self::assertTrue($data[0]['is_master']);
        self::assertSame('EN', $data[0]['country']);
        self::assertSame(61763, $data[0]['id']);
        self::assertFalse($data[0]['inherits_from_master']);
        $inheriting = array_values(array_filter($data, fn ($l) => $l['inherits_from_master']));
        self::assertNotEmpty($inheriting, 'some localised landings inherit from EN');
        self::assertSame($data[0]['testimonial_count'], $inheriting[0]['inherited_count']);
        self::assertSame(0, $inheriting[0]['testimonial_count']);
    }

    public function testUnknownSkuIs404(): void
    {
        $r = $this->request('GET', '/api/products/does-not-exist/landings');
        self::assertSame(404, $r['status']);
        self::assertSame('not_found', $r['json']['error']['code']);
    }
}
```

- [ ] **Step 2: Run — expect failures** (`make integration`, then `make api` after wiring)

- [ ] **Step 3: Implement**

Add to `LandingRepository`:
```php
    /** @return list<array{id:int,country:string,is_master:bool,url:string,title:string,status:?string,testimonial_count:int}> */
    public function listByProductSku(string $sku): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT l.id, l.country, l.is_master, l.url, l.title, l.status,
                    (SELECT COUNT(*) FROM testimonials t WHERE t.landing_id = l.id) AS testimonial_count
             FROM landings l JOIN products p ON p.id = l.product_id
             WHERE p.parent_sku = ? AND l.removed_at IS NULL
             ORDER BY l.is_master DESC, l.country ASC, l.id ASC',
        );
        $stmt->execute([$sku]);
        $rows = [];
        foreach ($stmt->fetchAll() as $r) {
            $rows[] = [
                'id' => (int) $r['id'], 'country' => (string) $r['country'], 'is_master' => (bool) $r['is_master'],
                'url' => (string) $r['url'], 'title' => (string) $r['title'],
                'status' => $r['status'] === null ? null : (string) $r['status'],
                'testimonial_count' => (int) $r['testimonial_count'],
            ];
        }
        return $rows;
    }

    /** @return array<string,mixed>|null */
    public function findMasterFor(int $landingId): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT m.* FROM landings l JOIN landings m ON m.product_id = l.product_id AND m.is_master = 1 AND m.removed_at IS NULL
             WHERE l.id = ? ORDER BY m.id ASC LIMIT 1',
        );
        $stmt->execute([$landingId]);
        $row = $stmt->fetch();
        return $row === false ? null : $row;
    }
```

`src/Application/LandingOverviewService.php`:
```php
<?php

declare(strict_types=1);

namespace App\Application;

use App\Domain\Exception\NotFoundException;
use App\Infrastructure\Repository\LandingRepository;

final class LandingOverviewService
{
    public function __construct(private readonly LandingRepository $landings)
    {
    }

    /** @return array{data: list<array<string,mixed>>} */
    public function forProduct(string $sku): array
    {
        $rows = $this->landings->listByProductSku($sku);
        if ($rows === []) {
            throw new NotFoundException("Product '$sku' not found");
        }
        $masterCount = 0;
        foreach ($rows as $row) {
            if ($row['is_master']) {
                $masterCount = $row['testimonial_count'];
                break;
            }
        }
        $data = [];
        foreach ($rows as $row) {
            $inherits = !$row['is_master'] && $row['testimonial_count'] === 0;
            $data[] = $row + ['inherits_from_master' => $inherits, 'inherited_count' => $inherits ? $masterCount : 0];
        }
        return ['data' => $data];
    }
}
```

`ProductController` — add constructor param `private readonly LandingOverviewService $overview` and:
```php
    public function landings(Request $request): Response
    {
        return Response::json($this->overview->forProduct($request->attribute('sku')));
    }
```
Container: `$c->set(LandingOverviewService::class, fn (Container $c) => new LandingOverviewService($c->get(LandingRepository::class)));` and update the `ProductController` factory to pass both services. Routes: `$r->get('/api/products/{sku}/landings', [ProductController::class, 'landings']);`.

- [ ] **Step 4: Green + commit**

```bash
make integration && make lint && make stan && make api
git add -A && git commit -m "feat(products): landings overview with testimonial counts and inheritance flag"
```

### Task 3: Products and countries views (UI) + merge phase 4

**Files:**
- Create: `public/assets/js/views/products.js`, `public/assets/js/views/countries.js`, `public/assets/js/components/pagination.js`
- Modify: `public/index.html` (script tags), `public/assets/js/app.js` (routes), `public/assets/css/app.css` (a few rules)
- Test: manual (curl + browser); `make api` stays green

**Interfaces:**
- `window.Views.products(params, query)` renders `#/products?search=&page=&sort=&dir=`; `window.Views.countries({sku})` renders `#/products/{sku}`; `window.Pagination.render(meta, onPage)` returns an HTML string with `data-page` buttons and wires clicks via delegation on `#app`.
- Helpers (put at top of `products.js` and reuse elsewhere): `window.esc = (s) => String(s ?? '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]))` — every user/DB string goes through `esc()` before `innerHTML`.

- [ ] **Step 1: Files**

`public/assets/js/components/pagination.js`:
```js
(function () {
  'use strict';
  window.Pagination = {
    render(meta) {
      const pages = Math.max(1, Math.ceil(meta.total / meta.per_page));
      if (pages <= 1) return '';
      let html = '<nav aria-label="Pages"><ul class="pagination pagination-sm mb-0">';
      const btn = (p, label, disabled, active) =>
        `<li class="page-item${disabled ? ' disabled' : ''}${active ? ' active' : ''}"><button type="button" class="page-link" data-page="${p}">${label}</button></li>`;
      html += btn(meta.page - 1, '&laquo;', meta.page <= 1, false);
      const from = Math.max(1, meta.page - 2), to = Math.min(pages, meta.page + 2);
      for (let p = from; p <= to; p++) html += btn(p, p, false, p === meta.page);
      html += btn(meta.page + 1, '&raquo;', meta.page >= pages, false);
      return html + '</ul></nav>';
    },
  };
})();
```

`public/assets/js/views/products.js`:
```js
(function () {
  'use strict';
  window.esc = (s) => String(s ?? '').replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
  window.Views = window.Views || {};

  const COLS = [['sku', 'SKU'], ['title', 'Product'], ['landings', 'Landings'], ['testimonials', 'Testimonials']];

  function hashFor(q) {
    const p = new URLSearchParams();
    if (q.search) p.set('search', q.search);
    if (q.page && q.page !== 1) p.set('page', q.page);
    if (q.sort && q.sort !== 'sku') p.set('sort', q.sort);
    if (q.dir && q.dir !== 'asc') p.set('dir', q.dir);
    const s = p.toString();
    return '#/products' + (s ? '?' + s : '');
  }

  window.Views.products = async function (params, query) {
    const q = { search: query.search || '', page: parseInt(query.page || '1', 10), sort: query.sort || 'sku', dir: query.dir || 'asc' };
    App.el.innerHTML = `
      <div class="d-flex flex-wrap align-items-center justify-content-between gap-2 mb-3">
        <h1 class="h3 mb-0">Products</h1>
        <form id="product-search" class="d-flex gap-2" role="search">
          <input id="product-search-input" class="form-control" type="search" placeholder="Search SKU or description" value="${esc(q.search)}" aria-label="Search products">
          <button class="btn btn-primary" type="submit">Search</button>
        </form>
      </div>
      <div id="products-table"><div class="text-muted">Loading…</div></div>`;
    document.getElementById('product-search').addEventListener('submit', (e) => {
      e.preventDefault();
      Router.navigate(hashFor({ ...q, search: document.getElementById('product-search-input').value.trim(), page: 1 }));
    });

    let res;
    try {
      res = await Api.get(`/api/products?search=${encodeURIComponent(q.search)}&page=${q.page}&per_page=20&sort=${q.sort}&dir=${q.dir}`);
    } catch (e) {
      document.getElementById('products-table').innerHTML = `<div class="alert alert-danger">Could not load products: ${esc(e.message)}</div>`;
      Toast.error('Could not load products: ' + e.message);
      return;
    }
    const head = COLS.map(([key, label]) => {
      const active = q.sort === key;
      const nextDir = active && q.dir === 'asc' ? 'desc' : 'asc';
      const icon = active ? (q.dir === 'asc' ? ' <i class="bi bi-caret-up-fill"></i>' : ' <i class="bi bi-caret-down-fill"></i>') : '';
      return `<th class="tm-sortable${active ? ' active' : ''}" data-sort="${key}" data-dir="${nextDir}">${label}${icon}</th>`;
    }).join('');
    const rows = res.data.map((p) => `
      <tr class="tm-row-link" data-href="#/products/${encodeURIComponent(p.sku)}">
        <td><code>${esc(p.sku)}</code></td>
        <td class="d-flex align-items-center gap-2">${p.image ? `<img src="${esc(p.image)}" alt="" width="40" height="40" class="rounded object-fit-cover">` : ''}<span>${esc(p.title)}</span></td>
        <td><span class="badge tm-count-badge">${p.landing_count}</span></td>
        <td><span class="badge tm-count-badge">${p.testimonial_count}</span></td>
      </tr>`).join('');
    document.getElementById('products-table').innerHTML = `
      <div class="card"><div class="table-responsive"><table class="table table-hover align-middle mb-0">
        <thead><tr>${head}</tr></thead>
        <tbody>${rows || '<tr><td colspan="4" class="text-muted text-center py-4">No products match.</td></tr>'}</tbody>
      </table></div>
      <div class="card-body d-flex justify-content-between align-items-center">
        <span class="text-muted small">${res.meta.total} products</span>${Pagination.render(res.meta)}
      </div></div>`;
    document.querySelectorAll('#products-table th.tm-sortable').forEach((th) =>
      th.addEventListener('click', () => Router.navigate(hashFor({ ...q, sort: th.dataset.sort, dir: th.dataset.dir, page: 1 }))));
    document.querySelectorAll('#products-table [data-page]').forEach((b) =>
      b.addEventListener('click', () => Router.navigate(hashFor({ ...q, page: parseInt(b.dataset.page, 10) }))));
    document.querySelectorAll('#products-table .tm-row-link').forEach((tr) =>
      tr.addEventListener('click', () => Router.navigate(tr.dataset.href)));
  };
})();
```

`public/assets/js/views/countries.js`:
```js
(function () {
  'use strict';
  window.Views = window.Views || {};

  window.Views.countries = async function (params) {
    const sku = params.sku;
    App.el.innerHTML = `
      <nav aria-label="breadcrumb"><ol class="breadcrumb"><li class="breadcrumb-item"><a href="#/products">Products</a></li><li class="breadcrumb-item active">${esc(sku)}</li></ol></nav>
      <div id="countries"><div class="text-muted">Loading…</div></div>`;
    let res;
    try {
      res = await Api.get(`/api/products/${encodeURIComponent(sku)}/landings`);
    } catch (e) {
      document.getElementById('countries').innerHTML = `<div class="alert alert-danger">${esc(e.status === 404 ? 'Product not found.' : 'Could not load landings: ' + e.message)}</div>`;
      return;
    }
    const master = res.data.find((l) => l.is_master);
    const cards = res.data.map((l) => {
      const badge = l.is_master
        ? `<span class="badge bg-dark">EN master</span>`
        : l.inherits_from_master
          ? `<span class="badge bg-warning text-dark" title="No own testimonials — the English set is shown on this landing">inherits EN (${l.inherited_count})</span>`
          : `<span class="badge bg-success">${l.testimonial_count} own</span>`;
      return `
        <div class="col-6 col-md-4 col-lg-3 col-xl-2">
          <a class="card h-100 text-decoration-none tm-country-card" href="#/landings/${l.id}">
            <div class="card-body d-flex flex-column gap-2">
              <div class="d-flex justify-content-between align-items-center">
                <span class="tm-country-code">${esc(l.country)}</span>
                <span class="badge rounded-pill ${l.testimonial_count ? 'bg-primary' : 'bg-secondary'}">${l.testimonial_count}</span>
              </div>
              ${badge}
              <small class="text-muted text-truncate" title="${esc(l.url)}">${esc(l.status || '')}</small>
            </div>
          </a>
        </div>`;
    }).join('');
    document.getElementById('countries').innerHTML = `
      <div class="d-flex flex-wrap justify-content-between align-items-end gap-2 mb-3">
        <div><h1 class="h3 mb-1">${esc(master ? master.title : sku)}</h1><div class="text-muted small">${res.data.length} landings · pick a country to manage its testimonials</div></div>
      </div>
      <div class="row g-3">${cards}</div>`;
  };
})();
```

`app.js` — replace the placeholder route registration with:
```js
  Router.register('#/products', (params, query) => Views.products(params, query));
  Router.register('#/products/{sku}', (params) => Views.countries(params));
```
`index.html` — add before `app.js`: `components/pagination.js`, `views/products.js`, `views/countries.js` (paths `assets/js/components/pagination.js` etc.).
`app.css` — append:
```css
.tm-row-link { cursor: pointer; }
.tm-country-code { font-family: "Montserrat", sans-serif; font-weight: 800; font-size: 1.25rem; color: var(--tm-dark); letter-spacing: .04em; }
.tm-country-card { color: inherit; transition: box-shadow .15s; }
.tm-country-card:hover { box-shadow: 0 4px 14px rgba(0,96,184,.18); }
```

- [ ] **Step 2: Verify**

```bash
make up && make api
for f in components/pagination views/products views/countries; do curl -s -o /dev/null -w "$f %{http_code}\n" http://localhost:8080/assets/js/$f.js; done
for f in public/assets/js/**/*.js public/assets/js/*.js; do node --check $f || echo "SYNTAX $f"; done
```
Open http://localhost:8080 — table with counts, sortable headers, search, pagination (with 10 products only search/sort are observable; run `make seed-large` once to see pagination, then `make seed` again to reset — note in the report). Click a row → country cards with counters and "inherits EN" badges.

- [ ] **Step 3: Commit, merge phase 4**

```bash
git add -A && git commit -m "feat(ui): product search table and country overview views"
```
Append `| 4 | Products + countries (API + UI) | 2h |` to `docs/time-log.md`, commit `docs: time log phase 4`, push, `gh pr create --title "Phase 4: products + countries" --body "..."`, wait `gh pr checks --watch`, `gh pr merge --merge --delete-branch`, `git checkout main && git pull`.

---

## Phase 5 — Testimonials CRUD

### Task 4: Domain — `TestimonialValidator`, `RatingResolver`, `CurrentUser`

**Files:**
- Create: `src/Domain/Testimonial/TestimonialValidator.php`, `src/Domain/Testimonial/RatingResolver.php`, `src/Domain/Auth/CurrentUser.php`, `src/Domain/Auth/AnonymousUser.php`
- Test: `tests/Unit/Domain/Testimonial/TestimonialValidatorTest.php`, `tests/Unit/Domain/Testimonial/RatingResolverTest.php`

**Interfaces:**
- `TestimonialValidator::validate(array $input, bool $partial = false): array` — returns a normalised array containing only the keys `author_name, text, rating, gender, url, is_active, sort_order` that were provided (all of them when `!$partial`, with defaults `rating=null`, `gender='unisex'`, `url=null`, `is_active=true`, `sort_order=null`); throws `ValidationException` with one message per bad field. Constants `MAX_NAME=128`, `MAX_TEXT=2000`, `MAX_URL=512`, `GENDERS=['male','female','unisex']`.
- `RatingResolver::__construct(?\Closure $random = null)`; `display(?int $rating): float` — stored rating as float, or random in `[4.0, 5.0]` with one decimal.
- `App\Domain\Auth\CurrentUser { public function id(): ?int; public function displayName(): ?string; }`; `AnonymousUser` returns nulls (used until phase 7).

- [ ] **Step 1: Branch, failing tests**

```bash
git checkout -b phase/05-testimonials
```

`tests/Unit/Domain/Testimonial/TestimonialValidatorTest.php`:
```php
<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Testimonial;

use App\Domain\Exception\ValidationException;
use App\Domain\Testimonial\TestimonialValidator;
use PHPUnit\Framework\TestCase;

final class TestimonialValidatorTest extends TestCase
{
    private TestimonialValidator $v;

    protected function setUp(): void
    {
        $this->v = new TestimonialValidator();
    }

    public function testFullPayloadIsNormalised(): void
    {
        $out = $this->v->validate([
            'author_name' => '  Janez Novak ', 'text' => ' Great! ', 'rating' => '5', 'gender' => 'male',
            'url' => 'https://example.com/x', 'is_active' => '0', 'sort_order' => '3', 'ignored' => 'x',
        ]);
        self::assertSame(['author_name' => 'Janez Novak', 'text' => 'Great!', 'rating' => 5, 'gender' => 'male', 'url' => 'https://example.com/x', 'is_active' => false, 'sort_order' => 3], $out);
    }

    public function testDefaultsWhenOptionalFieldsMissing(): void
    {
        $out = $this->v->validate(['author_name' => 'A', 'text' => 'T']);
        self::assertSame(['author_name' => 'A', 'text' => 'T', 'rating' => null, 'gender' => 'unisex', 'url' => null, 'is_active' => true, 'sort_order' => null], $out);
    }

    public function testRandomRatingSpellings(): void
    {
        foreach ([null, '', 'random', 'RANDOM'] as $r) {
            self::assertNull($this->v->validate(['author_name' => 'A', 'text' => 'T', 'rating' => $r])['rating']);
        }
    }

    public function testEmptyUrlBecomesNull(): void
    {
        self::assertNull($this->v->validate(['author_name' => 'A', 'text' => 'T', 'url' => '  '])['url']);
    }

    public function testPartialOnlyReturnsProvidedKeys(): void
    {
        self::assertSame(['is_active' => true], $this->v->validate(['is_active' => true], true));
        self::assertSame(['text' => 'x'], $this->v->validate(['text' => 'x'], true));
    }

    /** @return iterable<string, array{0: array<string,mixed>, 1: string}> */
    public static function badInputs(): iterable
    {
        yield 'missing name' => [['text' => 'T'], 'author_name'];
        yield 'blank name' => [['author_name' => '   ', 'text' => 'T'], 'author_name'];
        yield 'long name' => [['author_name' => str_repeat('a', 129), 'text' => 'T'], 'author_name'];
        yield 'missing text' => [['author_name' => 'A'], 'text'];
        yield 'long text' => [['author_name' => 'A', 'text' => str_repeat('x', 2001)], 'text'];
        yield 'rating 0' => [['author_name' => 'A', 'text' => 'T', 'rating' => 0], 'rating'];
        yield 'rating 6' => [['author_name' => 'A', 'text' => 'T', 'rating' => '6'], 'rating'];
        yield 'rating float' => [['author_name' => 'A', 'text' => 'T', 'rating' => 4.5], 'rating'];
        yield 'gender' => [['author_name' => 'A', 'text' => 'T', 'gender' => 'other'], 'gender'];
        yield 'url scheme' => [['author_name' => 'A', 'text' => 'T', 'url' => 'ftp://x.y'], 'url'];
        yield 'url junk' => [['author_name' => 'A', 'text' => 'T', 'url' => 'not a url'], 'url'];
        yield 'url long' => [['author_name' => 'A', 'text' => 'T', 'url' => 'https://x.y/' . str_repeat('a', 510)], 'url'];
        yield 'is_active' => [['author_name' => 'A', 'text' => 'T', 'is_active' => 'maybe'], 'is_active'];
        yield 'sort_order negative' => [['author_name' => 'A', 'text' => 'T', 'sort_order' => -1], 'sort_order'];
        yield 'sort_order text' => [['author_name' => 'A', 'text' => 'T', 'sort_order' => 'first'], 'sort_order'];
    }

    /**
     * @dataProvider badInputs
     * @param array<string,mixed> $input
     */
    public function testRejects(array $input, string $field): void
    {
        try {
            $this->v->validate($input);
            self::fail("expected ValidationException on $field");
        } catch (ValidationException $e) {
            self::assertArrayHasKey($field, $e->getFields());
        }
    }

    public function testMultipleErrorsAreReportedTogether(): void
    {
        try {
            $this->v->validate(['rating' => 9, 'gender' => 'x']);
            self::fail();
        } catch (ValidationException $e) {
            self::assertSame(['author_name', 'text', 'rating', 'gender'], array_keys($e->getFields()));
        }
    }

    public function testUnicodeLengthIsCountedInCharacters(): void
    {
        $text = str_repeat('Ж', 2000);
        self::assertSame($text, $this->v->validate(['author_name' => 'Đorđe', 'text' => $text])['text']);
    }
}
```

`tests/Unit/Domain/Testimonial/RatingResolverTest.php`:
```php
<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Testimonial;

use App\Domain\Testimonial\RatingResolver;
use PHPUnit\Framework\TestCase;

final class RatingResolverTest extends TestCase
{
    public function testStoredRatingIsReturnedAsFloat(): void
    {
        self::assertSame(3.0, (new RatingResolver())->display(3));
    }

    public function testRandomRatingUsesInjectedSource(): void
    {
        $r = new RatingResolver(fn () => 7);   // 7 tenths above 4.0
        self::assertSame(4.7, $r->display(null));
    }

    public function testRandomRatingStaysInRange(): void
    {
        $r = new RatingResolver();
        for ($i = 0; $i < 200; $i++) {
            $v = $r->display(null);
            self::assertGreaterThanOrEqual(4.0, $v);
            self::assertLessThanOrEqual(5.0, $v);
            self::assertSame(round($v, 1), $v);
        }
    }
}
```

- [ ] **Step 2: Run — expect class-not-found**  `make unit`

- [ ] **Step 3: Implement**

`src/Domain/Testimonial/TestimonialValidator.php`:
```php
<?php

declare(strict_types=1);

namespace App\Domain\Testimonial;

use App\Domain\Exception\ValidationException;

/**
 * Validates and normalises a testimonial payload. Pure: no I/O.
 */
final class TestimonialValidator
{
    public const MAX_NAME = 128;
    public const MAX_TEXT = 2000;
    public const MAX_URL = 512;
    public const GENDERS = ['male', 'female', 'unisex'];

    /**
     * @param array<string,mixed> $input
     * @return array<string,mixed>  normalised fields (only the provided ones when $partial)
     */
    public function validate(array $input, bool $partial = false): array
    {
        $errors = [];
        $out = [];

        if (!$partial || array_key_exists('author_name', $input)) {
            $name = trim((string) ($input['author_name'] ?? ''));
            if ($name === '') {
                $errors['author_name'] = 'Author name is required';
            } elseif (mb_strlen($name) > self::MAX_NAME) {
                $errors['author_name'] = 'Author name must be at most ' . self::MAX_NAME . ' characters';
            } else {
                $out['author_name'] = $name;
            }
        }

        if (!$partial || array_key_exists('text', $input)) {
            $text = trim((string) ($input['text'] ?? ''));
            if ($text === '') {
                $errors['text'] = 'Text is required';
            } elseif (mb_strlen($text) > self::MAX_TEXT) {
                $errors['text'] = 'Text must be at most ' . self::MAX_TEXT . ' characters';
            } else {
                $out['text'] = $text;
            }
        }

        if (!$partial || array_key_exists('rating', $input)) {
            $raw = $input['rating'] ?? null;
            if ($raw === null || $raw === '' || (is_string($raw) && strtolower($raw) === 'random')) {
                $out['rating'] = null;
            } elseif ((is_int($raw) || (is_string($raw) && ctype_digit($raw))) && (int) $raw >= 1 && (int) $raw <= 5) {
                $out['rating'] = (int) $raw;
            } else {
                $errors['rating'] = 'Rating must be 1–5 or "random"';
            }
        }

        if (!$partial || array_key_exists('gender', $input)) {
            $gender = (string) ($input['gender'] ?? 'unisex');
            if (!in_array($gender, self::GENDERS, true)) {
                $errors['gender'] = 'Gender must be male, female or unisex';
            } else {
                $out['gender'] = $gender;
            }
        }

        if (!$partial || array_key_exists('url', $input)) {
            $url = trim((string) ($input['url'] ?? ''));
            if ($url === '') {
                $out['url'] = null;
            } elseif (mb_strlen($url) > self::MAX_URL) {
                $errors['url'] = 'URL must be at most ' . self::MAX_URL . ' characters';
            } elseif (filter_var($url, FILTER_VALIDATE_URL) === false || !preg_match('#^https?://#i', $url)) {
                $errors['url'] = 'URL must start with http:// or https://';
            } else {
                $out['url'] = $url;
            }
        }

        if (!$partial || array_key_exists('is_active', $input)) {
            $raw = $input['is_active'] ?? true;
            $bool = filter_var($raw, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
            if ($bool === null) {
                $errors['is_active'] = 'Active must be true or false';
            } else {
                $out['is_active'] = $bool;
            }
        }

        if (!$partial || array_key_exists('sort_order', $input)) {
            $raw = $input['sort_order'] ?? null;
            if ($raw === null || $raw === '') {
                $out['sort_order'] = null;
            } elseif ((is_int($raw) || (is_string($raw) && ctype_digit($raw))) && (int) $raw >= 0) {
                $out['sort_order'] = (int) $raw;
            } else {
                $errors['sort_order'] = 'Sort order must be a non-negative integer';
            }
        }

        if ($errors !== []) {
            throw new ValidationException($errors);
        }
        return $out;
    }
}
```

`src/Domain/Testimonial/RatingResolver.php`:
```php
<?php

declare(strict_types=1);

namespace App\Domain\Testimonial;

/**
 * A NULL rating means "random": pick 4.0–5.0 at display time so the average stays realistic.
 */
final class RatingResolver
{
    /** @var \Closure(): int  returns tenths above 4.0, i.e. 0..10 */
    private \Closure $random;

    public function __construct(?\Closure $random = null)
    {
        $this->random = $random ?? static fn (): int => random_int(0, 10);
    }

    public function display(?int $rating): float
    {
        if ($rating !== null) {
            return (float) $rating;
        }
        return round(4.0 + ($this->random)() / 10, 1);
    }
}
```

`src/Domain/Auth/CurrentUser.php`:
```php
<?php

declare(strict_types=1);

namespace App\Domain\Auth;

interface CurrentUser
{
    public function id(): ?int;

    public function displayName(): ?string;
}
```
`src/Domain/Auth/AnonymousUser.php`:
```php
<?php

declare(strict_types=1);

namespace App\Domain\Auth;

final class AnonymousUser implements CurrentUser
{
    public function id(): ?int
    {
        return null;
    }

    public function displayName(): ?string
    {
        return null;
    }
}
```

- [ ] **Step 4: Green + commit**

```bash
make unit && make lint && make stan
git add -A && git commit -m "feat(testimonials): validator, rating resolver and current-user abstraction"
```

### Task 5: `TestimonialRepository` + `TestimonialService` (inheritance, audit fields)

**Files:**
- Create: `src/Infrastructure/Repository/TestimonialRepository.php`, `src/Application/TestimonialService.php`
- Test: `tests/Integration/Repository/TestimonialRepositoryTest.php`, `tests/Integration/Application/TestimonialServiceTest.php`

**Interfaces:**
- `TestimonialRepository(\PDO)`:
  - `listByLanding(int $landingId): list<Row>` ordered by `sort_order, id`
  - `find(int $id): ?Row`
  - `countByLanding(int $landingId): int`
  - `nextSortOrder(int $landingId): int` (max+1, 0 when empty)
  - `insert(int $landingId, array $fields, ?int $userId): int` — `$fields` = validator output with all 7 keys; `sort_order` null → `nextSortOrder`
  - `update(int $id, array $fields, ?int $userId): void` — only the keys present; sets `updated_by`; no-op on empty
  - `delete(int $id): bool`
  - `Row` = `array{id:int,landing_id:int,author_name:string,text:string,rating:?int,gender:string,url:?string,is_active:bool,sort_order:int,created_at:string,updated_at:string,created_by:?int,updated_by:?int}` (typed casts applied in the repository).
- `TestimonialService(TestimonialRepository, LandingRepository, TestimonialValidator, RatingResolver, CurrentUser)`:
  - `listForLanding(int $landingId): array{data: list<Presented>, meta: array{inherited: bool, source_landing_id: int, landing: array{id:int,country:string,is_master:bool,title:string,url:string}}}` — 404 if landing missing/removed; if the landing is not the master and has zero own testimonials, the master's list is returned with `inherited = true`.
  - `get(int $id): Presented` (404)
  - `create(int $landingId, array $input): Presented` (404 landing, 422)
  - `update(int $id, array $input): Presented` (404, 422; partial)
  - `delete(int $id): void` (404)
  - `present(Row $row): Presented` — `Presented` = Row + `rating_display: float` + `images: list` (empty until phase 6). Public so phase 6 can reuse it.

- [ ] **Step 1: Failing tests**

`tests/Integration/Repository/TestimonialRepositoryTest.php`:
```php
<?php

declare(strict_types=1);

namespace Tests\Integration\Repository;

use App\Infrastructure\Repository\TestimonialRepository;
use Tests\Integration\DatabaseTestCase;

final class TestimonialRepositoryTest extends DatabaseTestCase
{
    private TestimonialRepository $repo;
    private int $userId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->repo = new TestimonialRepository(self::$pdo);
        $p = self::insert('products', ['parent_sku' => 'abforge', 'title' => 'AbForge']);
        self::insert('landings', ['id' => 1, 'product_id' => $p, 'country' => 'EN', 'is_master' => 1, 'url' => 'u', 'last_synced_at' => '2026-01-01 00:00:00']);
        $this->userId = self::insert('users', ['username' => 'admin', 'password_hash' => 'x', 'display_name' => 'Admin']);
    }

    private function fields(array $over = []): array
    {
        return $over + ['author_name' => 'A', 'text' => 'T', 'rating' => null, 'gender' => 'unisex', 'url' => null, 'is_active' => true, 'sort_order' => null];
    }

    public function testInsertAssignsNextSortOrderAndAudit(): void
    {
        self::assertSame(0, $this->repo->nextSortOrder(1));
        $a = $this->repo->insert(1, $this->fields(), $this->userId);
        $b = $this->repo->insert(1, $this->fields(['rating' => 4]), null);
        $rowA = $this->repo->find($a);
        self::assertSame(0, $rowA['sort_order']);
        self::assertSame(1, $this->repo->find($b)['sort_order']);
        self::assertSame($this->userId, $rowA['created_by']);
        self::assertSame($this->userId, $rowA['updated_by']);
        self::assertNull($rowA['rating']);
        self::assertTrue($rowA['is_active']);
        self::assertSame(4, $this->repo->find($b)['rating']);
        self::assertSame(2, $this->repo->countByLanding(1));
    }

    public function testListOrdersBySortOrderThenId(): void
    {
        $a = $this->repo->insert(1, $this->fields(['sort_order' => 5]), null);
        $b = $this->repo->insert(1, $this->fields(['sort_order' => 1]), null);
        $c = $this->repo->insert(1, $this->fields(['sort_order' => 5]), null);
        self::assertSame([$b, $a, $c], array_column($this->repo->listByLanding(1), 'id'));
        self::assertSame(6, $this->repo->nextSortOrder(1));
    }

    public function testUpdateOnlyTouchesGivenFields(): void
    {
        $id = $this->repo->insert(1, $this->fields(['author_name' => 'Before', 'text' => 'keep']), null);
        $this->repo->update($id, ['author_name' => 'After', 'is_active' => false], $this->userId);
        $row = $this->repo->find($id);
        self::assertSame('After', $row['author_name']);
        self::assertSame('keep', $row['text']);
        self::assertFalse($row['is_active']);
        self::assertSame($this->userId, $row['updated_by']);
        self::assertNull($row['created_by']);
        $this->repo->update($id, [], null);   // no-op must not throw
    }

    public function testUpdateRejectsUnknownColumn(): void
    {
        $id = $this->repo->insert(1, $this->fields(), null);
        $this->expectException(\InvalidArgumentException::class);
        $this->repo->update($id, ['landing_id' => 999], null);
    }

    public function testDelete(): void
    {
        $id = $this->repo->insert(1, $this->fields(), null);
        self::assertTrue($this->repo->delete($id));
        self::assertFalse($this->repo->delete($id));
        self::assertNull($this->repo->find($id));
    }
}
```

`tests/Integration/Application/TestimonialServiceTest.php`:
```php
<?php

declare(strict_types=1);

namespace Tests\Integration\Application;

use App\Application\TestimonialService;
use App\Domain\Auth\CurrentUser;
use App\Domain\Exception\NotFoundException;
use App\Domain\Exception\ValidationException;
use App\Domain\Testimonial\RatingResolver;
use App\Domain\Testimonial\TestimonialValidator;
use App\Infrastructure\Repository\LandingRepository;
use App\Infrastructure\Repository\TestimonialRepository;
use Tests\Integration\DatabaseTestCase;

final class TestimonialServiceTest extends DatabaseTestCase
{
    private TestimonialService $svc;
    private int $userId;

    protected function setUp(): void
    {
        parent::setUp();
        $p = self::insert('products', ['parent_sku' => 'abforge', 'title' => 'AbForge']);
        self::insert('landings', ['id' => 1, 'product_id' => $p, 'country' => 'EN', 'is_master' => 1, 'url' => 'u/en', 'title' => 'EN', 'last_synced_at' => '2026-01-01 00:00:00']);
        self::insert('landings', ['id' => 2, 'product_id' => $p, 'country' => 'SI', 'is_master' => 0, 'url' => 'u/si', 'title' => 'SI', 'last_synced_at' => '2026-01-01 00:00:00']);
        self::insert('landings', ['id' => 3, 'product_id' => $p, 'country' => 'DE', 'is_master' => 0, 'url' => 'u/de', 'last_synced_at' => '2026-01-01 00:00:00', 'removed_at' => '2026-01-02 00:00:00']);
        $this->userId = self::insert('users', ['username' => 'admin', 'password_hash' => 'x', 'display_name' => 'Admin']);
        $user = new class ($this->userId) implements CurrentUser {
            public function __construct(private int $id)
            {
            }

            public function id(): ?int
            {
                return $this->id;
            }

            public function displayName(): ?string
            {
                return 'Admin';
            }
        };
        $this->svc = new TestimonialService(
            new TestimonialRepository(self::$pdo),
            new LandingRepository(self::$pdo),
            new TestimonialValidator(),
            new RatingResolver(fn () => 3),
            $user,
        );
    }

    public function testCreatePresentsWithRatingDisplayAndAudit(): void
    {
        $t = $this->svc->create(1, ['author_name' => 'Ana', 'text' => 'Nice', 'rating' => 'random']);
        self::assertNull($t['rating']);
        self::assertSame(4.3, $t['rating_display']);
        self::assertSame([], $t['images']);
        self::assertSame($this->userId, $t['created_by']);
        self::assertSame(0, $t['sort_order']);
        self::assertTrue($t['is_active']);
        $fixed = $this->svc->create(1, ['author_name' => 'Bo', 'text' => 'Ok', 'rating' => 2]);
        self::assertSame(2.0, $fixed['rating_display']);
    }

    public function testCreateOnMissingOrRemovedLandingIs404(): void
    {
        $this->expectException(NotFoundException::class);
        $this->svc->create(3, ['author_name' => 'A', 'text' => 'T']);
    }

    public function testCreateValidationErrorsBubble(): void
    {
        $this->expectException(ValidationException::class);
        $this->svc->create(1, ['text' => 'no name']);
    }

    public function testListInheritsFromMasterWhenLandingHasNone(): void
    {
        $this->svc->create(1, ['author_name' => 'EN one', 'text' => 'T']);
        $this->svc->create(1, ['author_name' => 'EN two', 'text' => 'T']);
        $own = $this->svc->listForLanding(1);
        self::assertFalse($own['meta']['inherited']);
        self::assertSame(1, $own['meta']['source_landing_id']);
        self::assertSame('EN', $own['meta']['landing']['country']);

        $si = $this->svc->listForLanding(2);
        self::assertTrue($si['meta']['inherited']);
        self::assertSame(1, $si['meta']['source_landing_id']);
        self::assertSame(2, $si['meta']['landing']['id']);
        self::assertSame(['EN one', 'EN two'], array_column($si['data'], 'author_name'));

        $this->svc->create(2, ['author_name' => 'SI own', 'text' => 'T']);
        $si = $this->svc->listForLanding(2);
        self::assertFalse($si['meta']['inherited']);
        self::assertSame(['SI own'], array_column($si['data'], 'author_name'));
    }

    public function testListOnRemovedLandingIs404(): void
    {
        $this->expectException(NotFoundException::class);
        $this->svc->listForLanding(3);
    }

    public function testUpdatePartialAndDelete(): void
    {
        $t = $this->svc->create(1, ['author_name' => 'A', 'text' => 'T', 'rating' => 5]);
        $u = $this->svc->update($t['id'], ['is_active' => false]);
        self::assertFalse($u['is_active']);
        self::assertSame(5, $u['rating']);
        self::assertSame('A', $u['author_name']);
        try {
            $this->svc->update($t['id'], ['rating' => 9]);
            self::fail();
        } catch (ValidationException $e) {
            self::assertArrayHasKey('rating', $e->getFields());
        }
        $this->svc->delete($t['id']);
        $this->expectException(NotFoundException::class);
        $this->svc->get($t['id']);
    }
}
```

- [ ] **Step 2: Run — expect failures**  `make integration`

- [ ] **Step 3: Implement**

`src/Infrastructure/Repository/TestimonialRepository.php`:
```php
<?php

declare(strict_types=1);

namespace App\Infrastructure\Repository;

/**
 * @phpstan-type Row array{id:int,landing_id:int,author_name:string,text:string,rating:?int,gender:string,url:?string,is_active:bool,sort_order:int,created_at:string,updated_at:string,created_by:?int,updated_by:?int}
 */
final class TestimonialRepository
{
    private const EDITABLE = ['author_name', 'text', 'rating', 'gender', 'url', 'is_active', 'sort_order'];

    public function __construct(private readonly \PDO $pdo)
    {
    }

    /** @return list<Row> */
    public function listByLanding(int $landingId): array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM testimonials WHERE landing_id = ? ORDER BY sort_order ASC, id ASC');
        $stmt->execute([$landingId]);
        return array_map([$this, 'cast'], $stmt->fetchAll());
    }

    /** @return Row|null */
    public function find(int $id): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM testimonials WHERE id = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        return $row === false ? null : $this->cast($row);
    }

    public function countByLanding(int $landingId): int
    {
        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM testimonials WHERE landing_id = ?');
        $stmt->execute([$landingId]);
        return (int) $stmt->fetchColumn();
    }

    public function nextSortOrder(int $landingId): int
    {
        $stmt = $this->pdo->prepare('SELECT COALESCE(MAX(sort_order) + 1, 0) FROM testimonials WHERE landing_id = ?');
        $stmt->execute([$landingId]);
        return (int) $stmt->fetchColumn();
    }

    /** @param array<string,mixed> $fields  validator output (all keys) */
    public function insert(int $landingId, array $fields, ?int $userId): int
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO testimonials (landing_id, author_name, text, rating, gender, url, is_active, sort_order, created_by, updated_by)
             VALUES (:landing_id, :author_name, :text, :rating, :gender, :url, :is_active, :sort_order, :created_by, :updated_by)',
        );
        $stmt->execute([
            'landing_id' => $landingId,
            'author_name' => $fields['author_name'],
            'text' => $fields['text'],
            'rating' => $fields['rating'],
            'gender' => $fields['gender'],
            'url' => $fields['url'],
            'is_active' => (int) $fields['is_active'],
            'sort_order' => $fields['sort_order'] ?? $this->nextSortOrder($landingId),
            'created_by' => $userId,
            'updated_by' => $userId,
        ]);
        return (int) $this->pdo->lastInsertId();
    }

    /** @param array<string,mixed> $fields  subset of editable columns */
    public function update(int $id, array $fields, ?int $userId): void
    {
        if ($fields === []) {
            return;
        }
        $set = [];
        $params = ['id' => $id, 'updated_by' => $userId];
        foreach ($fields as $column => $value) {
            if (!in_array($column, self::EDITABLE, true)) {
                throw new \InvalidArgumentException("Column '$column' is not editable");
            }
            $set[] = "`$column` = :$column";
            $params[$column] = $column === 'is_active' ? (int) $value : $value;
        }
        $set[] = 'updated_by = :updated_by';
        $this->pdo->prepare('UPDATE testimonials SET ' . implode(', ', $set) . ' WHERE id = :id')->execute($params);
    }

    public function delete(int $id): bool
    {
        $stmt = $this->pdo->prepare('DELETE FROM testimonials WHERE id = ?');
        $stmt->execute([$id]);
        return $stmt->rowCount() === 1;
    }

    /**
     * @param array<string,mixed> $r
     * @return Row
     */
    private function cast(array $r): array
    {
        return [
            'id' => (int) $r['id'],
            'landing_id' => (int) $r['landing_id'],
            'author_name' => (string) $r['author_name'],
            'text' => (string) $r['text'],
            'rating' => $r['rating'] === null ? null : (int) $r['rating'],
            'gender' => (string) $r['gender'],
            'url' => $r['url'] === null ? null : (string) $r['url'],
            'is_active' => (bool) $r['is_active'],
            'sort_order' => (int) $r['sort_order'],
            'created_at' => (string) $r['created_at'],
            'updated_at' => (string) $r['updated_at'],
            'created_by' => $r['created_by'] === null ? null : (int) $r['created_by'],
            'updated_by' => $r['updated_by'] === null ? null : (int) $r['updated_by'],
        ];
    }
}
```

`src/Application/TestimonialService.php`:
```php
<?php

declare(strict_types=1);

namespace App\Application;

use App\Domain\Auth\CurrentUser;
use App\Domain\Exception\NotFoundException;
use App\Domain\Testimonial\RatingResolver;
use App\Domain\Testimonial\TestimonialValidator;
use App\Infrastructure\Repository\LandingRepository;
use App\Infrastructure\Repository\TestimonialRepository;

/**
 * @phpstan-import-type Row from TestimonialRepository
 */
final class TestimonialService
{
    public function __construct(
        private readonly TestimonialRepository $testimonials,
        private readonly LandingRepository $landings,
        private readonly TestimonialValidator $validator,
        private readonly RatingResolver $ratings,
        private readonly CurrentUser $user,
    ) {
    }

    /** @return array{data: list<array<string,mixed>>, meta: array{inherited: bool, source_landing_id: int, landing: array{id:int,country:string,is_master:bool,title:string,url:string}}} */
    public function listForLanding(int $landingId): array
    {
        $landing = $this->activeLanding($landingId);
        $sourceId = $landingId;
        $inherited = false;
        if (!(bool) $landing['is_master'] && $this->testimonials->countByLanding($landingId) === 0) {
            $master = $this->landings->findMasterFor($landingId);
            if ($master !== null) {
                $sourceId = (int) $master['id'];
                $inherited = true;
            }
        }
        $data = array_map([$this, 'present'], $this->testimonials->listByLanding($sourceId));
        return [
            'data' => $data,
            'meta' => [
                'inherited' => $inherited,
                'source_landing_id' => $sourceId,
                'landing' => [
                    'id' => (int) $landing['id'], 'country' => (string) $landing['country'], 'is_master' => (bool) $landing['is_master'],
                    'title' => (string) $landing['title'], 'url' => (string) $landing['url'],
                ],
            ],
        ];
    }

    /** @return array<string,mixed> */
    public function get(int $id): array
    {
        return $this->present($this->existing($id));
    }

    /**
     * @param array<string,mixed> $input
     * @return array<string,mixed>
     */
    public function create(int $landingId, array $input): array
    {
        $this->activeLanding($landingId);
        $fields = $this->validator->validate($input);
        $id = $this->testimonials->insert($landingId, $fields, $this->user->id());
        return $this->get($id);
    }

    /**
     * @param array<string,mixed> $input
     * @return array<string,mixed>
     */
    public function update(int $id, array $input): array
    {
        $this->existing($id);
        $fields = $this->validator->validate($input, true);
        $this->testimonials->update($id, $fields, $this->user->id());
        return $this->get($id);
    }

    public function delete(int $id): void
    {
        $this->existing($id);
        $this->testimonials->delete($id);
    }

    /**
     * @param Row $row
     * @return array<string,mixed>
     */
    public function present(array $row): array
    {
        return $row + ['rating_display' => $this->ratings->display($row['rating']), 'images' => []];
    }

    /** @return Row */
    private function existing(int $id): array
    {
        $row = $this->testimonials->find($id);
        if ($row === null) {
            throw new NotFoundException("Testimonial $id not found");
        }
        return $row;
    }

    /** @return array<string,mixed> */
    private function activeLanding(int $landingId): array
    {
        $landing = $this->landings->find($landingId);
        if ($landing === null || $landing['removed_at'] !== null) {
            throw new NotFoundException("Landing $landingId not found");
        }
        return $landing;
    }
}
```

- [ ] **Step 4: Green + commit**

```bash
make integration && make lint && make stan
git add -A && git commit -m "feat(testimonials): repository and service with read-time EN inheritance and audit fields"
```

### Task 6: Testimonial endpoints

**Files:**
- Create: `src/Http/Controller/TestimonialController.php`
- Modify: `config/container.php`, `config/routes.php`
- Test: `tests/Api/TestimonialsTest.php`

**Interfaces:** routes `GET /api/landings/{id}/testimonials`, `POST /api/landings/{id}/testimonials` (201), `GET /api/testimonials/{id}`, `PATCH /api/testimonials/{id}`, `DELETE /api/testimonials/{id}` (204). Non-numeric ids → 404. Container registers `TestimonialValidator`, `RatingResolver`, `CurrentUser::class => AnonymousUser` (phase 7 swaps it), `TestimonialRepository`, `TestimonialService`, `TestimonialController`.

- [ ] **Step 1: Failing API test**

`tests/Api/TestimonialsTest.php`:
```php
<?php

declare(strict_types=1);

namespace Tests\Api;

final class TestimonialsTest extends ApiTestCase
{
    private const EN = 61763;   // abforge master (seed)
    private const CZ = 63133;   // abforge CZ (seed)

    public function testCrudLifecycle(): void
    {
        $created = $this->request('POST', '/api/landings/' . self::EN . '/testimonials', ['author_name' => 'Api Tester', 'text' => 'Created via API', 'rating' => 'random', 'gender' => 'female']);
        self::assertSame(201, $created['status'], json_encode($created['json']));
        $t = $created['json']['testimonial'];
        self::assertNull($t['rating']);
        self::assertGreaterThanOrEqual(4.0, $t['rating_display']);
        self::assertSame([], $t['images']);
        $id = $t['id'];

        $got = $this->request('GET', "/api/testimonials/$id");
        self::assertSame(200, $got['status']);
        self::assertSame('Api Tester', $got['json']['testimonial']['author_name']);

        $patched = $this->request('PATCH', "/api/testimonials/$id", ['is_active' => false, 'rating' => 3]);
        self::assertSame(200, $patched['status']);
        self::assertFalse($patched['json']['testimonial']['is_active']);
        self::assertSame(3.0, $patched['json']['testimonial']['rating_display']);

        $list = $this->request('GET', '/api/landings/' . self::EN . '/testimonials');
        self::assertSame(200, $list['status']);
        self::assertFalse($list['json']['meta']['inherited']);
        self::assertContains($id, array_column($list['json']['data'], 'id'));

        $deleted = $this->request('DELETE', "/api/testimonials/$id");
        self::assertSame(204, $deleted['status']);
        self::assertSame(404, $this->request('GET', "/api/testimonials/$id")['status']);
        self::assertSame(404, $this->request('DELETE', "/api/testimonials/$id")['status']);
    }

    public function testValidationErrorShape(): void
    {
        $r = $this->request('POST', '/api/landings/' . self::EN . '/testimonials', ['text' => str_repeat('x', 2001), 'url' => 'nope']);
        self::assertSame(422, $r['status']);
        self::assertSame('validation_failed', $r['json']['error']['code']);
        self::assertSame(['author_name', 'text', 'url'], array_keys($r['json']['error']['fields']));
    }

    public function testInheritedListForLocalisedLandingWithoutOwn(): void
    {
        $landings = $this->request('GET', '/api/products/abforge/landings')['json']['data'];
        $inheriting = array_values(array_filter($landings, fn ($l) => $l['inherits_from_master']));
        self::assertNotEmpty($inheriting);
        $r = $this->request('GET', '/api/landings/' . $inheriting[0]['id'] . '/testimonials');
        self::assertSame(200, $r['status']);
        self::assertTrue($r['json']['meta']['inherited']);
        self::assertSame(self::EN, $r['json']['meta']['source_landing_id']);
        self::assertSame($inheriting[0]['id'], $r['json']['meta']['landing']['id']);
        self::assertNotEmpty($r['json']['data']);
    }

    public function testUnknownLandingAndBadIds(): void
    {
        self::assertSame(404, $this->request('GET', '/api/landings/999999999/testimonials')['status']);
        self::assertSame(404, $this->request('POST', '/api/landings/999999999/testimonials', ['author_name' => 'A', 'text' => 'T'])['status']);
        self::assertSame(404, $this->request('GET', '/api/testimonials/abc')['status']);
    }
}
```

- [ ] **Step 2: Run — expect 404s**  `make api`

- [ ] **Step 3: Implement**

`src/Http/Controller/TestimonialController.php`:
```php
<?php

declare(strict_types=1);

namespace App\Http\Controller;

use App\Application\TestimonialService;
use App\Domain\Exception\NotFoundException;
use App\Http\Request;
use App\Http\Response;

final class TestimonialController
{
    public function __construct(private readonly TestimonialService $service)
    {
    }

    public function index(Request $request): Response
    {
        return Response::json($this->service->listForLanding(self::id($request, 'id')));
    }

    public function store(Request $request): Response
    {
        return Response::json(['testimonial' => $this->service->create(self::id($request, 'id'), $request->body)], 201);
    }

    public function show(Request $request): Response
    {
        return Response::json(['testimonial' => $this->service->get(self::id($request, 'id'))]);
    }

    public function update(Request $request): Response
    {
        return Response::json(['testimonial' => $this->service->update(self::id($request, 'id'), $request->body)]);
    }

    public function destroy(Request $request): Response
    {
        $this->service->delete(self::id($request, 'id'));
        return Response::noContent();
    }

    /** Route ids must be positive integers; anything else is a 404, not a 500. */
    public static function id(Request $request, string $name): int
    {
        $raw = $request->attribute($name);
        if (!ctype_digit($raw) || $raw === '0') {
            throw new NotFoundException('Not found');
        }
        return (int) $raw;
    }
}
```

Container additions:
```php
use App\Application\TestimonialService;
use App\Domain\Auth\AnonymousUser;
use App\Domain\Auth\CurrentUser;
use App\Domain\Testimonial\RatingResolver;
use App\Domain\Testimonial\TestimonialValidator;
use App\Http\Controller\TestimonialController;
use App\Infrastructure\Repository\TestimonialRepository;

    $c->set(CurrentUser::class, fn () => new AnonymousUser());
    $c->set(TestimonialValidator::class, fn () => new TestimonialValidator());
    $c->set(RatingResolver::class, fn () => new RatingResolver());
    $c->set(TestimonialRepository::class, fn (Container $c) => new TestimonialRepository($c->get(PDO::class)));
    $c->set(TestimonialService::class, fn (Container $c) => new TestimonialService(
        $c->get(TestimonialRepository::class),
        $c->get(LandingRepository::class),
        $c->get(TestimonialValidator::class),
        $c->get(RatingResolver::class),
        $c->get(CurrentUser::class),
    ));
    $c->set(TestimonialController::class, fn (Container $c) => new TestimonialController($c->get(TestimonialService::class)));
```
Routes:
```php
    $r->get('/api/landings/{id}/testimonials', [TestimonialController::class, 'index']);
    $r->post('/api/landings/{id}/testimonials', [TestimonialController::class, 'store']);
    $r->get('/api/testimonials/{id}', [TestimonialController::class, 'show']);
    $r->patch('/api/testimonials/{id}', [TestimonialController::class, 'update']);
    $r->delete('/api/testimonials/{id}', [TestimonialController::class, 'destroy']);
```

- [ ] **Step 4: Green + commit**

```bash
make unit && make integration && make lint && make stan && make api
git add -A && git commit -m "feat(testimonials): REST endpoints for list, create, read, update, delete"
```

### Task 7: Testimonials view — list, form modal, unambiguous saving, delete confirm; merge phase 5

**Files:**
- Create: `public/assets/js/views/testimonials.js`, `public/assets/js/components/testimonialForm.js`, `public/assets/js/components/confirm.js`, `public/assets/js/components/saveStatus.js`
- Modify: `public/index.html`, `public/assets/js/app.js`, `public/assets/css/app.css`

**Interfaces:**
- `Confirm.ask({title, body, confirmLabel, danger}) → Promise<boolean>` (Bootstrap modal, single instance `#confirm-modal` created lazily).
- `SaveStatus.bind(el)` returns `{saving(), saved(), failed(message, retry)}` that renders into `el` (`<span class="tm-save-status">`).
- `TestimonialForm.open({landingId, testimonial|null, onSaved(testimonial)})` — Bootstrap modal `#testimonial-modal`; fields: author_name, text (+ live counter `n / 2000`), rating (select: Random, 1–5), gender (radio), url, is_active (switch), sort_order (number). Save button → POST/PATCH; 422 maps `fields` onto `.is-invalid` + `.invalid-feedback`; other errors → toast + inline alert; on success closes and calls `onSaved`. The modal footer shows "Unsaved changes" once any input changes and "Saved ✓" briefly after save. Image uploader hook: `document.getElementById('testimonial-images-slot')` is left empty here; phase 6 fills it.
- `Views.testimonials({id})` renders `#/landings/{id}`: breadcrumb (Products › SKU › COUNTRY), inherited banner when `meta.inherited`, table rows: sort order, author + gender icon, text (truncated with title), rating (`★ 4.7` + `🎲` badge when random), active switch (inline PATCH with SaveStatus), Edit, Delete (Confirm → DELETE → row removed; failure → toast, row stays). "Add testimonial" button (disabled with tooltip while inherited? No — allowed: adding the first own testimonial replaces inheritance; show a hint in the banner).

- [ ] **Step 1: Files**

`public/assets/js/components/confirm.js`:
```js
(function () {
  'use strict';
  let modal, el;
  function ensure() {
    if (el) return;
    el = document.createElement('div');
    el.className = 'modal fade';
    el.id = 'confirm-modal';
    el.tabIndex = -1;
    el.innerHTML = `<div class="modal-dialog modal-dialog-centered"><div class="modal-content">
      <div class="modal-header"><h5 class="modal-title"></h5><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cancel"></button></div>
      <div class="modal-body"></div>
      <div class="modal-footer"><button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button><button type="button" class="btn btn-danger" id="confirm-ok">Confirm</button></div>
    </div></div>`;
    document.body.appendChild(el);
    modal = new bootstrap.Modal(el);
  }
  window.Confirm = {
    ask({ title, body, confirmLabel = 'Confirm', danger = true }) {
      ensure();
      el.querySelector('.modal-title').textContent = title;
      el.querySelector('.modal-body').textContent = body;
      const ok = el.querySelector('#confirm-ok');
      ok.textContent = confirmLabel;
      ok.className = 'btn ' + (danger ? 'btn-danger' : 'btn-primary');
      return new Promise((resolve) => {
        let decided = false;
        const onOk = () => { decided = true; modal.hide(); };
        const onHidden = () => { ok.removeEventListener('click', onOk); el.removeEventListener('hidden.bs.modal', onHidden); resolve(decided); };
        ok.addEventListener('click', onOk);
        el.addEventListener('hidden.bs.modal', onHidden);
        modal.show();
      });
    },
  };
})();
```

`public/assets/js/components/saveStatus.js`:
```js
(function () {
  'use strict';
  window.SaveStatus = {
    bind(el) {
      let timer;
      const set = (cls, html) => { clearTimeout(timer); el.className = 'tm-save-status ' + cls; el.innerHTML = html; };
      return {
        saving() { set('saving', '<span class="spinner-border spinner-border-sm me-1"></span>Saving…'); },
        saved() { set('saved', '<i class="bi bi-check-circle-fill me-1"></i>Saved'); timer = setTimeout(() => { el.className = 'tm-save-status'; el.innerHTML = ''; }, 2500); },
        failed(message, retry) {
          set('failed', `<i class="bi bi-exclamation-triangle-fill me-1"></i>Failed${retry ? ' · <a href="#" class="tm-retry">Retry</a>' : ''}`);
          el.title = message;
          if (retry) el.querySelector('.tm-retry').addEventListener('click', (e) => { e.preventDefault(); retry(); });
        },
      };
    },
  };
})();
```

`public/assets/js/components/testimonialForm.js`:
```js
(function () {
  'use strict';
  let el, modal;
  const FIELDS = ['author_name', 'text', 'rating', 'gender', 'url', 'is_active', 'sort_order'];

  function ensure() {
    if (el) return;
    el = document.createElement('div');
    el.className = 'modal fade';
    el.id = 'testimonial-modal';
    el.tabIndex = -1;
    el.innerHTML = `<div class="modal-dialog modal-lg modal-dialog-scrollable"><form class="modal-content" id="testimonial-form" novalidate>
      <div class="modal-header"><h5 class="modal-title"></h5><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button></div>
      <div class="modal-body">
        <div class="alert alert-danger d-none" id="tf-error" role="alert"></div>
        <div class="row g-3">
          <div class="col-md-6"><label class="form-label" for="tf-author">Author name</label><input class="form-control" id="tf-author" name="author_name" maxlength="128" required><div class="invalid-feedback"></div></div>
          <div class="col-md-3"><label class="form-label" for="tf-rating">Rating</label><select class="form-select" id="tf-rating" name="rating"><option value="random">Random (4–5 at display)</option><option value="5">5</option><option value="4">4</option><option value="3">3</option><option value="2">2</option><option value="1">1</option></select><div class="invalid-feedback"></div></div>
          <div class="col-md-3"><label class="form-label" for="tf-sort">Sort order</label><input class="form-control" id="tf-sort" name="sort_order" type="number" min="0" step="1" placeholder="auto"><div class="invalid-feedback"></div></div>
          <div class="col-12"><label class="form-label" for="tf-text">Text <span class="text-muted small" id="tf-count">0 / 2000</span></label><textarea class="form-control" id="tf-text" name="text" rows="5" maxlength="2000" required></textarea><div class="invalid-feedback"></div></div>
          <div class="col-md-6"><label class="form-label d-block">Gender</label>
            <div class="btn-group" role="group" aria-label="Gender">
              <input type="radio" class="btn-check" name="gender" id="tf-g-m" value="male"><label class="btn btn-outline-secondary" for="tf-g-m">Male</label>
              <input type="radio" class="btn-check" name="gender" id="tf-g-f" value="female"><label class="btn btn-outline-secondary" for="tf-g-f">Female</label>
              <input type="radio" class="btn-check" name="gender" id="tf-g-u" value="unisex" checked><label class="btn btn-outline-secondary" for="tf-g-u">Unisex</label>
            </div><div class="invalid-feedback d-block"></div></div>
          <div class="col-md-6"><label class="form-label" for="tf-url">Link (URL)</label><input class="form-control" id="tf-url" name="url" type="url" placeholder="https://"><div class="invalid-feedback"></div></div>
          <div class="col-12"><div class="form-check form-switch"><input class="form-check-input" type="checkbox" id="tf-active" name="is_active" checked><label class="form-check-label" for="tf-active">Active (shown on the landing page)</label></div><div class="invalid-feedback d-block"></div></div>
          <div class="col-12" id="testimonial-images-slot"></div>
        </div>
      </div>
      <div class="modal-footer justify-content-between">
        <span id="tf-status" class="tm-save-status"></span>
        <div><button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button> <button type="submit" class="btn btn-primary" id="tf-save">Save</button></div>
      </div>
    </form></div>`;
    document.body.appendChild(el);
    modal = new bootstrap.Modal(el, { backdrop: 'static' });
    el.querySelector('#tf-text').addEventListener('input', (e) => { el.querySelector('#tf-count').textContent = `${e.target.value.length} / 2000`; });
  }

  function clearErrors() {
    el.querySelectorAll('.is-invalid').forEach((i) => i.classList.remove('is-invalid'));
    el.querySelectorAll('.invalid-feedback').forEach((f) => { f.textContent = ''; });
    const box = el.querySelector('#tf-error'); box.classList.add('d-none'); box.textContent = '';
  }

  function showFieldErrors(fields) {
    for (const [name, msg] of Object.entries(fields)) {
      const input = el.querySelector(`[name="${name}"]`);
      if (!input) continue;
      input.classList.add('is-invalid');
      const fb = input.closest('.col-12, .col-md-6, .col-md-3')?.querySelector('.invalid-feedback');
      if (fb) fb.textContent = msg;
    }
  }

  function read() {
    const f = el.querySelector('#testimonial-form');
    return {
      author_name: f.author_name.value,
      text: f.text.value,
      rating: f.rating.value,
      gender: f.gender.value,
      url: f.url.value,
      is_active: f.is_active.checked,
      sort_order: f.sort_order.value === '' ? null : f.sort_order.value,
    };
  }

  function fill(t) {
    const f = el.querySelector('#testimonial-form');
    f.author_name.value = t ? t.author_name : '';
    f.text.value = t ? t.text : '';
    f.rating.value = t && t.rating !== null ? String(t.rating) : 'random';
    f.url.value = t && t.url ? t.url : '';
    f.is_active.checked = t ? t.is_active : true;
    f.sort_order.value = t ? t.sort_order : '';
    el.querySelector(`#tf-g-${t ? t.gender[0] : 'u'}`).checked = true;
    el.querySelector('#tf-count').textContent = `${f.text.value.length} / 2000`;
  }

  window.TestimonialForm = {
    open({ landingId, testimonial, onSaved }) {
      ensure();
      clearErrors();
      fill(testimonial);
      el.querySelector('.modal-title').textContent = testimonial ? `Edit testimonial #${testimonial.id}` : 'New testimonial';
      const status = SaveStatus.bind(el.querySelector('#tf-status'));
      el.querySelector('#tf-status').innerHTML = '';
      const form = el.querySelector('#testimonial-form');
      const slot = el.querySelector('#testimonial-images-slot');
      slot.innerHTML = '';
      document.dispatchEvent(new CustomEvent('tm:testimonial-form-open', { detail: { slot, testimonial } }));
      const onChange = () => { el.querySelector('#tf-status').className = 'tm-save-status saving'; el.querySelector('#tf-status').innerHTML = '<i class="bi bi-pencil me-1"></i>Unsaved changes'; };
      FIELDS.forEach((n) => form[n] && (form[n].oninput = onChange));
      form.onsubmit = async (e) => {
        e.preventDefault();
        clearErrors();
        const btn = el.querySelector('#tf-save');
        btn.disabled = true;
        status.saving();
        try {
          const res = testimonial
            ? await Api.patch(`/api/testimonials/${testimonial.id}`, read())
            : await Api.post(`/api/landings/${landingId}/testimonials`, read());
          status.saved();
          Toast.success(testimonial ? 'Testimonial saved' : 'Testimonial created');
          modal.hide();
          onSaved(res.testimonial);
        } catch (err) {
          status.failed(err.message);
          if (err.status === 422) {
            showFieldErrors(err.fields);
          } else {
            const box = el.querySelector('#tf-error'); box.textContent = 'Could not save: ' + err.message; box.classList.remove('d-none');
            Toast.error('Could not save: ' + err.message);
          }
        } finally {
          btn.disabled = false;
        }
      };
      modal.show();
    },
    hide() { if (modal) modal.hide(); },
  };
})();
```

`public/assets/js/views/testimonials.js`:
```js
(function () {
  'use strict';
  window.Views = window.Views || {};

  const genderIcon = (g) => ({ male: 'bi-gender-male', female: 'bi-gender-female', unisex: 'bi-gender-ambiguous' }[g] || 'bi-gender-ambiguous');

  function row(t, inherited) {
    const stars = `<span class="tm-stars" title="${t.rating === null ? 'Random: shown between 4.0 and 5.0' : 'Fixed rating'}">★ ${t.rating_display.toFixed(1)}</span>${t.rating === null ? ' <span class="badge bg-warning text-dark" title="Random rating">🎲</span>' : ''}`;
    const thumbs = (t.images || []).slice(0, 3).map((i) => `<img src="media/${esc(i.thumb_filename)}" alt="" class="tm-thumb">`).join('');
    return `
      <tr data-id="${t.id}">
        <td class="text-muted tabular">${t.sort_order}</td>
        <td><i class="bi ${genderIcon(t.gender)} me-1 text-muted"></i>${esc(t.author_name)}${t.url ? ` <a href="${esc(t.url)}" target="_blank" rel="noopener" title="${esc(t.url)}"><i class="bi bi-box-arrow-up-right small"></i></a>` : ''}</td>
        <td class="tm-text" title="${esc(t.text)}">${esc(t.text)}</td>
        <td class="text-nowrap">${stars}</td>
        <td>${thumbs}${(t.images || []).length > 3 ? `<span class="badge tm-count-badge">+${t.images.length - 3}</span>` : ''}</td>
        <td><div class="d-flex align-items-center gap-2"><div class="form-check form-switch m-0"><input class="form-check-input tm-active" type="checkbox" ${t.is_active ? 'checked' : ''} ${inherited ? 'disabled' : ''} aria-label="Active"></div><span class="tm-save-status"></span></div></td>
        <td class="text-end text-nowrap">
          <button class="btn btn-sm btn-outline-primary tm-edit" ${inherited ? 'disabled' : ''}><i class="bi bi-pencil"></i> Edit</button>
          <button class="btn btn-sm btn-outline-danger tm-delete" ${inherited ? 'disabled' : ''}><i class="bi bi-trash"></i></button>
        </td>
      </tr>`;
  }

  window.Views.testimonials = async function (params) {
    const landingId = params.id;
    App.el.innerHTML = '<div class="text-muted">Loading…</div>';
    let res;
    try {
      res = await Api.get(`/api/landings/${encodeURIComponent(landingId)}/testimonials`);
    } catch (e) {
      App.el.innerHTML = `<div class="alert alert-danger">${esc(e.status === 404 ? 'Landing not found.' : 'Could not load testimonials: ' + e.message)}</div>`;
      return;
    }
    const L = res.meta.landing;
    const inherited = res.meta.inherited;
    const sku = (L.url.match(/\/([^/]+)\/?$/) || [])[1] || '';
    App.el.innerHTML = `
      <nav aria-label="breadcrumb"><ol class="breadcrumb">
        <li class="breadcrumb-item"><a href="#/products">Products</a></li>
        <li class="breadcrumb-item"><a href="#/products/${encodeURIComponent(sku)}">${esc(sku)}</a></li>
        <li class="breadcrumb-item active">${esc(L.country)}</li></ol></nav>
      <div class="d-flex flex-wrap justify-content-between align-items-end gap-2 mb-3">
        <div><h1 class="h3 mb-1"><span class="tm-country-code me-2">${esc(L.country)}</span>${esc(L.title)}</h1><a class="small" href="${esc(L.url)}" target="_blank" rel="noopener">${esc(L.url)}</a></div>
        <button class="btn btn-primary" id="add-testimonial"><i class="bi bi-plus-lg me-1"></i>Add testimonial</button>
      </div>
      ${inherited ? `<div class="alert alert-warning d-flex align-items-center gap-2"><i class="bi bi-info-circle-fill"></i><div><strong>Inherited from the English master.</strong> This landing has no testimonials of its own, so the EN set below is what visitors see. Add a testimonial here to start a local set.</div></div>` : ''}
      <div class="card"><div class="table-responsive"><table class="table table-hover align-middle mb-0" id="testimonials-table">
        <thead><tr><th>#</th><th>Author</th><th>Text</th><th>Rating</th><th>Images</th><th>Active</th><th></th></tr></thead>
        <tbody>${res.data.map((t) => row(t, inherited)).join('') || '<tr><td colspan="7" class="text-muted text-center py-4">No testimonials yet.</td></tr>'}</tbody>
      </table></div></div>`;

    const reload = () => Router.navigate(`#/landings/${landingId}`);
    document.getElementById('add-testimonial').addEventListener('click', () =>
      TestimonialForm.open({ landingId, testimonial: null, onSaved: reload }));

    const byId = Object.fromEntries(res.data.map((t) => [t.id, t]));
    document.getElementById('testimonials-table').addEventListener('click', async (e) => {
      const tr = e.target.closest('tr[data-id]');
      if (!tr) return;
      const t = byId[tr.dataset.id];
      if (e.target.closest('.tm-edit')) {
        TestimonialForm.open({ landingId, testimonial: t, onSaved: reload });
      } else if (e.target.closest('.tm-delete')) {
        const ok = await Confirm.ask({ title: 'Delete testimonial', body: `Delete the testimonial by ${t.author_name}? This cannot be undone.`, confirmLabel: 'Delete' });
        if (!ok) return;
        try {
          await Api.del(`/api/testimonials/${t.id}`);
          tr.remove();
          Toast.success('Testimonial deleted');
        } catch (err) {
          Toast.error('Could not delete: ' + err.message);
        }
      }
    });
    document.getElementById('testimonials-table').addEventListener('change', async (e) => {
      if (!e.target.classList.contains('tm-active')) return;
      const tr = e.target.closest('tr[data-id]');
      const t = byId[tr.dataset.id];
      const status = SaveStatus.bind(tr.querySelector('.tm-save-status'));
      const value = e.target.checked;
      const attempt = async () => {
        status.saving();
        e.target.disabled = true;
        try {
          const r = await Api.patch(`/api/testimonials/${t.id}`, { is_active: value });
          byId[t.id] = r.testimonial;
          status.saved();
        } catch (err) {
          e.target.checked = !value;
          status.failed(err.message, attempt);
          Toast.error('Could not save: ' + err.message);
        } finally {
          e.target.disabled = false;
        }
      };
      attempt();
    });
  };
})();
```

`app.js` — add `Router.register('#/landings/{id}', (params) => Views.testimonials(params));`. `index.html` — add scripts (after existing components): `components/confirm.js`, `components/saveStatus.js`, `components/testimonialForm.js`, `views/testimonials.js`. `app.css` — append:
```css
.tm-text { max-width: 28rem; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.tm-stars { color: var(--tm-accent); font-weight: 500; }
.tm-thumb { width: 36px; height: 36px; object-fit: cover; border-radius: 4px; margin-right: 2px; background: var(--tm-light); }
.tabular { font-variant-numeric: tabular-nums; }
```

- [ ] **Step 2: Verify**

`make up && make api`; `node --check` all JS; in the browser: open a country → add a testimonial (blank submit shows field errors under the inputs; valid submit closes and reloads with "Testimonial created" toast) → toggle active (inline "Saving… → Saved") → delete (confirm modal) → open an inheriting country and see the yellow banner with disabled row actions. Record what you saw in the report.

- [ ] **Step 3: Commit, merge phase 5**

```bash
git add -A && git commit -m "feat(ui): testimonials view with form modal, inline active toggle, delete confirm and inheritance banner"
```
Time-log row `| 5 | Testimonials CRUD (domain, service, API, UI) | 3h |`, commit `docs: time log phase 5`, push, PR `Phase 5: testimonials CRUD`, CI, merge, back to `main`.

---

## Phase 6 — Images

### Task 8: `ImageValidator` + `ImageStorage` (UUID names, GD thumbnails)

**Files:**
- Create: `src/Domain/Image/ImageValidator.php`, `src/Infrastructure/Storage/ImageStorage.php`, `src/Http/UploadedFiles.php`
- Test: `tests/Unit/Domain/Image/ImageValidatorTest.php`, `tests/Unit/Infrastructure/Storage/ImageStorageTest.php`, `tests/Unit/Http/UploadedFilesTest.php`, `tests/Support/ImageFixtures.php`

**Interfaces:**
- `Tests\Support\ImageFixtures::png(string $dir, int $w = 640, int $h = 480): string` / `jpeg(...)` / `webp(...)` / `text(...)` — write a temp file with GD and return its path (shared by unit, API and e2e-seeding code).
- `ImageValidator::__construct(int $maxBytes)`; `validate(array $file): array{tmp_path:string,mime:string,ext:string,size:int,width:int,height:int}` — `$file` is one `$_FILES` entry (`name,type,tmp_name,error,size`). Throws `HttpException(413,'payload_too_large')` when `size > maxBytes` or `error` is `UPLOAD_ERR_INI_SIZE|FORM_SIZE`; `HttpException(415,'unsupported_media_type')` when the sniffed MIME (finfo) is not `image/jpeg|image/png|image/webp` or `getimagesize` disagrees; `ValidationException(['images' => …])` for other upload errors / empty files. `ext` ∈ `jpg|png|webp`.
- `ImageStorage::__construct(string $dir, int $thumbSize = 300)`; `store(string $sourcePath, string $ext): array{filename:string,thumb_filename:string}` — UUIDv4 names, moves the file (uses `move_uploaded_file` if `is_uploaded_file`, else `rename`), writes a thumbnail whose longest side is `$thumbSize` (never upscales); `delete(string $filename, string $thumbFilename): void` (ignores missing); `path(string $filename): string`; `static isSafeFilename(string): bool` — regex `^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}(_thumb)?\.(jpg|png|webp)$`; `static mimeFor(string $filename): string`.
- `App\Http\UploadedFiles::normalize(array $files, string $field): list<array{name:string,type:string,tmp_name:string,error:int,size:int}>` — flattens both `images` (single) and `images[]` (array-of-arrays) shapes.

- [ ] **Step 1: Branch, fixtures, failing tests**

```bash
git checkout -b phase/06-images
```

`tests/Support/ImageFixtures.php` (add `"Tests\\Support\\"` is already covered by `Tests\` PSR-4):
```php
<?php

declare(strict_types=1);

namespace Tests\Support;

final class ImageFixtures
{
    public static function png(string $dir, int $w = 640, int $h = 480): string
    {
        $path = tempnam($dir, 'img') . '.png';
        imagepng(self::canvas($w, $h), $path);
        return $path;
    }

    public static function jpeg(string $dir, int $w = 640, int $h = 480): string
    {
        $path = tempnam($dir, 'img') . '.jpg';
        imagejpeg(self::canvas($w, $h), $path, 85);
        return $path;
    }

    public static function webp(string $dir, int $w = 640, int $h = 480): string
    {
        $path = tempnam($dir, 'img') . '.webp';
        imagewebp(self::canvas($w, $h), $path, 80);
        return $path;
    }

    public static function text(string $dir): string
    {
        $path = tempnam($dir, 'txt') . '.jpg';   // wrong extension on purpose
        file_put_contents($path, "not an image\n");
        return $path;
    }

    /** @return array{name:string,type:string,tmp_name:string,error:int,size:int} */
    public static function asUpload(string $path, string $name = 'photo.jpg', int $error = UPLOAD_ERR_OK): array
    {
        return ['name' => $name, 'type' => 'application/octet-stream', 'tmp_name' => $path, 'error' => $error, 'size' => (int) filesize($path)];
    }

    private static function canvas(int $w, int $h): \GdImage
    {
        $im = imagecreatetruecolor($w, $h);
        $bg = imagecolorallocate($im, 0, 96, 184);
        $fg = imagecolorallocate($im, 255, 215, 33);
        imagefilledrectangle($im, 0, 0, $w, $h, $bg);
        imagefilledellipse($im, intdiv($w, 2), intdiv($h, 2), intdiv($w, 2), intdiv($h, 2), $fg);
        return $im;
    }
}
```

`tests/Unit/Domain/Image/ImageValidatorTest.php`:
```php
<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Image;

use App\Domain\Exception\HttpException;
use App\Domain\Exception\ValidationException;
use App\Domain\Image\ImageValidator;
use PHPUnit\Framework\TestCase;
use Tests\Support\ImageFixtures;

final class ImageValidatorTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir();
    }

    public function testAcceptsJpegPngWebpAndSniffsMime(): void
    {
        $v = new ImageValidator(5_000_000);
        foreach ([['jpeg', 'image/jpeg', 'jpg'], ['png', 'image/png', 'png'], ['webp', 'image/webp', 'webp']] as [$fn, $mime, $ext]) {
            $r = $v->validate(ImageFixtures::asUpload(ImageFixtures::$fn($this->dir), 'anything.bin'));
            self::assertSame($mime, $r['mime']);
            self::assertSame($ext, $r['ext']);
            self::assertSame([640, 480], [$r['width'], $r['height']]);
        }
    }

    public function testRejectsNonImageEvenWithImageExtension(): void
    {
        $this->expectException(HttpException::class);
        $this->expectExceptionMessageMatches('/JPG, PNG or WebP/');
        try {
            (new ImageValidator(5_000_000))->validate(ImageFixtures::asUpload(ImageFixtures::text($this->dir), 'evil.jpg'));
        } catch (HttpException $e) {
            self::assertSame(415, $e->getStatus());
            throw $e;
        }
    }

    public function testRejectsOversize(): void
    {
        try {
            (new ImageValidator(1000))->validate(ImageFixtures::asUpload(ImageFixtures::png($this->dir)));
            self::fail();
        } catch (HttpException $e) {
            self::assertSame(413, $e->getStatus());
            self::assertSame('payload_too_large', $e->getErrorCode());
        }
    }

    public function testUploadErrorsAre422(): void
    {
        try {
            (new ImageValidator(5_000_000))->validate(ImageFixtures::asUpload(ImageFixtures::png($this->dir), 'x.png', UPLOAD_ERR_PARTIAL));
            self::fail();
        } catch (ValidationException $e) {
            self::assertArrayHasKey('images', $e->getFields());
        }
    }
}
```

`tests/Unit/Infrastructure/Storage/ImageStorageTest.php`:
```php
<?php

declare(strict_types=1);

namespace Tests\Unit\Infrastructure\Storage;

use App\Infrastructure\Storage\ImageStorage;
use PHPUnit\Framework\TestCase;
use Tests\Support\ImageFixtures;

final class ImageStorageTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/tm-storage-' . bin2hex(random_bytes(4));
        mkdir($this->dir);
    }

    protected function tearDown(): void
    {
        array_map('unlink', glob($this->dir . '/*') ?: []);
        rmdir($this->dir);
    }

    public function testStoresWithUuidNameAndThumbnail(): void
    {
        $storage = new ImageStorage($this->dir, 300);
        $r = $storage->store(ImageFixtures::png(sys_get_temp_dir(), 1200, 600), 'png');
        self::assertTrue(ImageStorage::isSafeFilename($r['filename']));
        self::assertTrue(ImageStorage::isSafeFilename($r['thumb_filename']));
        self::assertSame(str_replace('.png', '_thumb.png', $r['filename']), $r['thumb_filename']);
        self::assertFileExists($storage->path($r['filename']));
        self::assertFileExists($storage->path($r['thumb_filename']));
        [$w, $h] = getimagesize($storage->path($r['thumb_filename']));
        self::assertSame([300, 150], [$w, $h]);
        self::assertSame('image/png', ImageStorage::mimeFor($r['filename']));
    }

    public function testThumbnailNeverUpscalesAndKeepsFormat(): void
    {
        $storage = new ImageStorage($this->dir, 300);
        $r = $storage->store(ImageFixtures::jpeg(sys_get_temp_dir(), 100, 80), 'jpg');
        [$w, $h] = getimagesize($storage->path($r['thumb_filename']));
        self::assertSame([100, 80], [$w, $h]);
        self::assertSame('image/jpeg', mime_content_type($storage->path($r['thumb_filename'])));
        $r = $storage->store(ImageFixtures::webp(sys_get_temp_dir(), 900, 900), 'webp');
        self::assertSame('image/webp', mime_content_type($storage->path($r['thumb_filename'])));
    }

    public function testDeleteIsIdempotent(): void
    {
        $storage = new ImageStorage($this->dir, 300);
        $r = $storage->store(ImageFixtures::png(sys_get_temp_dir()), 'png');
        $storage->delete($r['filename'], $r['thumb_filename']);
        self::assertFileDoesNotExist($storage->path($r['filename']));
        $storage->delete($r['filename'], $r['thumb_filename']);   // no exception
        self::assertTrue(true);
    }

    public function testIsSafeFilenameRejectsTraversalAndForeignNames(): void
    {
        foreach (['../x.jpg', 'a.jpg', '0123456789abcdef.jpg', 'ffffffff-ffff-4fff-8fff-ffffffffffff.gif', 'ffffffff-ffff-4fff-8fff-ffffffffffff.jpg/../../.env'] as $bad) {
            self::assertFalse(ImageStorage::isSafeFilename($bad), $bad);
        }
        self::assertTrue(ImageStorage::isSafeFilename('ffffffff-ffff-4fff-8fff-ffffffffffff_thumb.webp'));
    }
}
```

`tests/Unit/Http/UploadedFilesTest.php`:
```php
<?php

declare(strict_types=1);

namespace Tests\Unit\Http;

use App\Http\UploadedFiles;
use PHPUnit\Framework\TestCase;

final class UploadedFilesTest extends TestCase
{
    public function testFlattensArrayShape(): void
    {
        $files = ['images' => ['name' => ['a.jpg', 'b.png'], 'type' => ['x', 'y'], 'tmp_name' => ['/t/a', '/t/b'], 'error' => [0, 0], 'size' => [10, 20]]];
        $list = UploadedFiles::normalize($files, 'images');
        self::assertCount(2, $list);
        self::assertSame(['name' => 'b.png', 'type' => 'y', 'tmp_name' => '/t/b', 'error' => 0, 'size' => 20], $list[1]);
    }

    public function testSingleShapeAndMissingField(): void
    {
        $files = ['images' => ['name' => 'a.jpg', 'type' => 'x', 'tmp_name' => '/t/a', 'error' => 0, 'size' => 10]];
        self::assertCount(1, UploadedFiles::normalize($files, 'images'));
        self::assertSame([], UploadedFiles::normalize([], 'images'));
    }

    public function testSkipsNoFileEntries(): void
    {
        $files = ['images' => ['name' => [''], 'type' => [''], 'tmp_name' => [''], 'error' => [UPLOAD_ERR_NO_FILE], 'size' => [0]]];
        self::assertSame([], UploadedFiles::normalize($files, 'images'));
    }
}
```

- [ ] **Step 2: Run — expect failures**  `make unit`

- [ ] **Step 3: Implement**

`src/Domain/Image/ImageValidator.php`:
```php
<?php

declare(strict_types=1);

namespace App\Domain\Image;

use App\Domain\Exception\HttpException;
use App\Domain\Exception\ValidationException;

/**
 * Server-side upload validation: size limit, MIME sniffing (never trusts the client's name/type), dimension check.
 */
final class ImageValidator
{
    public const ALLOWED = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];

    public function __construct(private readonly int $maxBytes)
    {
    }

    /**
     * @param array{name:string,type:string,tmp_name:string,error:int,size:int} $file
     * @return array{tmp_path:string,mime:string,ext:string,size:int,width:int,height:int}
     */
    public function validate(array $file): array
    {
        if (in_array($file['error'], [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true) || $file['size'] > $this->maxBytes) {
            throw new HttpException(413, 'payload_too_large', sprintf('Image must be at most %d MB', intdiv($this->maxBytes, 1_048_576)));
        }
        if ($file['error'] !== UPLOAD_ERR_OK || $file['size'] <= 0 || !is_file($file['tmp_name'])) {
            throw new ValidationException(['images' => 'Upload failed (error ' . $file['error'] . ')']);
        }
        $mime = (string) (new \finfo(FILEINFO_MIME_TYPE))->file($file['tmp_name']);
        $info = @getimagesize($file['tmp_name']);
        if (!isset(self::ALLOWED[$mime]) || $info === false || ($info['mime'] ?? null) !== $mime) {
            throw new HttpException(415, 'unsupported_media_type', 'Only JPG, PNG or WebP images are allowed');
        }
        return [
            'tmp_path' => $file['tmp_name'],
            'mime' => $mime,
            'ext' => self::ALLOWED[$mime],
            'size' => $file['size'],
            'width' => (int) $info[0],
            'height' => (int) $info[1],
        ];
    }
}
```

`src/Infrastructure/Storage/ImageStorage.php`:
```php
<?php

declare(strict_types=1);

namespace App\Infrastructure\Storage;

/**
 * Files live outside the web root under generated UUID names; thumbnails are made with GD.
 */
final class ImageStorage
{
    private const SAFE = '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}(_thumb)?\.(jpg|png|webp)$/';
    private const MIMES = ['jpg' => 'image/jpeg', 'png' => 'image/png', 'webp' => 'image/webp'];

    public function __construct(private readonly string $dir, private readonly int $thumbSize = 300)
    {
    }

    /** @return array{filename:string,thumb_filename:string} */
    public function store(string $sourcePath, string $ext): array
    {
        if (!isset(self::MIMES[$ext])) {
            throw new \InvalidArgumentException("Unsupported extension '$ext'");
        }
        if (!is_dir($this->dir) && !mkdir($this->dir, 0775, true) && !is_dir($this->dir)) {
            throw new \RuntimeException("Upload directory '{$this->dir}' is not writable");
        }
        $uuid = self::uuid4();
        $filename = "$uuid.$ext";
        $thumb = "{$uuid}_thumb.$ext";
        $target = $this->path($filename);
        $moved = is_uploaded_file($sourcePath) ? move_uploaded_file($sourcePath, $target) : rename($sourcePath, $target);
        if (!$moved) {
            throw new \RuntimeException('Could not store uploaded file');
        }
        $this->writeThumbnail($target, $this->path($thumb), $ext);
        return ['filename' => $filename, 'thumb_filename' => $thumb];
    }

    public function delete(string $filename, string $thumbFilename): void
    {
        foreach ([$filename, $thumbFilename] as $f) {
            if (self::isSafeFilename($f) && is_file($this->path($f))) {
                @unlink($this->path($f));
            }
        }
    }

    public function path(string $filename): string
    {
        return rtrim($this->dir, '/') . '/' . $filename;
    }

    public static function isSafeFilename(string $filename): bool
    {
        return preg_match(self::SAFE, $filename) === 1;
    }

    public static function mimeFor(string $filename): string
    {
        $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        return self::MIMES[$ext] ?? 'application/octet-stream';
    }

    private function writeThumbnail(string $source, string $target, string $ext): void
    {
        $image = match ($ext) {
            'jpg' => imagecreatefromjpeg($source),
            'png' => imagecreatefrompng($source),
            'webp' => imagecreatefromwebp($source),
        };
        if ($image === false) {
            throw new \RuntimeException('Could not decode image');
        }
        $w = imagesx($image);
        $h = imagesy($image);
        $scale = min(1.0, $this->thumbSize / max($w, $h));
        $tw = max(1, (int) round($w * $scale));
        $th = max(1, (int) round($h * $scale));
        $thumb = imagescale($image, $tw, $th, IMG_BICUBIC);
        if ($thumb === false) {
            throw new \RuntimeException('Could not scale image');
        }
        if ($ext === 'png') {
            imagealphablending($thumb, false);
            imagesavealpha($thumb, true);
        }
        $ok = match ($ext) {
            'jpg' => imagejpeg($thumb, $target, 85),
            'png' => imagepng($thumb, $target, 6),
            'webp' => imagewebp($thumb, $target, 82),
        };
        if (!$ok) {
            throw new \RuntimeException('Could not write thumbnail');
        }
    }

    private static function uuid4(): string
    {
        $b = random_bytes(16);
        $b[6] = chr((ord($b[6]) & 0x0f) | 0x40);
        $b[8] = chr((ord($b[8]) & 0x3f) | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($b), 4));
    }
}
```

`src/Http/UploadedFiles.php`:
```php
<?php

declare(strict_types=1);

namespace App\Http;

final class UploadedFiles
{
    /**
     * Flattens PHP's $_FILES shape (single or `field[]`) into a list of upload entries, skipping "no file" slots.
     *
     * @param array<string,mixed> $files
     * @return list<array{name:string,type:string,tmp_name:string,error:int,size:int}>
     */
    public static function normalize(array $files, string $field): array
    {
        if (!isset($files[$field]) || !is_array($files[$field])) {
            return [];
        }
        $f = $files[$field];
        $names = is_array($f['name'] ?? null) ? $f['name'] : [$f['name'] ?? ''];
        $out = [];
        foreach (array_keys($names) as $i) {
            $pick = static fn (string $k) => is_array($f[$k] ?? null) ? ($f[$k][$i] ?? null) : ($f[$k] ?? null);
            $error = (int) ($pick('error') ?? UPLOAD_ERR_NO_FILE);
            if ($error === UPLOAD_ERR_NO_FILE) {
                continue;
            }
            $out[] = [
                'name' => (string) $pick('name'),
                'type' => (string) $pick('type'),
                'tmp_name' => (string) $pick('tmp_name'),
                'error' => $error,
                'size' => (int) $pick('size'),
            ];
        }
        return $out;
    }
}
```

- [ ] **Step 4: Green + commit**

```bash
make unit && make lint && make stan
git add -A && git commit -m "feat(images): upload validator, UUID image storage with GD thumbnails, \$_FILES normaliser"
```

### Task 9: `ImageRepository`, `ImageService`, image + media endpoints, images on testimonials

**Files:**
- Create: `src/Infrastructure/Repository/ImageRepository.php`, `src/Application/ImageService.php`, `src/Http/Controller/ImageController.php`, `src/Http/Controller/MediaController.php`
- Modify: `src/Application/TestimonialService.php` (attach images), `config/container.php`, `config/routes.php`
- Test: `tests/Integration/Repository/ImageRepositoryTest.php`, `tests/Api/ImagesTest.php`

**Interfaces:**
- `ImageRepository(\PDO)`: `listByTestimonialIds(list<int>): array<int, list<ImgRow>>` (keyed by testimonial id, ordered by `sort_order, id`), `find(int): ?ImgRow`, `insert(int $testimonialId, array{filename,thumb_filename,mime,size_bytes,width,height} $data, ?int $userId): int` (sort_order = max+1), `delete(int): bool`. `ImgRow` = `array{id:int,testimonial_id:int,filename:string,thumb_filename:string,mime:string,size_bytes:int,width:int,height:int,sort_order:int,created_at:string,created_by:?int}`.
- `ImageService(ImageRepository, TestimonialRepository, ImageValidator, ImageStorage, CurrentUser)`: `upload(int $testimonialId, list<UploadEntry> $files): list<ImgRow>` — 404 if testimonial missing, 422 `images` when the list is empty, validates *all* files before storing any (so one bad file rejects the batch with the right status), stores each, inserts rows; `delete(int $imageId): void` — 404, removes row then files.
- `TestimonialService::present()` now fills `images` — constructor gains `ImageRepository $images`; `listForLanding` fetches images for all rows in one query (`listByTestimonialIds`), `get` for one.
- Routes: `POST /api/testimonials/{id}/images` (multipart field `images[]`, 201 `{images:[...]}`), `DELETE /api/images/{id}` (204), `GET /media/{filename}` (200 with correct `Content-Type` and `Cache-Control: private, max-age=86400`; 404 for unsafe names or missing files).

- [ ] **Step 1: Failing tests**

`tests/Integration/Repository/ImageRepositoryTest.php`:
```php
<?php

declare(strict_types=1);

namespace Tests\Integration\Repository;

use App\Infrastructure\Repository\ImageRepository;
use Tests\Integration\DatabaseTestCase;

final class ImageRepositoryTest extends DatabaseTestCase
{
    private ImageRepository $repo;
    private int $t1;
    private int $t2;

    protected function setUp(): void
    {
        parent::setUp();
        $this->repo = new ImageRepository(self::$pdo);
        $p = self::insert('products', ['parent_sku' => 'abforge', 'title' => 'AbForge']);
        self::insert('landings', ['id' => 1, 'product_id' => $p, 'country' => 'EN', 'is_master' => 1, 'url' => 'u', 'last_synced_at' => '2026-01-01 00:00:00']);
        $this->t1 = self::insert('testimonials', ['landing_id' => 1, 'author_name' => 'A', 'text' => 'T', 'gender' => 'unisex', 'sort_order' => 0]);
        $this->t2 = self::insert('testimonials', ['landing_id' => 1, 'author_name' => 'B', 'text' => 'T', 'gender' => 'unisex', 'sort_order' => 1]);
    }

    private function data(string $n): array
    {
        return ['filename' => "$n.jpg", 'thumb_filename' => "{$n}_thumb.jpg", 'mime' => 'image/jpeg', 'size_bytes' => 100, 'width' => 10, 'height' => 5];
    }

    public function testInsertListDelete(): void
    {
        $a = $this->repo->insert($this->t1, $this->data('a'), null);
        $b = $this->repo->insert($this->t1, $this->data('b'), null);
        $c = $this->repo->insert($this->t2, $this->data('c'), null);
        $map = $this->repo->listByTestimonialIds([$this->t1, $this->t2, 999]);
        self::assertSame([$a, $b], array_column($map[$this->t1], 'id'));
        self::assertSame([0, 1], array_column($map[$this->t1], 'sort_order'));
        self::assertSame([$c], array_column($map[$this->t2], 'id'));
        self::assertArrayNotHasKey(999, $map);
        self::assertSame([], $this->repo->listByTestimonialIds([]));
        self::assertSame('a.jpg', $this->repo->find($a)['filename']);
        self::assertTrue($this->repo->delete($a));
        self::assertFalse($this->repo->delete($a));
    }

    public function testCascadeOnTestimonialDelete(): void
    {
        $this->repo->insert($this->t1, $this->data('a'), null);
        self::$pdo->exec("DELETE FROM testimonials WHERE id = {$this->t1}");
        self::assertSame([], $this->repo->listByTestimonialIds([$this->t1]));
    }
}
```

`tests/Api/ImagesTest.php`:
```php
<?php

declare(strict_types=1);

namespace Tests\Api;

use Tests\Support\ImageFixtures;

final class ImagesTest extends ApiTestCase
{
    private const EN = 61763;

    private function newTestimonial(): int
    {
        $r = $this->request('POST', '/api/landings/' . self::EN . '/testimonials', ['author_name' => 'Img Tester', 'text' => 'with images']);
        self::assertSame(201, $r['status']);
        return $r['json']['testimonial']['id'];
    }

    public function testUploadListServeDelete(): void
    {
        $id = $this->newTestimonial();
        $tmp = sys_get_temp_dir();
        $r = $this->upload("/api/testimonials/$id/images", ['images[0]' => ImageFixtures::png($tmp, 800, 400), 'images[1]' => ImageFixtures::jpeg($tmp)]);
        self::assertSame(201, $r['status'], json_encode($r['json']));
        $images = $r['json']['images'];
        self::assertCount(2, $images);
        self::assertSame([0, 1], array_column($images, 'sort_order'));
        self::assertMatchesRegularExpression('/^[0-9a-f-]{36}\.png$/', $images[0]['filename']);

        $t = $this->request('GET', "/api/testimonials/$id")['json']['testimonial'];
        self::assertCount(2, $t['images']);

        $list = $this->request('GET', '/api/landings/' . self::EN . '/testimonials')['json']['data'];
        $mine = array_values(array_filter($list, fn ($x) => $x['id'] === $id))[0];
        self::assertCount(2, $mine['images']);

        $media = $this->raw('/media/' . $images[0]['thumb_filename']);
        self::assertSame(200, $media['status']);
        self::assertSame('image/png', $media['content_type']);
        [$w] = getimagesizefromstring($media['body']);
        self::assertSame(300, $w);

        self::assertSame(204, $this->request('DELETE', '/api/images/' . $images[0]['id'])['status']);
        self::assertSame(404, $this->raw('/media/' . $images[0]['thumb_filename'])['status']);
        self::assertSame(404, $this->request('DELETE', '/api/images/' . $images[0]['id'])['status']);
        $this->request('DELETE', "/api/testimonials/$id");
    }

    public function testRejectsNonImageAndEmptyBatch(): void
    {
        $id = $this->newTestimonial();
        $r = $this->upload("/api/testimonials/$id/images", ['images[0]' => ImageFixtures::text(sys_get_temp_dir())]);
        self::assertSame(415, $r['status']);
        self::assertSame('unsupported_media_type', $r['json']['error']['code']);
        $r = $this->upload("/api/testimonials/$id/images", []);
        self::assertSame(422, $r['status']);
        self::assertArrayHasKey('images', $r['json']['error']['fields']);
        $r = $this->upload('/api/testimonials/999999999/images', ['images[0]' => ImageFixtures::png(sys_get_temp_dir())]);
        self::assertSame(404, $r['status']);
        $this->request('DELETE', "/api/testimonials/$id");
    }

    public function testMediaRejectsUnsafeNames(): void
    {
        self::assertSame(404, $this->raw('/media/..%2F..%2F.env')['status']);
        self::assertSame(404, $this->raw('/media/nope.jpg')['status']);
    }

    /** @return array{status:int,content_type:string,body:string} */
    private function raw(string $path): array
    {
        $ch = curl_init($this->baseUrl() . $path);
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_COOKIEFILE => $this->cookieJarPath(), CURLOPT_COOKIEJAR => $this->cookieJarPath()]);
        $body = (string) curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $ct = (string) curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
        curl_close($ch);
        return ['status' => $status, 'content_type' => $ct, 'body' => $body];
    }
}
```
Add to `tests/Api/ApiTestCase.php`: `protected function cookieJarPath(): string { return $this->cookieJar; }`.

- [ ] **Step 2: Run — expect failures**  `make integration` (repo), then `make api` after wiring

- [ ] **Step 3: Implement**

`src/Infrastructure/Repository/ImageRepository.php`:
```php
<?php

declare(strict_types=1);

namespace App\Infrastructure\Repository;

/**
 * @phpstan-type ImgRow array{id:int,testimonial_id:int,filename:string,thumb_filename:string,mime:string,size_bytes:int,width:int,height:int,sort_order:int,created_at:string,created_by:?int}
 */
final class ImageRepository
{
    public function __construct(private readonly \PDO $pdo)
    {
    }

    /**
     * @param list<int> $testimonialIds
     * @return array<int, list<ImgRow>>
     */
    public function listByTestimonialIds(array $testimonialIds): array
    {
        if ($testimonialIds === []) {
            return [];
        }
        $marks = implode(',', array_fill(0, count($testimonialIds), '?'));
        $stmt = $this->pdo->prepare("SELECT * FROM testimonial_images WHERE testimonial_id IN ($marks) ORDER BY testimonial_id, sort_order ASC, id ASC");
        $stmt->execute(array_values($testimonialIds));
        $map = [];
        foreach ($stmt->fetchAll() as $r) {
            $row = $this->cast($r);
            $map[$row['testimonial_id']][] = $row;
        }
        return $map;
    }

    /** @return ImgRow|null */
    public function find(int $id): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM testimonial_images WHERE id = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        return $row === false ? null : $this->cast($row);
    }

    /** @param array{filename:string,thumb_filename:string,mime:string,size_bytes:int,width:int,height:int} $data */
    public function insert(int $testimonialId, array $data, ?int $userId): int
    {
        $next = $this->pdo->prepare('SELECT COALESCE(MAX(sort_order) + 1, 0) FROM testimonial_images WHERE testimonial_id = ?');
        $next->execute([$testimonialId]);
        $stmt = $this->pdo->prepare(
            'INSERT INTO testimonial_images (testimonial_id, filename, thumb_filename, mime, size_bytes, width, height, sort_order, created_by)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)',
        );
        $stmt->execute([$testimonialId, $data['filename'], $data['thumb_filename'], $data['mime'], $data['size_bytes'], $data['width'], $data['height'], (int) $next->fetchColumn(), $userId]);
        return (int) $this->pdo->lastInsertId();
    }

    public function delete(int $id): bool
    {
        $stmt = $this->pdo->prepare('DELETE FROM testimonial_images WHERE id = ?');
        $stmt->execute([$id]);
        return $stmt->rowCount() === 1;
    }

    /**
     * @param array<string,mixed> $r
     * @return ImgRow
     */
    private function cast(array $r): array
    {
        return [
            'id' => (int) $r['id'], 'testimonial_id' => (int) $r['testimonial_id'],
            'filename' => (string) $r['filename'], 'thumb_filename' => (string) $r['thumb_filename'], 'mime' => (string) $r['mime'],
            'size_bytes' => (int) $r['size_bytes'], 'width' => (int) $r['width'], 'height' => (int) $r['height'],
            'sort_order' => (int) $r['sort_order'], 'created_at' => (string) $r['created_at'],
            'created_by' => $r['created_by'] === null ? null : (int) $r['created_by'],
        ];
    }
}
```

`src/Application/ImageService.php`:
```php
<?php

declare(strict_types=1);

namespace App\Application;

use App\Domain\Auth\CurrentUser;
use App\Domain\Exception\NotFoundException;
use App\Domain\Exception\ValidationException;
use App\Domain\Image\ImageValidator;
use App\Infrastructure\Repository\ImageRepository;
use App\Infrastructure\Repository\TestimonialRepository;
use App\Infrastructure\Storage\ImageStorage;

/**
 * @phpstan-import-type ImgRow from ImageRepository
 */
final class ImageService
{
    public function __construct(
        private readonly ImageRepository $images,
        private readonly TestimonialRepository $testimonials,
        private readonly ImageValidator $validator,
        private readonly ImageStorage $storage,
        private readonly CurrentUser $user,
    ) {
    }

    /**
     * @param list<array{name:string,type:string,tmp_name:string,error:int,size:int}> $files
     * @return list<ImgRow>
     */
    public function upload(int $testimonialId, array $files): array
    {
        if ($this->testimonials->find($testimonialId) === null) {
            throw new NotFoundException("Testimonial $testimonialId not found");
        }
        if ($files === []) {
            throw new ValidationException(['images' => 'Choose at least one image']);
        }
        // Validate everything first so a bad file rejects the whole batch before anything is written.
        $checked = array_map(fn (array $f) => $this->validator->validate($f), $files);
        $stored = [];
        foreach ($checked as $c) {
            $names = $this->storage->store($c['tmp_path'], $c['ext']);
            $id = $this->images->insert($testimonialId, [
                'filename' => $names['filename'], 'thumb_filename' => $names['thumb_filename'], 'mime' => $c['mime'],
                'size_bytes' => $c['size'], 'width' => $c['width'], 'height' => $c['height'],
            ], $this->user->id());
            $row = $this->images->find($id);
            if ($row !== null) {
                $stored[] = $row;
            }
        }
        return $stored;
    }

    public function delete(int $imageId): void
    {
        $row = $this->images->find($imageId);
        if ($row === null) {
            throw new NotFoundException("Image $imageId not found");
        }
        $this->images->delete($imageId);
        $this->storage->delete($row['filename'], $row['thumb_filename']);
    }
}
```

`TestimonialService` changes: add constructor param `private readonly ImageRepository $images` (after `$landings`); `listForLanding`: `$rows = $this->testimonials->listByLanding($sourceId); $imgs = $this->images->listByTestimonialIds(array_column($rows, 'id')); $data = array_map(fn ($r) => $this->present($r, $imgs[$r['id']] ?? []), $rows);`; `get`: `$row = $this->existing($id); return $this->present($row, $this->images->listByTestimonialIds([$id])[$id] ?? []);`; `present(array $row, array $images = []): array` returns `$row + ['rating_display' => …, 'images' => $images]`. Update `tests/Integration/Application/TestimonialServiceTest.php` constructor call to pass `new ImageRepository(self::$pdo)`.

`src/Http/Controller/ImageController.php`:
```php
<?php

declare(strict_types=1);

namespace App\Http\Controller;

use App\Application\ImageService;
use App\Http\Request;
use App\Http\Response;
use App\Http\UploadedFiles;

final class ImageController
{
    public function __construct(private readonly ImageService $service)
    {
    }

    public function store(Request $request): Response
    {
        $files = UploadedFiles::normalize($request->files, 'images');
        return Response::json(['images' => $this->service->upload(TestimonialController::id($request, 'id'), $files)], 201);
    }

    public function destroy(Request $request): Response
    {
        $this->service->delete(TestimonialController::id($request, 'id'));
        return Response::noContent();
    }
}
```

`src/Http/Controller/MediaController.php`:
```php
<?php

declare(strict_types=1);

namespace App\Http\Controller;

use App\Domain\Exception\NotFoundException;
use App\Http\Request;
use App\Http\Response;
use App\Infrastructure\Storage\ImageStorage;

/**
 * Streams uploaded images from outside the web root. The filename must match the UUID pattern
 * exactly — no path separators can ever reach the filesystem.
 */
final class MediaController
{
    public function __construct(private readonly ImageStorage $storage)
    {
    }

    public function show(Request $request): Response
    {
        $name = $request->attribute('filename');
        if (!ImageStorage::isSafeFilename($name) || !is_file($this->storage->path($name))) {
            throw new NotFoundException('Not found');
        }
        return Response::file($this->storage->path($name), ImageStorage::mimeFor($name))
            ->withHeader('Cache-Control', 'private, max-age=86400');
    }
}
```

Container additions:
```php
use App\Application\ImageService;
use App\Domain\Image\ImageValidator;
use App\Http\Controller\ImageController;
use App\Http\Controller\MediaController;
use App\Infrastructure\Repository\ImageRepository;
use App\Infrastructure\Storage\ImageStorage;

    $c->set(ImageRepository::class, fn (Container $c) => new ImageRepository($c->get(PDO::class)));
    $c->set(ImageValidator::class, fn () => new ImageValidator((int) $config['upload']['max_bytes']));
    $c->set(ImageStorage::class, fn () => new ImageStorage($config['upload']['dir']));
    $c->set(ImageService::class, fn (Container $c) => new ImageService(
        $c->get(ImageRepository::class), $c->get(TestimonialRepository::class), $c->get(ImageValidator::class), $c->get(ImageStorage::class), $c->get(CurrentUser::class),
    ));
    $c->set(ImageController::class, fn (Container $c) => new ImageController($c->get(ImageService::class)));
    $c->set(MediaController::class, fn (Container $c) => new MediaController($c->get(ImageStorage::class)));
```
and pass `$c->get(ImageRepository::class)` into `TestimonialService`. Routes:
```php
    $r->post('/api/testimonials/{id}/images', [ImageController::class, 'store']);
    $r->delete('/api/images/{id}', [ImageController::class, 'destroy']);
    $r->get('/media/{filename}', [MediaController::class, 'show']);
```
`Router` patterns use `[^/]+` for params, so `/media/../x` never matches; the URL-encoded `%2F` test hits the controller and is rejected by the regex.

Note for `php -S` (CI/`make api`): `public/index.php`'s static passthrough only returns files that exist under `public/`, so `/media/...` always reaches the router. Apache: `.htaccess` rewrites non-files to `index.php` — same.

- [ ] **Step 4: Green + commit**

```bash
make unit && make integration && make lint && make stan && make api
git add -A && git commit -m "feat(images): upload, delete and media endpoints; images attached to testimonial responses"
```

### Task 10: Seed image files, uploader UI; merge phase 6

**Files:**
- Create: `database/seed-images.php`, `public/assets/js/components/imageUploader.js`
- Modify: `Makefile` (`seed` target), `.github/workflows/ci.yml` ("Start app server" step), `README.md` (XAMPP step), `public/index.html`, `public/assets/js/components/testimonialForm.js` (listen to the open event), `public/assets/css/app.css`

**Interfaces:**
- `php database/seed-images.php` — for every `testimonial_images` row whose file is missing in `config['upload']['dir']`, writes a 640×480 JPEG (DFVU blue background, yellow initial of the author) and its 300 px thumbnail under the row's filenames. Idempotent; prints the count.
- `ImageUploader.mount(slot, testimonial)` — renders inside the form modal: current images as thumbnails with a delete button (Confirm → `DELETE /api/images/{id}`), a drop zone + file input (`accept="image/jpeg,image/png,image/webp"`, multiple) that uploads immediately via `Api.upload('/api/testimonials/{id}/images', FormData)` with per-batch status; for a *new* testimonial (no id yet) shows "Save the testimonial first to add images" instead.

- [ ] **Step 1: Seed images script**

`database/seed-images.php`:
```php
<?php

declare(strict_types=1);

/**
 * Generates placeholder image files for seeded testimonial_images rows whose files are missing.
 * Usage: php database/seed-images.php   (idempotent; safe to re-run)
 */

require __DIR__ . '/../vendor/autoload.php';

use App\Infrastructure\Db\PdoFactory;

$config = require __DIR__ . '/../config/config.php';
$pdo = PdoFactory::create($config['db']);
$dir = rtrim((string) $config['upload']['dir'], '/');
if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
    fwrite(STDERR, "Cannot create $dir\n");
    exit(1);
}

$rows = $pdo->query('SELECT i.filename, i.thumb_filename, t.author_name FROM testimonial_images i JOIN testimonials t ON t.id = i.testimonial_id')->fetchAll();
$written = 0;
foreach ($rows as $row) {
    $full = "$dir/{$row['filename']}";
    $thumb = "$dir/{$row['thumb_filename']}";
    if (is_file($full) && is_file($thumb)) {
        continue;
    }
    $initial = mb_strtoupper(mb_substr(trim((string) $row['author_name']), 0, 1)) ?: '?';
    foreach ([[$full, 640, 480, 5], [$thumb, 300, 225, 3]] as [$path, $w, $h, $font]) {
        $im = imagecreatetruecolor($w, $h);
        imagefilledrectangle($im, 0, 0, $w, $h, imagecolorallocate($im, 0, 96, 184));
        imagefilledellipse($im, intdiv($w, 2), intdiv($h, 2), intdiv($h, 2), intdiv($h, 2), imagecolorallocate($im, 255, 215, 33));
        $tw = imagefontwidth($font) * strlen($initial);
        imagestring($im, $font, intdiv($w - $tw, 2), intdiv($h - imagefontheight($font), 2), $initial, imagecolorallocate($im, 40, 40, 53));
        imagejpeg($im, $path, 82);
    }
    $written++;
}
echo "seed-images: wrote $written of " . count($rows) . " image pairs into $dir\n";
```
Makefile `seed:` — append a third line `$(COMPOSE) exec app php database/seed-images.php`. CI "Start app server" step — add `php database/seed-images.php` after the seed load. README XAMPP step 3 → "Import `database/schema.sql`, then `database/seed.sql`, then run `php database/seed-images.php` (creates the demo photos)". Run `make seed` locally and confirm `ls storage/uploads | wc -l` ≈ 130.

- [ ] **Step 2: Uploader component**

`public/assets/js/components/imageUploader.js`:
```js
(function () {
  'use strict';
  function thumbCard(img) {
    return `<div class="tm-img-card" data-image-id="${img.id}">
      <img src="media/${esc(img.thumb_filename)}" alt="" width="96" height="96">
      <button type="button" class="btn btn-sm btn-danger tm-img-delete" title="Delete image" aria-label="Delete image"><i class="bi bi-x-lg"></i></button>
      <small class="text-muted d-block text-truncate">${img.width}×${img.height}</small>
    </div>`;
  }

  window.ImageUploader = {
    mount(slot, testimonial) {
      if (!testimonial) {
        slot.innerHTML = '<label class="form-label">Images</label><div class="text-muted small"><i class="bi bi-info-circle me-1"></i>Save the testimonial first, then add images.</div>';
        return;
      }
      const images = [...(testimonial.images || [])];
      slot.innerHTML = `
        <label class="form-label">Images <span class="text-muted small">(JPG, PNG, WebP · max 5 MB each)</span></label>
        <div class="tm-img-grid" id="iu-grid">${images.map(thumbCard).join('')}</div>
        <div class="tm-dropzone" id="iu-drop" tabindex="0" role="button" aria-label="Add images">
          <i class="bi bi-cloud-arrow-up fs-3 d-block"></i><span>Drop images here or <u>choose files</u></span>
          <input type="file" id="iu-input" accept="image/jpeg,image/png,image/webp" multiple hidden>
        </div>
        <div class="d-flex align-items-center gap-2 mt-1"><span id="iu-status" class="tm-save-status"></span></div>`;
      const grid = slot.querySelector('#iu-grid');
      const drop = slot.querySelector('#iu-drop');
      const input = slot.querySelector('#iu-input');
      const status = SaveStatus.bind(slot.querySelector('#iu-status'));

      async function send(fileList) {
        const files = [...fileList];
        if (!files.length) return;
        const fd = new FormData();
        files.forEach((f) => fd.append('images[]', f, f.name));
        status.saving();
        try {
          const res = await Api.upload(`/api/testimonials/${testimonial.id}/images`, fd);
          res.images.forEach((img) => { images.push(img); grid.insertAdjacentHTML('beforeend', thumbCard(img)); });
          testimonial.images = images;
          status.saved();
          Toast.success(`${res.images.length} image${res.images.length === 1 ? '' : 's'} uploaded`);
        } catch (err) {
          status.failed(err.message);
          Toast.error('Upload failed: ' + (err.fields && err.fields.images ? err.fields.images : err.message));
        } finally {
          input.value = '';
        }
      }
      drop.addEventListener('click', () => input.click());
      drop.addEventListener('keydown', (e) => { if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); input.click(); } });
      input.addEventListener('change', () => send(input.files));
      ['dragenter', 'dragover'].forEach((ev) => drop.addEventListener(ev, (e) => { e.preventDefault(); drop.classList.add('is-over'); }));
      ['dragleave', 'drop'].forEach((ev) => drop.addEventListener(ev, (e) => { e.preventDefault(); drop.classList.remove('is-over'); }));
      drop.addEventListener('drop', (e) => send(e.dataTransfer.files));

      grid.addEventListener('click', async (e) => {
        const btn = e.target.closest('.tm-img-delete');
        if (!btn) return;
        const card = btn.closest('.tm-img-card');
        const id = parseInt(card.dataset.imageId, 10);
        const ok = await Confirm.ask({ title: 'Delete image', body: 'Remove this image from the testimonial?', confirmLabel: 'Delete' });
        if (!ok) return;
        try {
          await Api.del(`/api/images/${id}`);
          card.remove();
          const i = images.findIndex((x) => x.id === id);
          if (i >= 0) images.splice(i, 1);
          testimonial.images = images;
          Toast.success('Image deleted');
        } catch (err) {
          Toast.error('Could not delete image: ' + err.message);
        }
      });
    },
  };

  document.addEventListener('tm:testimonial-form-open', (e) => ImageUploader.mount(e.detail.slot, e.detail.testimonial));
})();
```
`index.html`: add `components/imageUploader.js` after `testimonialForm.js`. `app.css` append:
```css
.tm-img-grid { display: flex; flex-wrap: wrap; gap: .5rem; margin-bottom: .5rem; }
.tm-img-card { position: relative; width: 96px; }
.tm-img-card img { width: 96px; height: 96px; object-fit: cover; border-radius: 4px; background: var(--tm-light); }
.tm-img-delete { position: absolute; top: -6px; right: -6px; padding: 0 .3rem; line-height: 1.4; }
.tm-dropzone { border: 2px dashed var(--line, #ccd); border-radius: 6px; padding: 1rem; text-align: center; color: #6c757d; cursor: pointer; }
.tm-dropzone.is-over { border-color: var(--tm-primary); background: rgba(0,96,184,.06); }
```
Because the form's `onSaved` reloads the view, images added during editing show up in the table thumbnails after closing the modal. The testimonials view row already renders `t.images` thumbnails (Task 7).

- [ ] **Step 3: Verify, commit, merge phase 6**

`make up && make seed && make api`; browser: edit a seeded testimonial → its placeholder photo shows in the modal → drop a PNG → thumbnail appears with "Saved" → delete it (confirm) → close; the row's thumbnails updated. Try a `.txt` renamed to `.jpg` → red toast "Only JPG, PNG or WebP images are allowed".
```bash
git add -A && git commit -m "feat(images): seeded placeholder photos and drag-and-drop uploader in the testimonial form"
```
Time-log row `| 6 | Images (validation, storage, endpoints, uploader) | 3h |`, commit, push, PR `Phase 6: images`, merge, `main`.

---

## Phase 7 — Login

### Task 11: Session auth — `UserRepository`, `SessionAuth`, `AuthService`, `AuthMiddleware`, auth endpoints

**Files:**
- Create: `src/Infrastructure/Repository/UserRepository.php`, `src/Infrastructure/Auth/SessionAuth.php`, `src/Application/AuthService.php`, `src/Http/Middleware/AuthMiddleware.php`, `src/Http/Controller/AuthController.php`
- Modify: `config/container.php` (swap `CurrentUser`, add middleware), `config/routes.php`, `tests/Api/ApiTestCase.php` (auto-login), `README.md` (credentials)
- Test: `tests/Integration/Repository/UserRepositoryTest.php`, `tests/Unit/Http/Middleware/AuthMiddlewareTest.php`, `tests/Api/AuthTest.php`

**Interfaces:**
- `UserRepository(\PDO)`: `findByUsername(string): ?array{id:int,username:string,password_hash:string,display_name:string}`, `find(int): ?array{id:int,username:string,display_name:string}`.
- `SessionAuth::__construct(string $sessionName)` implements `CurrentUser`; `start(): void` (idempotent; `session_name`, `session_set_cookie_params(['httponly'=>true,'samesite'=>'Lax','secure'=>$isHttps,'path'=>'/'])`, `session_start()` only when `PHP_SAPI !== 'cli'` or headers not sent); `login(int $id, string $displayName): void` (regenerates id); `logout(): void` (destroys session + expires cookie); `id(): ?int`; `displayName(): ?string`; `isAuthenticated(): bool`.
- `AuthService(UserRepository, SessionAuth)`: `login(string $username, string $password): array{id:int,username:string,display_name:string}` — 422 when either field is blank; 401 `invalid_credentials` (`UnauthorizedException` message "Invalid username or password") otherwise; `password_verify`; `logout(): void`; `current(): ?array{id,username,display_name}`.
- `AuthMiddleware(SessionAuth)`: public routes = `POST /api/auth/login`, `GET /api/health`, `GET /api/auth/me`? **No** — `me` returns 401 when logged out (the SPA uses it to decide). Rule: if path starts with `/api/` or `/media/` and is not `POST /api/auth/login` or `GET /api/health` → require `isAuthenticated()` else throw `UnauthorizedException`. Non-API paths (`/`) pass.
- Routes: `POST /api/auth/login` (200 `{user}`), `POST /api/auth/logout` (204), `GET /api/auth/me` (200 `{user}` | 401).
- `ApiTestCase::setUp()` calls `$this->login()` (posts admin/admin123 once per test class using a static flag keyed by class); `login(string $u = 'admin', string $p = 'admin123'): array`; `logout(): void` (posts logout and deletes the jar).

- [ ] **Step 1: Branch, failing tests**

```bash
git checkout -b phase/07-login
```

`tests/Integration/Repository/UserRepositoryTest.php`:
```php
<?php

declare(strict_types=1);

namespace Tests\Integration\Repository;

use App\Infrastructure\Repository\UserRepository;
use Tests\Integration\DatabaseTestCase;

final class UserRepositoryTest extends DatabaseTestCase
{
    public function testFindByUsernameAndFind(): void
    {
        $id = self::insert('users', ['username' => 'admin', 'password_hash' => password_hash('pw', PASSWORD_DEFAULT), 'display_name' => 'Demo Admin']);
        $repo = new UserRepository(self::$pdo);
        $u = $repo->findByUsername('admin');
        self::assertSame($id, $u['id']);
        self::assertTrue(password_verify('pw', $u['password_hash']));
        self::assertNull($repo->findByUsername('ADMIN '));
        self::assertSame(['id' => $id, 'username' => 'admin', 'display_name' => 'Demo Admin'], $repo->find($id));
        self::assertNull($repo->find(999));
    }
}
```

`tests/Unit/Http/Middleware/AuthMiddlewareTest.php`:
```php
<?php

declare(strict_types=1);

namespace Tests\Unit\Http\Middleware;

use App\Domain\Exception\UnauthorizedException;
use App\Http\Middleware\AuthMiddleware;
use App\Http\Request;
use App\Http\Response;
use App\Infrastructure\Auth\SessionAuth;
use PHPUnit\Framework\TestCase;

final class AuthMiddlewareTest extends TestCase
{
    private function mw(bool $authenticated): AuthMiddleware
    {
        $auth = $this->createMock(SessionAuth::class);
        $auth->method('isAuthenticated')->willReturn($authenticated);
        return new AuthMiddleware($auth);
    }

    /** @return iterable<string, array{0:string,1:string}> */
    public static function publicRoutes(): iterable
    {
        yield 'login' => ['POST', '/api/auth/login'];
        yield 'health' => ['GET', '/api/health'];
        yield 'spa' => ['GET', '/'];
    }

    /** @dataProvider publicRoutes */
    public function testPublicRoutesPassWhenLoggedOut(string $method, string $path): void
    {
        $r = $this->mw(false)->process(new Request($method, $path), fn () => Response::noContent());
        self::assertSame(204, $r->status);
    }

    /** @return iterable<string, array{0:string,1:string}> */
    public static function protectedRoutes(): iterable
    {
        yield 'products' => ['GET', '/api/products'];
        yield 'me' => ['GET', '/api/auth/me'];
        yield 'sync' => ['POST', '/api/landings/sync'];
        yield 'media' => ['GET', '/media/ffffffff-ffff-4fff-8fff-ffffffffffff.jpg'];
        yield 'logout' => ['POST', '/api/auth/logout'];
    }

    /** @dataProvider protectedRoutes */
    public function testProtectedRoutesRequireSession(string $method, string $path): void
    {
        $this->expectException(UnauthorizedException::class);
        $this->mw(false)->process(new Request($method, $path), fn () => Response::noContent());
    }

    /** @dataProvider protectedRoutes */
    public function testProtectedRoutesPassWhenLoggedIn(string $method, string $path): void
    {
        $r = $this->mw(true)->process(new Request($method, $path), fn () => Response::noContent());
        self::assertSame(204, $r->status);
    }
}
```

`tests/Api/AuthTest.php`:
```php
<?php

declare(strict_types=1);

namespace Tests\Api;

final class AuthTest extends ApiTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->logout();   // this class tests the logged-out state explicitly
    }

    public function testProtectedEndpointsAre401WhenLoggedOut(): void
    {
        foreach (['/api/products', '/api/auth/me', '/api/sync/last'] as $path) {
            $r = $this->request('GET', $path);
            self::assertSame(401, $r['status'], $path);
            self::assertSame('unauthorized', $r['json']['error']['code']);
        }
        self::assertSame(200, $this->request('GET', '/api/health')['status']);
    }

    public function testLoginLogoutFlow(): void
    {
        $bad = $this->request('POST', '/api/auth/login', ['username' => 'admin', 'password' => 'wrong']);
        self::assertSame(401, $bad['status']);
        self::assertSame('invalid_credentials', $bad['json']['error']['code']);

        $blank = $this->request('POST', '/api/auth/login', ['username' => '', 'password' => '']);
        self::assertSame(422, $blank['status']);
        self::assertSame(['username', 'password'], array_keys($blank['json']['error']['fields']));

        $ok = $this->request('POST', '/api/auth/login', ['username' => 'admin', 'password' => 'admin123']);
        self::assertSame(200, $ok['status']);
        self::assertSame('admin', $ok['json']['user']['username']);
        self::assertArrayNotHasKey('password_hash', $ok['json']['user']);
        self::assertStringContainsString('HttpOnly', $ok['headers']['set-cookie'] ?? '');
        self::assertStringContainsString('SameSite=Lax', $ok['headers']['set-cookie'] ?? '');

        $me = $this->request('GET', '/api/auth/me');
        self::assertSame(200, $me['status']);
        self::assertSame('Demo Admin', $me['json']['user']['display_name']);
        self::assertSame(200, $this->request('GET', '/api/products')['status']);

        self::assertSame(204, $this->request('POST', '/api/auth/logout')['status']);
        self::assertSame(401, $this->request('GET', '/api/auth/me')['status']);
    }

    public function testLoginRequiresXhrHeader(): void
    {
        $r = $this->request('POST', '/api/auth/login', ['username' => 'admin', 'password' => 'admin123'], [], xhr: false);
        self::assertSame(403, $r['status']);
    }
}
```

`tests/Api/ApiTestCase.php` — add:
```php
    /** @var array<string,bool> */
    private static array $loggedIn = [];

    protected function setUp(): void
    {
        $this->cookieJar = sys_get_temp_dir() . '/tm-cookies-' . str_replace('\\', '_', static::class) . '.txt';
        if (empty(self::$loggedIn[static::class])) {
            $this->login();
            self::$loggedIn[static::class] = true;
        }
    }

    /** @return array<string,mixed> */
    protected function login(string $username = 'admin', string $password = 'admin123'): array
    {
        $r = $this->request('POST', '/api/auth/login', ['username' => $username, 'password' => $password]);
        if ($r['status'] !== 200) {
            self::fail('login failed: ' . json_encode($r['json']));
        }
        return $r['json']['user'];
    }

    protected function logout(): void
    {
        @unlink($this->cookieJar);
        self::$loggedIn[static::class] = false;
    }
```
(`setUp` replaces the existing one; keep `cookieJarPath()`.) Because `SyncTest`/`ProductsTest`/… inherit `setUp`, they log in automatically.

- [ ] **Step 2: Run — expect failures** (`make unit`, `make integration`, then `make api` after wiring — most API tests will 401 until the login endpoint exists)

- [ ] **Step 3: Implement**

`src/Infrastructure/Repository/UserRepository.php`:
```php
<?php

declare(strict_types=1);

namespace App\Infrastructure\Repository;

final class UserRepository
{
    public function __construct(private readonly \PDO $pdo)
    {
    }

    /** @return array{id:int,username:string,password_hash:string,display_name:string}|null */
    public function findByUsername(string $username): ?array
    {
        $stmt = $this->pdo->prepare('SELECT id, username, password_hash, display_name FROM users WHERE username = ?');
        $stmt->execute([$username]);
        $row = $stmt->fetch();
        return $row === false ? null : ['id' => (int) $row['id'], 'username' => (string) $row['username'], 'password_hash' => (string) $row['password_hash'], 'display_name' => (string) $row['display_name']];
    }

    /** @return array{id:int,username:string,display_name:string}|null */
    public function find(int $id): ?array
    {
        $stmt = $this->pdo->prepare('SELECT id, username, display_name FROM users WHERE id = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        return $row === false ? null : ['id' => (int) $row['id'], 'username' => (string) $row['username'], 'display_name' => (string) $row['display_name']];
    }
}
```

`src/Infrastructure/Auth/SessionAuth.php`:
```php
<?php

declare(strict_types=1);

namespace App\Infrastructure\Auth;

use App\Domain\Auth\CurrentUser;

/**
 * PHP-session-backed identity. Cookie: HttpOnly, SameSite=Lax, Secure on HTTPS.
 */
class SessionAuth implements CurrentUser
{
    private const KEY_ID = 'user_id';
    private const KEY_NAME = 'user_name';

    public function __construct(private readonly string $sessionName)
    {
    }

    public function start(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE || PHP_SAPI === 'cli') {
            return;
        }
        session_name($this->sessionName);
        session_set_cookie_params([
            'lifetime' => 0,
            'path' => '/',
            'httponly' => true,
            'samesite' => 'Lax',
            'secure' => (($_SERVER['HTTPS'] ?? '') !== '' && $_SERVER['HTTPS'] !== 'off') || ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https',
        ]);
        session_start();
    }

    public function login(int $id, string $displayName): void
    {
        $this->start();
        session_regenerate_id(true);
        $_SESSION[self::KEY_ID] = $id;
        $_SESSION[self::KEY_NAME] = $displayName;
    }

    public function logout(): void
    {
        $this->start();
        $_SESSION = [];
        if (session_status() === PHP_SESSION_ACTIVE) {
            $p = session_get_cookie_params();
            setcookie(session_name(), '', ['expires' => time() - 3600, 'path' => $p['path'], 'httponly' => true, 'samesite' => 'Lax', 'secure' => $p['secure']]);
            session_destroy();
        }
    }

    public function isAuthenticated(): bool
    {
        return $this->id() !== null;
    }

    public function id(): ?int
    {
        $this->start();
        return isset($_SESSION[self::KEY_ID]) ? (int) $_SESSION[self::KEY_ID] : null;
    }

    public function displayName(): ?string
    {
        $this->start();
        return isset($_SESSION[self::KEY_NAME]) ? (string) $_SESSION[self::KEY_NAME] : null;
    }
}
```
(Non-final so PHPUnit can mock it in the middleware test.)

`src/Application/AuthService.php`:
```php
<?php

declare(strict_types=1);

namespace App\Application;

use App\Domain\Exception\UnauthorizedException;
use App\Domain\Exception\ValidationException;
use App\Infrastructure\Auth\SessionAuth;
use App\Infrastructure\Repository\UserRepository;

final class AuthService
{
    public function __construct(private readonly UserRepository $users, private readonly SessionAuth $session)
    {
    }

    /** @return array{id:int,username:string,display_name:string} */
    public function login(string $username, string $password): array
    {
        $errors = [];
        if (trim($username) === '') {
            $errors['username'] = 'Username is required';
        }
        if ($password === '') {
            $errors['password'] = 'Password is required';
        }
        if ($errors !== []) {
            throw new ValidationException($errors);
        }
        $user = $this->users->findByUsername(trim($username));
        if ($user === null || !password_verify($password, $user['password_hash'])) {
            throw new UnauthorizedException('Invalid username or password', 'invalid_credentials');
        }
        $this->session->login($user['id'], $user['display_name']);
        return ['id' => $user['id'], 'username' => $user['username'], 'display_name' => $user['display_name']];
    }

    public function logout(): void
    {
        $this->session->logout();
    }

    /** @return array{id:int,username:string,display_name:string}|null */
    public function current(): ?array
    {
        $id = $this->session->id();
        return $id === null ? null : $this->users->find($id);
    }
}
```
`UnauthorizedException` needs an optional error-code parameter — change its constructor to `public function __construct(string $message = 'Authentication required', string $code = 'unauthorized') { parent::__construct(401, $code, $message); }` (existing callers unchanged).

`src/Http/Middleware/AuthMiddleware.php`:
```php
<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Domain\Exception\UnauthorizedException;
use App\Http\MiddlewareInterface;
use App\Http\Request;
use App\Http\Response;
use App\Infrastructure\Auth\SessionAuth;

/**
 * Everything under /api and /media needs a session, except login and the health probe.
 */
final class AuthMiddleware implements MiddlewareInterface
{
    private const PUBLIC = [['POST', '/api/auth/login'], ['GET', '/api/health']];

    public function __construct(private readonly SessionAuth $auth)
    {
    }

    public function process(Request $request, callable $next): Response
    {
        $guarded = $request->isApi() || str_starts_with($request->path, '/media/');
        if ($guarded && !in_array([$request->method, rtrim($request->path, '/')], self::PUBLIC, true) && !$this->auth->isAuthenticated()) {
            throw new UnauthorizedException();
        }
        return $next($request);
    }
}
```

`src/Http/Controller/AuthController.php`:
```php
<?php

declare(strict_types=1);

namespace App\Http\Controller;

use App\Application\AuthService;
use App\Domain\Exception\UnauthorizedException;
use App\Http\Request;
use App\Http\Response;

final class AuthController
{
    public function __construct(private readonly AuthService $auth)
    {
    }

    public function login(Request $request): Response
    {
        $user = $this->auth->login((string) $request->input('username', ''), (string) $request->input('password', ''));
        return Response::json(['user' => $user]);
    }

    public function logout(Request $request): Response
    {
        $this->auth->logout();
        return Response::noContent();
    }

    public function me(Request $request): Response
    {
        $user = $this->auth->current();
        if ($user === null) {
            throw new UnauthorizedException();
        }
        return Response::json(['user' => $user]);
    }
}
```

Container: replace `$c->set(CurrentUser::class, fn () => new AnonymousUser());` with
```php
    $c->set(SessionAuth::class, fn () => new SessionAuth((string) $config['session']['name']));
    $c->set(CurrentUser::class, fn (Container $c) => $c->get(SessionAuth::class));
    $c->set(UserRepository::class, fn (Container $c) => new UserRepository($c->get(PDO::class)));
    $c->set(AuthService::class, fn (Container $c) => new AuthService($c->get(UserRepository::class), $c->get(SessionAuth::class)));
    $c->set(AuthController::class, fn (Container $c) => new AuthController($c->get(AuthService::class)));
```
and the Kernel middleware list becomes `[new RequireXhrMiddleware(), new AuthMiddleware($c->get(SessionAuth::class))]`. Delete `AnonymousUser` if nothing else uses it (keep the `CurrentUser` interface). Routes:
```php
    $r->post('/api/auth/login', [AuthController::class, 'login']);
    $r->post('/api/auth/logout', [AuthController::class, 'logout']);
    $r->get('/api/auth/me', [AuthController::class, 'me']);
```
README: under Quick start add "Log in with **admin / admin123** (seeded demo user)."; under Deliberate shortcuts replace the "sync endpoints unauthenticated" bullet with "single seeded user, no registration/roles/password reset (per brief); PHP file sessions".

- [ ] **Step 4: Green + commit**

```bash
make unit && make integration && make lint && make stan && make api
git add -A && git commit -m "feat(auth): session login with password_hash, auth middleware guarding /api and /media"
```

### Task 12: Login view, session-aware navbar; merge phase 7

**Files:**
- Create: `public/assets/js/views/login.js`
- Modify: `public/assets/js/app.js` (boot sequence), `public/index.html`, `public/assets/css/app.css`

**Interfaces:**
- `Views.login(params, query)` renders `#/login?next=<hash>`: centred card with username/password, Sign in button, inline error for 401/422, spinner while pending; on success `App.setUser(user)` and navigate to `next` (default `#/products`).
- `App.setUser(user|null)` re-renders `#nav-right`: when logged in → `Sync.mount()` + user chip + Sign out button (POST logout → `App.setUser(null)` → `#/login`); when null → empty.
- Boot: `app.js` calls `GET /api/auth/me`; 200 → `App.setUser(user); Router.start()`; 401 → `App.setUser(null); location.hash = '#/login?next=' + encodeURIComponent(location.hash || '#/products'); Router.start()`. `App.onUnauthorized` navigates to `#/login?next=…` and toasts "Session expired — please sign in again" (only if not already on login).

- [ ] **Step 1: Files**

`public/assets/js/views/login.js`:
```js
(function () {
  'use strict';
  window.Views = window.Views || {};
  window.Views.login = function (params, query) {
    const next = query.next || '#/products';
    App.el.innerHTML = `
      <div class="row justify-content-center"><div class="col-12 col-sm-8 col-md-5 col-lg-4">
        <div class="card mt-5"><div class="card-body p-4">
          <h1 class="h4 mb-3">Sign in</h1>
          <form id="login-form" novalidate>
            <div class="alert alert-danger d-none" id="login-error" role="alert"></div>
            <div class="mb-3"><label class="form-label" for="login-username">Username</label><input class="form-control" id="login-username" name="username" autocomplete="username" autofocus required><div class="invalid-feedback"></div></div>
            <div class="mb-3"><label class="form-label" for="login-password">Password</label><input class="form-control" id="login-password" name="password" type="password" autocomplete="current-password" required><div class="invalid-feedback"></div></div>
            <button class="btn btn-primary w-100" type="submit" id="login-submit">Sign in</button>
          </form>
          <p class="text-muted small mt-3 mb-0">Demo credentials: <code>admin</code> / <code>admin123</code></p>
        </div></div>
      </div></div>`;
    const form = document.getElementById('login-form');
    form.addEventListener('submit', async (e) => {
      e.preventDefault();
      const btn = document.getElementById('login-submit');
      const errBox = document.getElementById('login-error');
      errBox.classList.add('d-none');
      form.querySelectorAll('.is-invalid').forEach((i) => i.classList.remove('is-invalid'));
      btn.disabled = true;
      btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>Signing in…';
      try {
        const res = await Api.post('/api/auth/login', { username: form.username.value, password: form.password.value });
        App.setUser(res.user);
        Router.navigate(next);
      } catch (err) {
        if (err.status === 422) {
          for (const [name, msg] of Object.entries(err.fields)) {
            const input = form[name];
            if (!input) continue;
            input.classList.add('is-invalid');
            input.parentElement.querySelector('.invalid-feedback').textContent = msg;
          }
        } else {
          errBox.textContent = err.status === 401 ? 'Invalid username or password.' : 'Could not sign in: ' + err.message;
          errBox.classList.remove('d-none');
        }
      } finally {
        btn.disabled = false;
        btn.textContent = 'Sign in';
      }
    });
  };
})();
```

`app.js` — full replacement:
```js
(function () {
  'use strict';
  window.App = {
    el: document.getElementById('app'),
    user: null,
    setNav(html) { document.getElementById('nav-right').innerHTML = html; },
    setUser(user) {
      App.user = user;
      if (!user) { App.setNav(''); return; }
      Sync.mount();
      document.getElementById('nav-right').insertAdjacentHTML('beforeend',
        `<span class="text-white-50 small ms-2"><i class="bi bi-person-circle me-1"></i>${esc(user.display_name)}</span>
         <button id="logout-btn" class="btn btn-sm btn-outline-light ms-1">Sign out</button>`);
      document.getElementById('logout-btn').addEventListener('click', async () => {
        try { await Api.post('/api/auth/logout'); } catch (e) { /* session is gone either way */ }
        App.setUser(null);
        Router.navigate('#/login');
      });
    },
    onUnauthorized() {
      if ((location.hash || '').startsWith('#/login')) return;
      const next = location.hash || '#/products';
      App.setUser(null);
      Toast.error('Please sign in to continue');
      Router.navigate('#/login?next=' + encodeURIComponent(next));
    },
  };

  Router.register('#/login', (params, query) => Views.login(params, query));
  Router.register('#/products', (params, query) => Views.products(params, query));
  Router.register('#/products/{sku}', (params) => Views.countries(params));
  Router.register('#/landings/{id}', (params) => Views.testimonials(params));

  (async function boot() {
    try {
      const me = await Api.get('/api/auth/me');
      App.setUser(me.user);
      if ((location.hash || '').startsWith('#/login')) location.hash = '#/products';
    } catch (e) {
      App.setUser(null);
      if (!(location.hash || '').startsWith('#/login')) {
        location.hash = '#/login?next=' + encodeURIComponent(location.hash || '#/products');
      }
    }
    Router.start();
  })();
})();
```
Note: `Api` calls `App.onUnauthorized` on any 401 — the boot `me` call is expected to 401; guard against a toast on boot by checking `App.user === null && !location.hash.startsWith('#/login')` **before** the boot call sets things — simplest: in `api.js` change the hook call to `if (res.status === 401 && window.App && window.App.onUnauthorized && path !== '/api/auth/me') …`. Make that one-line change in `api.js`.

`index.html`: add `views/login.js` before `app.js`. `app.css`: `#nav-right .btn-outline-light { --bs-btn-color: #fff; }`.

- [ ] **Step 2: Verify, commit, merge phase 7**

`make up && make api`; browser: fresh incognito → redirected to Sign in → wrong password shows inline error → correct → lands on Products with user chip + Sync button → Sign out → back to login. `node --check` on new JS.
```bash
git add -A && git commit -m "feat(ui): login view, session-aware navbar and 401 redirect"
```
Time-log row `| 7 | Login (session auth, middleware, UI) | 1.5h |`, commit, push, PR `Phase 7: login`, merge, `main`.

---

## Phase 8 — Playwright end-to-end

### Task 13: Playwright happy path + error visibility, in Docker and CI; merge phase 8

**Files:**
- Create: `tests/e2e/package.json`, `tests/e2e/package-lock.json` (generated), `tests/e2e/playwright.config.ts`, `tests/e2e/tests/happy-path.spec.ts`, `tests/e2e/tests/errors.spec.ts`, `tests/e2e/.gitignore`
- Modify: `Makefile` (`e2e` target), `.github/workflows/ci.yml` (new `e2e` job), `README.md`

**Interfaces:** `BASE_URL` env (default `http://localhost:8080`); specs assume the seeded DB and the demo user; each spec creates what it needs and cleans up (deletes the testimonial it created) so runs are repeatable.

- [ ] **Step 1: Branch and files**

```bash
git checkout -b phase/08-e2e
```

`tests/e2e/package.json`:
```json
{
  "name": "testimonials-manager-e2e",
  "private": true,
  "scripts": { "test": "playwright test", "report": "playwright show-report" },
  "devDependencies": { "@playwright/test": "1.47.2", "@types/node": "^20.14.0", "typescript": "^5.5.0" }
}
```
`tests/e2e/.gitignore`: `node_modules/`, `playwright-report/`, `test-results/`.

`tests/e2e/playwright.config.ts`:
```ts
import { defineConfig, devices } from '@playwright/test';

export default defineConfig({
  testDir: './tests',
  timeout: 30_000,
  expect: { timeout: 5_000 },
  fullyParallel: false,
  workers: 1,
  retries: process.env.CI ? 1 : 0,
  reporter: [['list'], ['html', { open: 'never' }]],
  use: {
    baseURL: process.env.BASE_URL || 'http://localhost:8080',
    trace: 'retain-on-failure',
    screenshot: 'only-on-failure',
  },
  projects: [{ name: 'chromium', use: { ...devices['Desktop Chrome'] } }],
});
```

`tests/e2e/tests/happy-path.spec.ts`:
```ts
import { test, expect, Page } from '@playwright/test';

const PNG_1x1 = Buffer.from('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==', 'base64');

async function login(page: Page) {
  await page.goto('/');
  await expect(page.getByRole('heading', { name: 'Sign in' })).toBeVisible();
  await page.getByLabel('Username').fill('admin');
  await page.getByLabel('Password').fill('admin123');
  await page.getByRole('button', { name: 'Sign in' }).click();
  await expect(page.getByRole('heading', { name: 'Products' })).toBeVisible();
}

test('editor can find a product, pick a country and manage a testimonial with an image', async ({ page }) => {
  await login(page);

  // 1. search
  await page.getByLabel('Search products').fill('abforge');
  await page.getByRole('button', { name: 'Search' }).click();
  const row = page.locator('tr.tm-row-link', { hasText: 'abforge' });
  await expect(row).toHaveCount(1);
  await row.click();

  // 2. country overview → a localised landing that inherits from EN
  await expect(page.locator('.tm-country-card')).not.toHaveCount(0);
  const inheriting = page.locator('.tm-country-card', { hasText: 'inherits EN' }).first();
  await expect(inheriting).toBeVisible();
  const country = (await inheriting.locator('.tm-country-code').textContent())?.trim();
  await inheriting.click();
  await expect(page.getByText('Inherited from the English master')).toBeVisible();

  // 3. create
  await page.getByRole('button', { name: 'Add testimonial' }).click();
  const modal = page.locator('#testimonial-modal');
  await modal.getByLabel('Author name').fill('E2E Author');
  await modal.getByLabel('Text').fill('Written by Playwright.');
  await modal.getByLabel('Rating').selectOption('5');
  await modal.getByRole('button', { name: 'Save' }).click();
  await expect(page.getByText('Testimonial created')).toBeVisible();
  await expect(page.getByText('Inherited from the English master')).toHaveCount(0);
  const created = page.locator('#testimonials-table tr', { hasText: 'E2E Author' });
  await expect(created).toHaveCount(1);
  await expect(created).toContainText('★ 5.0');

  // 4. edit + upload an image
  await created.getByRole('button', { name: 'Edit' }).click();
  await modal.getByLabel('Author name').fill('E2E Author Edited');
  await modal.locator('#iu-input').setInputFiles({ name: 'dot.png', mimeType: 'image/png', buffer: PNG_1x1 });
  await expect(modal.locator('.tm-img-card')).toHaveCount(1);
  await expect(page.getByText('1 image uploaded')).toBeVisible();
  await modal.getByRole('button', { name: 'Save' }).click();
  await expect(page.getByText('Testimonial saved')).toBeVisible();
  const edited = page.locator('#testimonials-table tr', { hasText: 'E2E Author Edited' });
  await expect(edited.locator('img.tm-thumb')).toHaveCount(1);

  // 5. inline active toggle shows explicit save state
  await edited.locator('input.tm-active').click();
  await expect(edited.locator('.tm-save-status')).toContainText('Saved');

  // 6. delete with confirmation
  await edited.locator('.tm-delete').click();
  await expect(page.locator('#confirm-modal')).toBeVisible();
  await page.locator('#confirm-ok').click();
  await expect(page.getByText('Testimonial deleted')).toBeVisible();
  await expect(page.locator('#testimonials-table tr', { hasText: 'E2E Author' })).toHaveCount(0);
  expect(country).toBeTruthy();
});
```

`tests/e2e/tests/errors.spec.ts`:
```ts
import { test, expect } from '@playwright/test';

test.beforeEach(async ({ page }) => {
  await page.goto('/');
  await page.getByLabel('Username').fill('admin');
  await page.getByLabel('Password').fill('admin123');
  await page.getByRole('button', { name: 'Sign in' }).click();
  await expect(page.getByRole('heading', { name: 'Products' })).toBeVisible();
});

test('validation errors are shown on the fields, never swallowed', async ({ page }) => {
  await page.goto('/#/landings/61763');
  await page.getByRole('button', { name: 'Add testimonial' }).click();
  const modal = page.locator('#testimonial-modal');
  await modal.getByLabel('Link (URL)').fill('not a url');
  await modal.getByRole('button', { name: 'Save' }).click();
  await expect(modal.locator('#tf-author')).toHaveClass(/is-invalid/);
  await expect(modal.locator('#tf-text')).toHaveClass(/is-invalid/);
  await expect(modal.locator('#tf-url')).toHaveClass(/is-invalid/);
  await expect(modal.locator('#tf-status')).toContainText('Failed');
  await expect(modal).toBeVisible();
});

test('a server error while saving is visible and the row is not silently changed', async ({ page }) => {
  await page.goto('/#/landings/61763');
  const first = page.locator('#testimonials-table tr[data-id]').first();
  const toggle = first.locator('input.tm-active');
  const before = await toggle.isChecked();
  await page.route('**/api/testimonials/*', (route) =>
    route.request().method() === 'PATCH'
      ? route.fulfill({ status: 500, contentType: 'application/json', body: JSON.stringify({ error: { code: 'internal_error', message: 'Simulated outage' } }) })
      : route.continue());
  await toggle.click();
  await expect(first.locator('.tm-save-status')).toContainText('Failed');
  await expect(page.locator('.toast', { hasText: 'Simulated outage' })).toBeVisible();
  expect(await toggle.isChecked()).toBe(before);
});

test('unauthenticated visitors are sent to the login page', async ({ page, context }) => {
  await context.clearCookies();
  await page.goto('/#/products');
  await expect(page.getByRole('heading', { name: 'Sign in' })).toBeVisible();
});
```

- [ ] **Step 2: Makefile + CI**

Makefile `e2e:` becomes:
```make
e2e:           ## Playwright happy-path against the running app (needs `make up && make seed`)
	$(COMPOSE) --profile e2e run --rm -e CI=1 playwright sh -c "npm ci && npx playwright test"
```
(The playwright service already mounts `./tests/e2e` at `/e2e` with `BASE_URL=http://app`.)

`.github/workflows/ci.yml` — add a second job:
```yaml
  e2e:
    runs-on: ubuntu-latest
    needs: test
    services:
      mysql:
        image: mysql:8.0
        env: { MYSQL_ROOT_PASSWORD: root, MYSQL_DATABASE: testimonials, MYSQL_USER: app, MYSQL_PASSWORD: app }
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
      APP_ENV: test
      APP_DEBUG: "1"
      UPLOAD_DIR: storage/uploads
      UPLOAD_MAX_BYTES: "5242880"
      LANDINGS_API_URL: https://example.invalid/landings
      LANDINGS_API_KEY: test-key
      LANDINGS_API_FIXTURE: tests/fixtures/landings.json
      SESSION_NAME: tm_session
      BASE_URL: http://127.0.0.1:8080
    steps:
      - uses: actions/checkout@v4
      - uses: shivammathur/setup-php@v2
        with: { php-version: "8.2", extensions: "pdo_mysql, gd, curl, fileinfo, mbstring", coverage: none }
      - run: composer install --no-interaction --no-progress
      - name: Seed and start app
        run: |
          mkdir -p storage/uploads
          mysql -h127.0.0.1 -uroot -proot testimonials < database/schema.sql
          mysql -h127.0.0.1 -uroot -proot testimonials < database/seed.sql
          php database/seed-images.php
          (php -S 127.0.0.1:8080 -t public public/index.php > /tmp/php-server.log 2>&1 &)
          sleep 2
      - uses: actions/setup-node@v4
        with: { node-version: "20", cache: npm, cache-dependency-path: tests/e2e/package-lock.json }
      - run: npm ci
        working-directory: tests/e2e
      - run: npx playwright install --with-deps chromium
        working-directory: tests/e2e
      - run: npx playwright test
        working-directory: tests/e2e
      - uses: actions/upload-artifact@v4
        if: always()
        with: { name: playwright-report, path: tests/e2e/playwright-report, retention-days: 7 }
      - name: Server log on failure
        if: failure()
        run: cat /tmp/php-server.log
```
Generate the lock file inside the Playwright container (host Node is 16): `docker compose --profile e2e run --rm playwright sh -c "npm install --package-lock-only"` then commit `tests/e2e/package-lock.json`.

- [ ] **Step 3: Run locally, then CI**

```bash
make up && make seed && make e2e
```
Expected: 4 passed. If a selector fails, fix the *test* only when the UI genuinely renders differently from the spec text; otherwise fix the UI (the spec's copy — "Testimonial created", "Inherited from the English master", "1 image uploaded", "Saved" — is the contract from Tasks 7/10). README Development table row `make e2e` → "Playwright happy path + error visibility (Chromium in Docker)".

```bash
git add -A && git commit -m "test(e2e): Playwright happy path and error-visibility specs, CI job"
```
Time-log row `| 8 | Playwright e2e + CI job | 1.5h |`, commit, push, PR `Phase 8: e2e`, wait for both CI jobs, merge, `main`.

---

## Phase 9 — Deploy to Fly.io

### Task 14: Fly.io app + MySQL app, install command, deploy workflow, live link; merge phase 9

**Files:**
- Create: `fly.toml`, `deploy/mysql/fly.toml`, `deploy/README.md`, `bin/install.php`, `.github/workflows/deploy.yml`
- Modify: `Dockerfile` (uploads dir permissions, run seed-images at release), `README.md` (live link, demo creds, deploy notes), `docs/time-log.md`
- Test: `tests/Integration/InstallTest.php` (install is idempotent); manual: `curl https://<app>.fly.dev/api/health`

**Interfaces:**
- `bin/install.php` — CLI: if table `users` is missing, applies `database/schema.sql` then `database/seed.sql`; always runs the seed-images generator; prints what it did; exit 0. Used as Fly's `release_command` and usable on any LAMP box.
- App name `tm-dfvu` (org "personal", region `fra`); MySQL app `tm-dfvu-db` (private, `.internal` DNS only). If a name is taken, append `-<3 random letters>` to both and use the same suffix everywhere (fly.toml, secrets, README).
- Fly secrets on the app: `DB_HOST=tm-dfvu-db.internal DB_PORT=3306 DB_NAME=testimonials DB_USER=app DB_PASS=<generated> APP_ENV=prod APP_DEBUG=0 SESSION_NAME=tm_session LANDINGS_API_URL=https://develop.s-mania.com/it/testimonials/landings-api.php LANDINGS_API_KEY=<owner sets> UPLOAD_DIR=/var/www/storage/uploads`.
- Owner-only steps are listed explicitly; the implementer runs everything else with the local `fly` CLI (already authenticated on this machine) and reports each command's output.

- [ ] **Step 1: Branch, install script + test**

```bash
git checkout -b phase/09-deploy
```

`tests/Integration/InstallTest.php`:
```php
<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Support\Env;

final class InstallTest extends DatabaseTestCase
{
    public function testInstallScriptIsIdempotentAndSeeds(): void
    {
        self::$pdo->exec('SET FOREIGN_KEY_CHECKS=0');
        foreach (['testimonial_images', 'testimonials', 'change_log', 'sync_runs', 'landings', 'products', 'users'] as $t) {
            self::$pdo->exec("DROP TABLE IF EXISTS `$t`");
        }
        self::$pdo->exec('SET FOREIGN_KEY_CHECKS=1');
        $uploads = sys_get_temp_dir() . '/tm-install-' . bin2hex(random_bytes(3));
        $env = sprintf('DB_NAME=%s UPLOAD_DIR=%s', escapeshellarg((string) Env::get('TEST_DB_NAME', 'testimonials_test')), escapeshellarg($uploads));
        $first = shell_exec("$env php " . dirname(__DIR__, 2) . '/bin/install.php 2>&1');
        self::assertStringContainsString('schema applied', (string) $first);
        self::assertStringContainsString('seed applied', (string) $first);
        self::assertSame(170, (int) self::$pdo->query('SELECT COUNT(*) FROM landings')->fetchColumn());
        self::assertGreaterThan(0, count(glob("$uploads/*.jpg") ?: []));
        $second = shell_exec("$env php " . dirname(__DIR__, 2) . '/bin/install.php 2>&1');
        self::assertStringContainsString('schema present', (string) $second);
        array_map('unlink', glob("$uploads/*") ?: []);
        @rmdir($uploads);
        self::loadSql(dirname(__DIR__, 2) . '/database/schema.sql');
    }
}
```

`bin/install.php`:
```php
#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * First-run installer: creates the schema and demo data if the database is empty, then makes sure
 * the seeded demo photos exist. Idempotent — used as the Fly.io release command and for LAMP installs.
 */

require dirname(__DIR__) . '/vendor/autoload.php';

use App\Infrastructure\Db\PdoFactory;

$config = require dirname(__DIR__) . '/config/config.php';
$pdo = PdoFactory::create($config['db']);

$hasUsers = $pdo->query("SHOW TABLES LIKE 'users'")->fetch() !== false;
if ($hasUsers) {
    echo "install: schema present, skipping schema/seed\n";
} else {
    foreach (['schema', 'seed'] as $file) {
        $sql = (string) file_get_contents(dirname(__DIR__) . "/database/$file.sql");
        foreach (preg_split('/;\s*\n/', $sql) ?: [] as $statement) {
            $statement = trim($statement);
            if ($statement === '' || preg_match('/^(--.*\n?)+$/', $statement)) {
                continue;
            }
            $pdo->exec($statement);
        }
        echo "install: $file applied\n";
    }
}
passthru(PHP_BINARY . ' ' . escapeshellarg(dirname(__DIR__) . '/database/seed-images.php'), $code);
exit($code);
```
`chmod +x bin/install.php`. Run `make integration` → green (the test drops and recreates the test schema; other tests are unaffected because `setUpBeforeClass` reloads `schema.sql` per class and the test restores it at the end).

- [ ] **Step 2: Fly configuration files**

`fly.toml`:
```toml
app = "tm-dfvu"
primary_region = "fra"

[build]
  dockerfile = "Dockerfile"

[deploy]
  release_command = "php bin/install.php"

[env]
  APP_ENV = "prod"
  APP_DEBUG = "0"
  UPLOAD_DIR = "/var/www/storage/uploads"
  SESSION_NAME = "tm_session"
  DB_PORT = "3306"
  DB_NAME = "testimonials"
  DB_USER = "app"
  LANDINGS_API_URL = "https://develop.s-mania.com/it/testimonials/landings-api.php"

[http_service]
  internal_port = 80
  force_https = true
  auto_stop_machines = "stop"
  auto_start_machines = true
  min_machines_running = 0

[[http_service.checks]]
  interval = "30s"
  timeout = "5s"
  grace_period = "20s"
  method = "GET"
  path = "/api/health"

[[mounts]]
  source = "uploads"
  destination = "/var/www/storage/uploads"

[[vm]]
  size = "shared-cpu-1x"
  memory = "512mb"
```

`deploy/mysql/fly.toml`:
```toml
app = "tm-dfvu-db"
primary_region = "fra"

[build]
  image = "mysql:8.0"

[env]
  MYSQL_DATABASE = "testimonials"
  MYSQL_USER = "app"

[processes]
  app = "--datadir /data/mysql --character-set-server=utf8mb4 --collation-server=utf8mb4_unicode_ci --default-authentication-plugin=mysql_native_password --innodb-buffer-pool-size=128M"

[[mounts]]
  source = "mysqldata"
  destination = "/data"

[[vm]]
  size = "shared-cpu-1x"
  memory = "512mb"
```
(No `[http_service]` → not exposed publicly; reachable from the app over the private 6PN network as `tm-dfvu-db.internal`.)

`deploy/README.md`:
```markdown
# Deploying to Fly.io

Two Fly apps: `tm-dfvu` (this repo's Dockerfile, Apache+PHP) and `tm-dfvu-db` (stock `mysql:8.0` on a volume, private network only).

## One-time setup (already done for the demo)

```bash
# database
cd deploy/mysql
fly apps create tm-dfvu-db
fly volumes create mysqldata --size 1 --region fra -a tm-dfvu-db --yes
fly secrets set MYSQL_ROOT_PASSWORD=<random> MYSQL_PASSWORD=<random> -a tm-dfvu-db
fly deploy -a tm-dfvu-db --ha=false
cd ../..

# app
fly apps create tm-dfvu
fly volumes create uploads --size 1 --region fra -a tm-dfvu --yes
fly secrets set DB_HOST=tm-dfvu-db.internal DB_PASS=<same MYSQL_PASSWORD> LANDINGS_API_KEY=<key> -a tm-dfvu
fly deploy -a tm-dfvu --ha=false
```

The release command (`php bin/install.php`) creates the schema and demo data on first deploy and is a no-op afterwards.

## Continuous deployment

`.github/workflows/deploy.yml` runs `flyctl deploy --remote-only` on every push to `main`, using the `FLY_API_TOKEN` repository secret.

## Operations

- Logs: `fly logs -a tm-dfvu`
- Shell: `fly ssh console -a tm-dfvu`
- Re-seed: `fly ssh console -a tm-dfvu -C "php bin/install.php"`
- Rotate the upstream key: `fly secrets set LANDINGS_API_KEY=… -a tm-dfvu`
```

`.github/workflows/deploy.yml`:
```yaml
name: Deploy
on:
  push:
    branches: [main]
  workflow_dispatch:

concurrency:
  group: deploy
  cancel-in-progress: false

jobs:
  deploy:
    runs-on: ubuntu-latest
    steps:
      - uses: actions/checkout@v4
      - uses: superfly/flyctl-actions/setup-flyctl@master
      - run: flyctl deploy --remote-only --ha=false
        env:
          FLY_API_TOKEN: ${{ secrets.FLY_API_TOKEN }}
```

Dockerfile — after `COPY . .` make sure uploads and sessions are writable and the mount point exists:
```dockerfile
RUN composer dump-autoload --optimize \
 && mkdir -p storage/uploads /var/lib/php/sessions \
 && chown -R www-data:www-data storage /var/lib/php/sessions
```
(and keep `EXPOSE 80`). Apache in the image already serves `/var/www/public`.

- [ ] **Step 3: Provision and deploy (implementer runs these; report every output)**

```bash
fly auth whoami
cd deploy/mysql && fly apps create tm-dfvu-db || true
DBPASS=$(openssl rand -hex 16); ROOTPASS=$(openssl rand -hex 16)
fly volumes create mysqldata --size 1 --region fra -a tm-dfvu-db --yes
fly secrets set MYSQL_ROOT_PASSWORD=$ROOTPASS MYSQL_PASSWORD=$DBPASS -a tm-dfvu-db
fly deploy -a tm-dfvu-db --ha=false
cd ../..
fly apps create tm-dfvu || true
fly volumes create uploads --size 1 --region fra -a tm-dfvu --yes
fly secrets set DB_HOST=tm-dfvu-db.internal DB_PASS=$DBPASS LANDINGS_API_KEY=replace-me -a tm-dfvu
fly deploy -a tm-dfvu --ha=false
curl -s https://tm-dfvu.fly.dev/api/health
```
Expected: `{"status":"ok","db":true}`. Then in a browser: login works, products list shows the seeded data, a seeded testimonial's photo renders (volume + release command worked). If `fly apps create` says the name is taken, pick `tm-dfvu-<xyz>` and update both `fly.toml` files, `deploy/README.md`, the secrets and the README link consistently. If the release command fails because MySQL isn't up yet, wait for `fly status -a tm-dfvu-db` to show `started` and re-run `fly deploy -a tm-dfvu`.

**Owner-only (put in the report, do not attempt):** `fly secrets set LANDINGS_API_KEY=<real key> -a tm-dfvu` so the live "Sync landings" button works against DFVU's API.

- [ ] **Step 4: README + wrap-up**

README top: replace the "Status" quote with:
```markdown
> **Live demo:** https://tm-dfvu.fly.dev — sign in with `admin` / `admin123`. (Free-tier machine: the first request after idle takes a few seconds.)
```
Add a "Deployment" section linking `deploy/README.md`. Time-log row `| 9 | Fly.io deploy (MySQL app, volumes, release command, CD workflow) | 2h |`.
```bash
git add -A && git commit -m "feat(deploy): Fly.io apps, idempotent installer, deploy workflow and live demo link"
```
Push, PR `Phase 9: deploy`, wait for CI, merge → the Deploy workflow runs on `main`; confirm it succeeds with `gh run watch` and re-check `/api/health`.

---

## Self-review notes

- **Spec coverage:** §2.1 search/paging/sort/counts → T1–T3; §2.2 country overview with counters, countries from data → T2–T3; §2.3 fields, add/edit/delete with confirm, rating fixed/random, gender, unambiguous save, validation both sides → T4–T7; §2.4 multiple ordered images, JPG/PNG/WebP, size limit server-side, UUID names, thumbnails, files outside DB → T8–T10 (ordering = insertion order now; drag & drop reorder is plan 3); §2.5 login with `password_hash`, session, protected pages + API → T11–T12; §9 e2e → T13; §10 hosting → T14. Audit `created_by/updated_by` populated once T11 swaps `CurrentUser`. Deferred to plan 3: reorder endpoints, copy, bulk, change log, AI, image processing (spec §7 bonus list).
- **Placeholder scan:** none — every step has code; owner-only actions are called out explicitly.
- **Type consistency:** `TestimonialController::id()` reused by `ImageController`; `TestimonialService::present($row, $images = [])` signature introduced in T5 and extended in T9 (T5's tests call `create/get/list`, not `present`, so the change is additive); `ImgRow`/`Row` phpstan types referenced by name; `ApiTestCase::cookieJarPath()` added in T9 and used by `ImagesTest::raw()`; `UnauthorizedException` gains an optional code argument in T11 (default unchanged).
- **Known follow-ups for plan 3:** `sort_order` renumbering on reorder; `ImageRepository::reorder`; `change_log` writes from the services; `CurrentUser::displayName()` for the log.
