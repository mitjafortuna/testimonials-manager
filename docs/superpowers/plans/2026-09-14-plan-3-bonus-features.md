# Testimonials Manager — Plan 3: Bonus features (Phases 10–16)

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Deliver the assignment's bonus points on top of the plan-1+plan-2 foundation (already live at https://tm-dfvu.fly.dev): AI mock providers for translation/name suggestion, drag-and-drop reordering, copy-between-countries, bulk actions, a per-record change log, image processing (downscale/WebP/crop), and a release ZIP workflow. Phases 10–15 are cut from the bottom (15 first, then 14, …) if the 2026-09-20 deadline gets tight; phase 16 (release) always ships.

**Architecture:** Same layering as plans 1–2 — thin controllers in `src/Http/Controller`, services in `src/Application` own validation/orchestration, all SQL in `src/Infrastructure/Repository`, pure logic in `src/Domain`. Every new backend feature is additive to the existing `TestimonialService`/`ImageService`/`TestimonialRepository`/`ImageRepository` rather than parallel classes, except AI (a new bounded `Domain\Ai` + `AiService`) and change log (a new `ChangeLogRepository`). The SPA gains new components (`copyTestimonials.js`, `historyPanel.js`) and extends existing ones (`testimonialForm.js`, `imageUploader.js`, `testimonials.js`) — no new views.

**Tech Stack:** Same as plan 2 — PHP 8.1+ (no framework, zero runtime Composer deps), MySQL 8 via PDO, GD, Bootstrap 5.3 + jQuery-free vanilla JS, PHPUnit 10, Docker, GitHub Actions, Fly.io. No new dependencies.

**Spec:** `docs/superpowers/specs/2026-09-13-testimonials-manager-design.md` — §8 (AI mock providers) and §12 (phases 10–16) are this plan's primary source; §4's inheritance note ("the 'copy from EN' bonus materialises the inherited set into real rows") motivates phase 12.

## Global Constraints

- PHP `>=8.1` syntax (no `readonly class`, no DNF types); `composer.json` `require` stays php + extensions only. No new Composer packages.
- PDO prepared statements for every query that carries a value; identifiers from whitelists only (`IN (?,?,…)` placeholder counts generated from `count()`, never from values).
- REST + JSON; real status codes; error body `{"error":{"code","message","fields"}}` (`fields` only on 422). Every new mutating endpoint reuses `Response::error`/`HttpException` subclasses exactly as existing endpoints do — no new error shapes.
- Every mutating `/api` request already gets `X-Requested-With` + session enforcement from the existing `RequireXhrMiddleware`/`AuthMiddleware` pipeline — new routes need **no** per-route auth code, they're covered automatically by matching `/api/*`.
- `database/schema.sql`'s `change_log` table **already exists** on the live production database (it was in schema.sql when phase 9's `bin/install.php` ran on `tm-dfvu-db`, just unused until now) — phase 14 writes to it, no migration needed. Do not add new tables or columns anywhere in this plan; every phase is additive to existing tables only.
- Route ids: reuse `TestimonialController::id(Request, string): int` (positive-integer-or-404) for every new `{id}` route parameter — do not reinvent it.
- UI: English, Bootstrap 5, DFVU palette tokens already in `public/assets/css/app.css` (`--tm-primary`, `.tm-save-status`, etc. — reuse existing classes, only add new ones for genuinely new widgets). Every failed request → visible toast (`Toast.error`); destructive bulk/copy-replace actions confirm first (`Confirm.ask`).
- Every new frontend file follows the existing IIFE + `window.X = {...}` module pattern, loaded via a plain `<script>` tag in `public/index.html` with a path relative to `assets/js/...` (no bundler; must keep working on XAMPP sub-folder installs where `Api` resolves against `document.baseURI`).
- Must still run on XAMPP and in Docker identically — no behavior gated on `APP_ENV`.
- All commands run in Docker: `make unit`, `make integration`, `make api`, `make lint`/`make lint-fix`, `make stan`, `make up`, `make seed`. phpstan level 6 (array shapes documented via `@phpstan-type`/`@phpstan-import-type` following the existing `Row`/`ImgRow` convention), PSR-12.
- **Phase 15 must not change default upload behavior.** `ImageStorage::store()`'s new `$crop`/`$convertWebp` parameters default to `null`/`false`; every currently-passing test in `tests/Unit/Infrastructure/Storage/ImageStorageTest.php` and `tests/Api/ImagesTest.php` must keep passing unmodified (verified in this plan's design: they call `store()` with 2 args, so defaults apply, and 1200×600/900×900/100×80 fixtures never exceed the new 1600px cap, so the always-on downscale of the main image is a no-op for them). Only downscaling above 1600px is unconditional; format conversion and cropping are opt-in per upload.
- Every commit ends with:
  ```
  Co-Authored-By: Claude Opus 5 <noreply@anthropic.com>
  Claude-Session: https://claude.ai/code/session_014Ux1NSdiKLKNGQHc4VFjuf
  ```
- One branch per phase (`phase/NN-name`), PR titled `Phase N: …`, body ending with the two attribution lines for PRs, merged with `gh pr merge --merge --delete-branch` after CI is green; each phase appends a row to `docs/time-log.md`.

## Existing interfaces this plan builds on (from plans 1–2, current `main`)

- `App\Http\Request` — `public readonly string $method, $path; array $query, $body, $headers, $files, $cookies, $attributes`; `query(string $k, mixed $d=null)`, `input(string $k, mixed $d=null)`, `attribute(string $k): string`, `header(string)`, `isXhr()`, `isApi()`, `withAttributes(array)`.
- `App\Http\Response` — `json(mixed, int=200)`, `noContent()`, `error(int,string,string,array=[])`, `withHeader()`.
- `App\Http\Router::{get,post,patch,delete}(string $pattern, array{0:string,1:string} $handler)`; `{name}` params match `[^/]+`.
- `App\Domain\Exception\{HttpException(status,code,message,fields), NotFoundException, ValidationException(array $fields), ConflictException}`.
- `App\Container::set(string $id, callable(Container):object)`, `get(string $id)`.
- `App\Domain\Auth\CurrentUser { id(): ?int; displayName(): ?string; }`, bound to `SessionAuth` in the container.
- `App\Infrastructure\Repository\TestimonialRepository` (`@phpstan-type Row array{id:int,landing_id:int,author_name:string,text:string,rating:?int,gender:string,url:?string,is_active:bool,sort_order:int,created_at:string,updated_at:string,created_by:?int,updated_by:?int}`): `listByLanding(int): list<Row>`, `find(int): ?Row`, `countByLanding(int): int`, `nextSortOrder(int): int`, `insert(int $landingId, array $fields, ?int $userId): int`, `update(int, array $fields, ?int $userId): void`, `delete(int): bool`.
- `App\Infrastructure\Repository\ImageRepository` (`@phpstan-type ImgRow array{id:int,testimonial_id:int,filename:string,thumb_filename:string,mime:string,size_bytes:int,width:int,height:int,sort_order:int,created_at:string,created_by:?int}`): `listByTestimonialIds(list<int>): array<int,list<ImgRow>>`, `find(int): ?ImgRow`, `insert(int $tid, array $data, ?int $userId): int`, `delete(int): bool`.
- `App\Infrastructure\Repository\LandingRepository`: `find(int): ?array` (full row incl. `product_id`, `removed_at`), `listByProductSku(string): list<array{id,country,is_master,url,title,status,testimonial_count}>`, `findMasterFor(int): ?array`.
- `App\Infrastructure\Storage\ImageStorage(string $dir, int $thumbSize=300)`: `store(string $sourcePath, string $ext): array{filename:string,thumb_filename:string}` (phase 15 extends the signature and return shape), `delete(string,string): void`, `path(string): string`, `static isSafeFilename(string): bool`, `static mimeFor(string): string`. `MIMES` const already maps `jpg/png/webp`.
- `App\Application\TestimonialService(TestimonialRepository, LandingRepository, ImageRepository, ImageStorage, TestimonialValidator, RatingResolver, CurrentUser)`: `listForLanding(int): array{data,meta}`, `get(int): array`, `create(int $landingId, array $input): array`, `update(int $id, array $input): array`, `delete(int): void`, `present(Row $row, list<ImgRow> $images=[]): array`. Phase 14 adds a `ChangeLogRepository` constructor param (last position) — every other constructor arg order stays unchanged.
- `App\Application\ImageService(ImageRepository, TestimonialRepository, ImageValidator, ImageStorage, CurrentUser)`: `upload(int $testimonialId, list<array{...}> $files): list<ImgRow>`, `delete(int $imageId): void`. Phase 14 adds `ChangeLogRepository` (last position); phase 15 extends `upload()`'s signature.
- `App\Http\Controller\TestimonialController::id(Request, string): int` — public static, reused by `ImageController` and every new controller in this plan.
- `App\Domain\Testimonial\TestimonialValidator::GENDERS = ['male','female','unisex']`.
- Frontend globals: `Api.{get,post,patch,del,upload}`, `ApiError{status,code,message,fields}`, `Toast.{success,info,error}`, `Confirm.ask({title,body,confirmLabel,danger}): Promise<bool>`, `SaveStatus.bind(el): {saving(),saved(),failed(msg,retry?)}`, `Router.{register,navigate}`, `App.el`, `esc(string): string` (global HTML-escaper defined in `products.js`, loaded first).
- `TestimonialForm.open({landingId, testimonial, onSaved})` dispatches `document.dispatchEvent(new CustomEvent('tm:testimonial-form-open', {detail:{slot, testimonial}}))` — `imageUploader.js` listens on this. Phase 10 adds a `country` field to the `open()` options object (additive, not a breaking change to the call signature since it's a single options object).
- Seed: user `admin`/`admin123`; 10 products, 170 landings; testimonials on every master + every third localised landing; `abforge` EN master id `61763` (used in existing API test fixtures — reuse this id in new API tests for consistency).

## File structure (this plan)

```
src/Domain/Ai/{AiProviderInterface,AbstractMockProvider,OpenAiProvider,GeminiProvider,ClaudeProvider,ProviderRegistry}.php
src/Application/AiService.php
src/Http/Controller/AiController.php
src/Infrastructure/Repository/ChangeLogRepository.php
public/assets/js/components/{copyTestimonials,historyPanel}.js
scripts/{build-zip.sh,zip-exclude.txt}
docs/submission-checklist.md
```
(everything else is modifications to existing files, listed per task)

---

## Phase 10 — AI mock providers

### Task 1: `AiProviderInterface`, 3 mock providers, `ProviderRegistry`, `AiService`, endpoints

**Files:**
- Create: `src/Domain/Ai/AiProviderInterface.php`, `src/Domain/Ai/AbstractMockProvider.php`, `src/Domain/Ai/OpenAiProvider.php`, `src/Domain/Ai/GeminiProvider.php`, `src/Domain/Ai/ClaudeProvider.php`, `src/Domain/Ai/ProviderRegistry.php`, `src/Application/AiService.php`, `src/Http/Controller/AiController.php`
- Modify: `config/container.php`, `config/routes.php`
- Test: `tests/Unit/Domain/Ai/{AbstractMockProviderTest,ProviderRegistryTest}.php`, `tests/Unit/Application/AiServiceTest.php`, `tests/Api/AiTest.php`

**Interfaces:**
- `AiProviderInterface { translate(string $text, string $targetCountry): string; authorName(string $country, string $gender): string; name(): string; }`
- `ProviderRegistry::__construct(array<string,AiProviderInterface> $providers)`; `all(): list<array{id:string,name:string}>`; `has(string $id): bool`; `get(string $id): AiProviderInterface` (throws `\InvalidArgumentException` — callers must check `has()` first, mirroring how `TestimonialValidator` checks membership before use).
- `AiService::translate(string $providerId, string $text, string $targetCountry): array{text:string}`; `authorName(string $providerId, string $country, string $gender): array{name:string}`; `listProviders(): list<array{id:string,name:string}>`.
- Routes: `GET /api/ai/providers`, `POST /api/ai/translate`, `POST /api/ai/author-name`.

- [ ] **Step 1: Failing unit tests for the domain layer**

`tests/Unit/Domain/Ai/AbstractMockProviderTest.php`:
```php
<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Ai;

use App\Domain\Ai\ClaudeProvider;
use App\Domain\Ai\GeminiProvider;
use App\Domain\Ai\OpenAiProvider;
use PHPUnit\Framework\TestCase;

final class AbstractMockProviderTest extends TestCase
{
    public function testTranslatePrefixesWithUppercasedCountryCode(): void
    {
        $p = new OpenAiProvider();
        self::assertSame('[SI] Great product!', $p->translate('Great product!', 'si'));
        self::assertSame('[DE] Great product!', $p->translate('Great product!', 'DE'));
    }

    public function testAuthorNamePicksFromTheGenderPool(): void
    {
        $p = new OpenAiProvider();
        for ($i = 0; $i < 20; $i++) {
            self::assertContains($p->authorName('SI', 'male'), ['Alex', 'Sam', 'Jordan']);
            self::assertContains($p->authorName('SI', 'female'), ['Taylor', 'Riley', 'Morgan']);
        }
    }

    public function testAuthorNameFallsBackToUnisexForUnknownGender(): void
    {
        $p = new OpenAiProvider();
        self::assertContains($p->authorName('SI', 'nonbinary'), ['Casey', 'Drew', 'Jamie']);
    }

    public function testEachProviderHasItsOwnNameAndSamplePool(): void
    {
        $openai = new OpenAiProvider();
        $gemini = new GeminiProvider();
        $claude = new ClaudeProvider();
        self::assertSame('OpenAI (mock)', $openai->name());
        self::assertSame('Gemini (mock)', $gemini->name());
        self::assertSame('Claude (mock)', $claude->name());
        self::assertContains($gemini->authorName('SI', 'male'), ['Noah', 'Liam', 'Ethan']);
        self::assertContains($claude->authorName('SI', 'male'), ['Leo', 'Max', 'Theo']);
    }
}
```

`tests/Unit/Domain/Ai/ProviderRegistryTest.php`:
```php
<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Ai;

use App\Domain\Ai\AiProviderInterface;
use App\Domain\Ai\ProviderRegistry;
use PHPUnit\Framework\TestCase;

final class ProviderRegistryTest extends TestCase
{
    private function provider(string $name): AiProviderInterface
    {
        return new class ($name) implements AiProviderInterface {
            public function __construct(private string $n)
            {
            }

            public function translate(string $text, string $targetCountry): string
            {
                return $text;
            }

            public function authorName(string $country, string $gender): string
            {
                return 'X';
            }

            public function name(): string
            {
                return $this->n;
            }
        };
    }

    public function testAllListsIdsAndNames(): void
    {
        $r = new ProviderRegistry(['a' => $this->provider('A'), 'b' => $this->provider('B')]);
        self::assertSame([['id' => 'a', 'name' => 'A'], ['id' => 'b', 'name' => 'B']], $r->all());
    }

    public function testHasAndGet(): void
    {
        $p = $this->provider('A');
        $r = new ProviderRegistry(['a' => $p]);
        self::assertTrue($r->has('a'));
        self::assertFalse($r->has('missing'));
        self::assertSame($p, $r->get('a'));
    }

    public function testGetUnknownThrows(): void
    {
        $r = new ProviderRegistry([]);
        $this->expectException(\InvalidArgumentException::class);
        $r->get('nope');
    }
}
```

- [ ] **Step 2: Run — expect failures**

`make unit` — expect class-not-found errors for `App\Domain\Ai\*`.

- [ ] **Step 3: Implement the domain layer**

`src/Domain/Ai/AiProviderInterface.php`:
```php
<?php

declare(strict_types=1);

namespace App\Domain\Ai;

/**
 * A mock stand-in for a real translation/generation provider. Every implementation is pure and
 * deterministic-ish (random only within a fixed sample pool) — no network calls, safe to unit test.
 */
interface AiProviderInterface
{
    public function translate(string $text, string $targetCountry): string;

    public function authorName(string $country, string $gender): string;

    public function name(): string;
}
```

`src/Domain/Ai/AbstractMockProvider.php`:
```php
<?php

declare(strict_types=1);

namespace App\Domain\Ai;

/**
 * Shared mock behaviour: translate() just tags the text with the target country (a real provider
 * would call out to an actual translation API — this keeps the call site identical for later).
 * Subclasses only differ in name() and their sample name pool per gender.
 */
abstract class AbstractMockProvider implements AiProviderInterface
{
    public function translate(string $text, string $targetCountry): string
    {
        return sprintf('[%s] %s', strtoupper($targetCountry), $text);
    }

    public function authorName(string $country, string $gender): string
    {
        $pool = $this->sampleNames();
        $names = $pool[$gender] ?? $pool['unisex'];
        return $names[array_rand($names)];
    }

    /** @return array{male:list<string>,female:list<string>,unisex:list<string>} */
    abstract protected function sampleNames(): array;
}
```

`src/Domain/Ai/OpenAiProvider.php`:
```php
<?php

declare(strict_types=1);

namespace App\Domain\Ai;

final class OpenAiProvider extends AbstractMockProvider
{
    public function name(): string
    {
        return 'OpenAI (mock)';
    }

    protected function sampleNames(): array
    {
        return ['male' => ['Alex', 'Sam', 'Jordan'], 'female' => ['Taylor', 'Riley', 'Morgan'], 'unisex' => ['Casey', 'Drew', 'Jamie']];
    }
}
```

`src/Domain/Ai/GeminiProvider.php`:
```php
<?php

declare(strict_types=1);

namespace App\Domain\Ai;

final class GeminiProvider extends AbstractMockProvider
{
    public function name(): string
    {
        return 'Gemini (mock)';
    }

    protected function sampleNames(): array
    {
        return ['male' => ['Noah', 'Liam', 'Ethan'], 'female' => ['Ava', 'Mia', 'Zoe'], 'unisex' => ['Sky', 'River', 'Quinn']];
    }
}
```

`src/Domain/Ai/ClaudeProvider.php`:
```php
<?php

declare(strict_types=1);

namespace App\Domain\Ai;

final class ClaudeProvider extends AbstractMockProvider
{
    public function name(): string
    {
        return 'Claude (mock)';
    }

    protected function sampleNames(): array
    {
        return ['male' => ['Leo', 'Max', 'Theo'], 'female' => ['Nora', 'Iris', 'June'], 'unisex' => ['Robin', 'Sage', 'Wren']];
    }
}
```

`src/Domain/Ai/ProviderRegistry.php`:
```php
<?php

declare(strict_types=1);

namespace App\Domain\Ai;

final class ProviderRegistry
{
    /** @param array<string,AiProviderInterface> $providers  id => instance */
    public function __construct(private readonly array $providers)
    {
    }

    /** @return list<array{id:string,name:string}> */
    public function all(): array
    {
        $out = [];
        foreach ($this->providers as $id => $p) {
            $out[] = ['id' => $id, 'name' => $p->name()];
        }
        return $out;
    }

    public function has(string $id): bool
    {
        return isset($this->providers[$id]);
    }

    public function get(string $id): AiProviderInterface
    {
        if (!isset($this->providers[$id])) {
            throw new \InvalidArgumentException("Unknown AI provider '$id'");
        }
        return $this->providers[$id];
    }
}
```

- [ ] **Step 4: Run — domain tests pass**

`make unit` → green for the new `Tests\Unit\Domain\Ai\*` tests. Commit:
```bash
git add -A && git commit -m "feat(ai): mock provider interface, 3 providers, registry"
```

- [ ] **Step 5: Failing tests for `AiService`**

`tests/Unit/Application/AiServiceTest.php`:
```php
<?php

declare(strict_types=1);

namespace Tests\Unit\Application;

use App\Application\AiService;
use App\Domain\Ai\ProviderRegistry;
use App\Domain\Exception\ValidationException;
use App\Domain\Ai\AiProviderInterface;
use PHPUnit\Framework\TestCase;

final class AiServiceTest extends TestCase
{
    private function service(): AiService
    {
        $provider = new class implements AiProviderInterface {
            public function translate(string $text, string $targetCountry): string
            {
                return "[$targetCountry] $text";
            }

            public function authorName(string $country, string $gender): string
            {
                return "$gender-$country";
            }

            public function name(): string
            {
                return 'Mock';
            }
        };
        return new AiService(new ProviderRegistry(['mock' => $provider]));
    }

    public function testListProviders(): void
    {
        self::assertSame([['id' => 'mock', 'name' => 'Mock']], $this->service()->listProviders());
    }

    public function testTranslate(): void
    {
        self::assertSame(['text' => '[SI] Hello'], $this->service()->translate('mock', 'Hello', 'si'));
    }

    public function testTranslateRejectsUnknownProvider(): void
    {
        $this->expectException(ValidationException::class);
        $this->service()->translate('nope', 'Hello', 'si');
    }

    public function testTranslateRejectsBlankTextAndBadCountry(): void
    {
        try {
            $this->service()->translate('mock', '   ', 'six');
            self::fail('expected ValidationException');
        } catch (ValidationException $e) {
            self::assertArrayHasKey('text', $e->getFields());
            self::assertArrayHasKey('target_country', $e->getFields());
        }
    }

    public function testAuthorName(): void
    {
        self::assertSame(['name' => 'male-SI'], $this->service()->authorName('mock', 'si', 'male'));
    }

    public function testAuthorNameRejectsBadGender(): void
    {
        $this->expectException(ValidationException::class);
        $this->service()->authorName('mock', 'si', 'other');
    }
}
```

- [ ] **Step 6: Run — expect class-not-found for `AiService`**

`make unit`

- [ ] **Step 7: Implement `AiService` and `AiController`, wire routes/container**

`src/Application/AiService.php`:
```php
<?php

declare(strict_types=1);

namespace App\Application;

use App\Domain\Ai\ProviderRegistry;
use App\Domain\Exception\ValidationException;
use App\Domain\Testimonial\TestimonialValidator;

final class AiService
{
    public function __construct(private readonly ProviderRegistry $providers)
    {
    }

    /** @return list<array{id:string,name:string}> */
    public function listProviders(): array
    {
        return $this->providers->all();
    }

    /** @return array{text:string} */
    public function translate(string $providerId, string $text, string $targetCountry): array
    {
        $errors = $this->validateProvider($providerId);
        $text = trim($text);
        if ($text === '') {
            $errors['text'] = 'Text is required';
        }
        $country = strtoupper(trim($targetCountry));
        if (!preg_match('/^[A-Z]{2}$/', $country)) {
            $errors['target_country'] = 'Target country must be a 2-letter code';
        }
        if ($errors !== []) {
            throw new ValidationException($errors);
        }
        return ['text' => $this->providers->get($providerId)->translate($text, $country)];
    }

    /** @return array{name:string} */
    public function authorName(string $providerId, string $country, string $gender): array
    {
        $errors = $this->validateProvider($providerId);
        $countryU = strtoupper(trim($country));
        if (!preg_match('/^[A-Z]{2}$/', $countryU)) {
            $errors['country'] = 'Country must be a 2-letter code';
        }
        if (!in_array($gender, TestimonialValidator::GENDERS, true)) {
            $errors['gender'] = 'Gender must be male, female or unisex';
        }
        if ($errors !== []) {
            throw new ValidationException($errors);
        }
        return ['name' => $this->providers->get($providerId)->authorName($countryU, $gender)];
    }

    /** @return array<string,string> */
    private function validateProvider(string $providerId): array
    {
        return $this->providers->has($providerId) ? [] : ['provider' => 'Unknown provider'];
    }
}
```

`src/Http/Controller/AiController.php`:
```php
<?php

declare(strict_types=1);

namespace App\Http\Controller;

use App\Application\AiService;
use App\Http\Request;
use App\Http\Response;

final class AiController
{
    public function __construct(private readonly AiService $service)
    {
    }

    public function providers(Request $request): Response
    {
        return Response::json(['data' => $this->service->listProviders()]);
    }

    public function translate(Request $request): Response
    {
        return Response::json($this->service->translate(
            (string) $request->input('provider', ''),
            (string) $request->input('text', ''),
            (string) $request->input('target_country', ''),
        ));
    }

    public function authorName(Request $request): Response
    {
        return Response::json($this->service->authorName(
            (string) $request->input('provider', ''),
            (string) $request->input('country', ''),
            (string) $request->input('gender', 'unisex'),
        ));
    }
}
```

`config/routes.php` — add `use App\Http\Controller\AiController;` and, after the images routes:
```php
    $r->get('/api/ai/providers', [AiController::class, 'providers']);
    $r->post('/api/ai/translate', [AiController::class, 'translate']);
    $r->post('/api/ai/author-name', [AiController::class, 'authorName']);
```

`config/container.php` — add imports (`App\Application\AiService`, `App\Domain\Ai\{ProviderRegistry,OpenAiProvider,GeminiProvider,ClaudeProvider}`, `App\Http\Controller\AiController`) and, after the image bindings:
```php
    $c->set(ProviderRegistry::class, fn () => new ProviderRegistry([
        'openai' => new OpenAiProvider(),
        'gemini' => new GeminiProvider(),
        'claude' => new ClaudeProvider(),
    ]));
    $c->set(AiService::class, fn (Container $c) => new AiService($c->get(ProviderRegistry::class)));
    $c->set(AiController::class, fn (Container $c) => new AiController($c->get(AiService::class)));
```

- [ ] **Step 8: Run — unit tests pass**

`make unit` → green.

- [ ] **Step 9: Failing API test**

`tests/Api/AiTest.php`:
```php
<?php

declare(strict_types=1);

namespace Tests\Api;

final class AiTest extends ApiTestCase
{
    public function testListProviders(): void
    {
        $r = $this->request('GET', '/api/ai/providers');
        self::assertSame(200, $r['status']);
        $ids = array_column($r['json']['data'], 'id');
        self::assertEqualsCanonicalizing(['openai', 'gemini', 'claude'], $ids);
    }

    public function testTranslate(): void
    {
        $r = $this->request('POST', '/api/ai/translate', ['provider' => 'openai', 'text' => 'Great product', 'target_country' => 'si']);
        self::assertSame(200, $r['status']);
        self::assertSame('[SI] Great product', $r['json']['text']);
    }

    public function testTranslateValidation(): void
    {
        $r = $this->request('POST', '/api/ai/translate', ['provider' => 'nope', 'text' => '', 'target_country' => 'x']);
        self::assertSame(422, $r['status']);
        self::assertArrayHasKey('provider', $r['json']['error']['fields']);
        self::assertArrayHasKey('text', $r['json']['error']['fields']);
        self::assertArrayHasKey('target_country', $r['json']['error']['fields']);
    }

    public function testAuthorName(): void
    {
        $r = $this->request('POST', '/api/ai/author-name', ['provider' => 'claude', 'country' => 'de', 'gender' => 'male']);
        self::assertSame(200, $r['status']);
        self::assertContains($r['json']['name'], ['Leo', 'Max', 'Theo']);
    }

    public function testAiEndpointsRequireSession(): void
    {
        $this->logout();
        self::assertSame(401, $this->request('GET', '/api/ai/providers')['status']);
    }
}
```

- [ ] **Step 10: Green + commit**

```bash
make unit && make integration && make lint && make stan && make up && make api
git add -A && git commit -m "feat(ai): AiService, AiController, /api/ai/* endpoints"
```

### Task 2: Provider dropdown + "Translate from EN" / "Suggest name" buttons; merge phase 10

**Files:**
- Modify: `public/assets/js/components/testimonialForm.js`, `public/assets/js/views/testimonials.js`, `public/assets/css/app.css`, `docs/time-log.md`

**Interfaces:**
- `TestimonialForm.open({landingId, country, testimonial, onSaved})` — `country` is new (the 2-letter country code of the landing being edited); `testimonials.js` passes `L.country`.

- [ ] **Step 1: Pass `country` into `TestimonialForm.open`**

In `public/assets/js/views/testimonials.js`, both call sites of `TestimonialForm.open` gain `country: L.country`:
```js
document.getElementById('add-testimonial').addEventListener('click', () =>
  TestimonialForm.open({ landingId, country: L.country, testimonial: null, onSaved: reload }));
```
and inside the table click handler:
```js
if (e.target.closest('.tm-edit')) {
  TestimonialForm.open({ landingId, country: L.country, testimonial: t, onSaved: reload });
}
```

- [ ] **Step 2: Add the AI controls to the form modal**

In `public/assets/js/components/testimonialForm.js`, inside `ensure()`'s template, add a new row right after the Text `<textarea>` block (before the Gender/URL row):
```html
<div class="col-12">
  <div class="d-flex flex-wrap align-items-center gap-2">
    <select class="form-select form-select-sm w-auto" id="tf-ai-provider" aria-label="AI provider"></select>
    <button type="button" class="btn btn-sm btn-outline-secondary" id="tf-ai-translate"><i class="bi bi-translate me-1"></i>Translate from EN</button>
    <button type="button" class="btn btn-sm btn-outline-secondary" id="tf-ai-name"><i class="bi bi-magic me-1"></i>Suggest name</button>
    <span class="text-muted small" id="tf-ai-status"></span>
  </div>
</div>
```
Still inside `ensure()`, after the existing `form.addEventListener('input', …)`/`form.addEventListener('change', …)` lines, wire the buttons (static markup, attached once) and load the provider list once:
```js
const providerSelect = el.querySelector('#tf-ai-provider');
Api.get('/api/ai/providers').then((res) => {
  providerSelect.innerHTML = res.data.map((p) => `<option value="${esc(p.id)}">${esc(p.name)}</option>`).join('');
}).catch(() => { providerSelect.innerHTML = '<option value="">AI unavailable</option>'; });

const aiStatus = el.querySelector('#tf-ai-status');
el.querySelector('#tf-ai-translate').addEventListener('click', async () => {
  const f = el.querySelector('#testimonial-form');
  if (!f.text.value.trim()) { Toast.error('Type some text first'); return; }
  aiStatus.textContent = 'Translating…';
  try {
    const res = await Api.post('/api/ai/translate', { provider: providerSelect.value, text: f.text.value, target_country: currentCountry });
    f.text.value = res.text;
    el.querySelector('#tf-count').textContent = `${res.text.length} / 2000`;
    aiStatus.textContent = '';
    markDirty();
  } catch (err) {
    aiStatus.textContent = '';
    Toast.error('Translate failed: ' + err.message);
  }
});
el.querySelector('#tf-ai-name').addEventListener('click', async () => {
  const f = el.querySelector('#testimonial-form');
  aiStatus.textContent = 'Suggesting…';
  try {
    const res = await Api.post('/api/ai/author-name', { provider: providerSelect.value, country: currentCountry, gender: f.gender.value });
    f.author_name.value = res.name;
    aiStatus.textContent = '';
    markDirty();
  } catch (err) {
    aiStatus.textContent = '';
    Toast.error('Suggest name failed: ' + err.message);
  }
});
```
Add a module-level variable alongside the existing `let el, modal;` / `let markDirty = () => {};` at the top of the IIFE:
```js
let currentCountry = '';
```
And in `window.TestimonialForm.open(...)`, set it from the new option right after `ensure(); clearErrors();`:
```js
currentCountry = country || '';
```
(the `open({ landingId, testimonial, onSaved })` destructuring in the function signature becomes `open({ landingId, country, testimonial, onSaved })`).

- [ ] **Step 3: CSS (optional polish)**

`app.css` append:
```css
#tf-ai-status { min-width: 6rem; }
```

- [ ] **Step 4: Verify, commit, merge phase 10**

`make up && make seed`; in the browser (or via curl against `/api/ai/*` per Task 1's API test, since Chrome-extension UI checks are unreliable in this environment — see plan-2 precedent): open a testimonial for a non-EN country, type English text, pick a provider, click "Translate from EN" → text field updates to `[XX] …`; click "Suggest name" → author name field updates; Save is still required to persist (buttons never auto-save).
```bash
make unit && make lint && make stan
git add -A && git commit -m "feat(ai): provider dropdown and translate/suggest-name buttons in the testimonial form"
```
Time-log row `| 10 | AI mock providers (translate, suggest name) | 2h |`, push, PR `Phase 10: AI mocks`, wait for CI, merge.

---

## Phase 11 — Drag & drop reorder

### Task 3: Reorder endpoints for testimonials and images

**Files:**
- Modify: `src/Infrastructure/Repository/TestimonialRepository.php`, `src/Infrastructure/Repository/ImageRepository.php`, `src/Application/TestimonialService.php`, `src/Application/ImageService.php`, `src/Http/Controller/TestimonialController.php`, `src/Http/Controller/ImageController.php`, `config/routes.php`
- Test: `tests/Integration/Repository/TestimonialRepositoryTest.php`, `tests/Integration/Repository/ImageRepositoryTest.php`, `tests/Integration/Application/TestimonialServiceTest.php`, `tests/Api/TestimonialsTest.php`, `tests/Api/ImagesTest.php`

**Interfaces:**
- `TestimonialRepository::reorder(int $landingId, list<int> $ids): void` — assigns `sort_order` = array index, in one transaction; ids not belonging to `$landingId` are silently no-ops (the `WHERE … AND landing_id = ?` guard), so validation of "exactly this landing's ids" happens one layer up in the service.
- `TestimonialService::reorder(int $landingId, list<int> $ids): array` — 404 if landing missing/removed; 422 (`fields.ids`) if `$ids` isn't exactly the landing's current testimonial id set; returns the same shape as `listForLanding()`.
- `ImageRepository::reorder(int $testimonialId, list<int> $ids): void` — mirrors the testimonial version for `testimonial_images`.
- `ImageService::reorder(int $testimonialId, list<int> $ids): list<ImgRow>` — 404 if testimonial missing; 422 if `$ids` mismatched.
- Routes: `PATCH /api/landings/{id}/testimonials/reorder` (body `{"ids":[3,1,2]}`), `PATCH /api/testimonials/{id}/images/reorder` (body `{"ids":[7,5,6]}`).

- [ ] **Step 1: Failing repository tests**

Add to `tests/Integration/Repository/TestimonialRepositoryTest.php` (inside the existing test class, following its existing `setUp()` fixture — read the file first to match its exact landing/product ids before writing this method body verbatim; the shape below assumes a landing id available as `$this->landingId` or equivalent, adjust to match what's already in the file):
```php
    public function testReorderAssignsSortOrderByPosition(): void
    {
        $repo = new TestimonialRepository(self::$pdo);
        $p = self::insert('products', ['parent_sku' => 'reorder-sku', 'title' => 'T']);
        $l = self::insert('landings', ['id' => 501, 'product_id' => $p, 'country' => 'EN', 'is_master' => 1, 'url' => 'u', 'title' => 'T', 'last_synced_at' => '2026-01-01 00:00:00']);
        $a = $repo->insert($l, ['author_name' => 'A', 'text' => 'a', 'rating' => null, 'gender' => 'unisex', 'url' => null, 'is_active' => true, 'sort_order' => null], null);
        $b = $repo->insert($l, ['author_name' => 'B', 'text' => 'b', 'rating' => null, 'gender' => 'unisex', 'url' => null, 'is_active' => true, 'sort_order' => null], null);
        $c = $repo->insert($l, ['author_name' => 'C', 'text' => 'c', 'rating' => null, 'gender' => 'unisex', 'url' => null, 'is_active' => true, 'sort_order' => null], null);

        $repo->reorder($l, [$c, $a, $b]);

        $rows = $repo->listByLanding($l);
        self::assertSame([$c, $a, $b], array_column($rows, 'id'));
        self::assertSame([0, 1, 2], array_column($rows, 'sort_order'));
    }
```

Add to `tests/Integration/Repository/ImageRepositoryTest.php` (same pattern — read the file first for its fixture helper names):
```php
    public function testReorderAssignsSortOrderByPosition(): void
    {
        // Insert a testimonial + 3 images via the existing fixture helpers used elsewhere in this
        // file, then:
        $repo = new ImageRepository(self::$pdo);
        // ... ($t, $img1, $img2, $img3 set up per this file's existing conventions)
        $repo->reorder($t, [$img3, $img1, $img2]);
        $rows = $repo->listByTestimonialIds([$t])[$t];
        self::assertSame([$img3, $img1, $img2], array_column($rows, 'id'));
        self::assertSame([0, 1, 2], array_column($rows, 'sort_order'));
    }
```

- [ ] **Step 2: Run — expect "method does not exist"**

`make integration`

- [ ] **Step 3: Implement the repository methods**

Add to `TestimonialRepository`:
```php
    /** @param list<int> $ids  full ordered set of every testimonial id belonging to $landingId */
    public function reorder(int $landingId, array $ids): void
    {
        $this->pdo->beginTransaction();
        try {
            $stmt = $this->pdo->prepare('UPDATE testimonials SET sort_order = ? WHERE id = ? AND landing_id = ?');
            foreach ($ids as $i => $id) {
                $stmt->execute([$i, $id, $landingId]);
            }
            $this->pdo->commit();
        } catch (\Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }
    }
```

Add to `ImageRepository`:
```php
    /** @param list<int> $ids */
    public function reorder(int $testimonialId, array $ids): void
    {
        $this->pdo->beginTransaction();
        try {
            $stmt = $this->pdo->prepare('UPDATE testimonial_images SET sort_order = ? WHERE id = ? AND testimonial_id = ?');
            foreach ($ids as $i => $id) {
                $stmt->execute([$i, $id, $testimonialId]);
            }
            $this->pdo->commit();
        } catch (\Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }
    }
```

- [ ] **Step 4: Run — repository tests pass**

`make integration`

- [ ] **Step 5: Failing service/API tests**

Add to `tests/Integration/Application/TestimonialServiceTest.php` (inside the existing class, reusing its `$this->svc` and landing ids 1/2 from its `setUp()`):
```php
    public function testReorderRejectsAMismatchedIdSet(): void
    {
        $t1 = $this->svc->create(1, ['author_name' => 'A', 'text' => 'a']);
        $t2 = $this->svc->create(1, ['author_name' => 'B', 'text' => 'b']);
        $this->expectException(ValidationException::class);
        $this->svc->reorder(1, [$t1['id']]);   // missing $t2['id']
    }

    public function testReorderAppliesTheGivenOrder(): void
    {
        $t1 = $this->svc->create(1, ['author_name' => 'A', 'text' => 'a']);
        $t2 = $this->svc->create(1, ['author_name' => 'B', 'text' => 'b']);
        $res = $this->svc->reorder(1, [$t2['id'], $t1['id']]);
        self::assertSame([$t2['id'], $t1['id']], array_column($res['data'], 'id'));
    }
```

`tests/Api/TestimonialsTest.php` — add (reusing the file's existing seeded EN landing id `61763`):
```php
    public function testReorderTestimonials(): void
    {
        $list = $this->request('GET', '/api/landings/61763/testimonials')['json']['data'];
        $ids = array_column($list, 'id');
        self::assertGreaterThanOrEqual(2, count($ids));
        $reversed = array_reverse($ids);
        $r = $this->request('PATCH', '/api/landings/61763/testimonials/reorder', ['ids' => $reversed]);
        self::assertSame(200, $r['status']);
        self::assertSame($reversed, array_column($r['json']['data'], 'id'));
        // restore original order so other tests in this run aren't affected
        $this->request('PATCH', '/api/landings/61763/testimonials/reorder', ['ids' => $ids]);
    }

    public function testReorderRejectsWrongIdSet(): void
    {
        $r = $this->request('PATCH', '/api/landings/61763/testimonials/reorder', ['ids' => [999999999]]);
        self::assertSame(422, $r['status']);
        self::assertArrayHasKey('ids', $r['json']['error']['fields']);
    }
```

`tests/Api/ImagesTest.php` — add:
```php
    public function testReorderImages(): void
    {
        $id = $this->newTestimonial();
        $tmp = sys_get_temp_dir();
        $up = $this->upload("/api/testimonials/$id/images", ['images[0]' => ImageFixtures::png($tmp), 'images[1]' => ImageFixtures::jpeg($tmp)]);
        $ids = array_column($up['json']['images'], 'id');
        $reversed = array_reverse($ids);
        $r = $this->request('PATCH', "/api/testimonials/$id/images/reorder", ['ids' => $reversed]);
        self::assertSame(200, $r['status']);
        self::assertSame($reversed, array_column($r['json']['images'], 'id'));
        $this->request('DELETE', "/api/testimonials/$id");
    }
```

- [ ] **Step 6: Run — expect failures**

`make integration` (class exists, method missing) then, once wired, `make api`.

- [ ] **Step 7: Implement the service, controller and route layers**

`TestimonialService` — add `use App\Domain\Exception\ValidationException;` to the imports, and add this method:
```php
    /** @param list<int> $ids @return array{data: list<array<string,mixed>>, meta: array{inherited: bool, source_landing_id: int, landing: array{id:int,country:string,is_master:bool,title:string,url:string}}} */
    public function reorder(int $landingId, array $ids): array
    {
        $this->activeLanding($landingId);
        $existingIds = array_column($this->testimonials->listByLanding($landingId), 'id');
        $sortedExisting = $existingIds;
        sort($sortedExisting);
        $sortedGiven = $ids;
        sort($sortedGiven);
        if ($sortedExisting !== $sortedGiven) {
            throw new ValidationException(['ids' => 'Must list exactly the testimonials belonging to this landing']);
        }
        $this->testimonials->reorder($landingId, $ids);
        return $this->listForLanding($landingId);
    }
```

`ImageService` — add `use App\Domain\Exception\ValidationException;`, and:
```php
    /** @param list<int> $ids @return list<ImgRow> */
    public function reorder(int $testimonialId, array $ids): array
    {
        if ($this->testimonials->find($testimonialId) === null) {
            throw new NotFoundException("Testimonial $testimonialId not found");
        }
        $existing = $this->images->listByTestimonialIds([$testimonialId])[$testimonialId] ?? [];
        $existingIds = array_column($existing, 'id');
        $sortedExisting = $existingIds;
        sort($sortedExisting);
        $sortedGiven = $ids;
        sort($sortedGiven);
        if ($sortedExisting !== $sortedGiven) {
            throw new ValidationException(['ids' => 'Must list exactly the images belonging to this testimonial']);
        }
        $this->images->reorder($testimonialId, $ids);
        return $this->images->listByTestimonialIds([$testimonialId])[$testimonialId] ?? [];
    }
```

`TestimonialController` — add `use App\Domain\Exception\ValidationException;` and:
```php
    public function reorder(Request $request): Response
    {
        $raw = $request->input('ids');
        if (!is_array($raw)) {
            throw new ValidationException(['ids' => 'Must be an array of testimonial ids']);
        }
        return Response::json($this->service->reorder(self::id($request, 'id'), array_map('intval', $raw)));
    }
```

`ImageController` — add `use App\Domain\Exception\ValidationException;` and:
```php
    public function reorder(Request $request): Response
    {
        $raw = $request->input('ids');
        if (!is_array($raw)) {
            throw new ValidationException(['ids' => 'Must be an array of image ids']);
        }
        return Response::json(['images' => $this->service->reorder(TestimonialController::id($request, 'id'), array_map('intval', $raw))]);
    }
```

`config/routes.php` — add, near the existing testimonials/images routes:
```php
    $r->patch('/api/landings/{id}/testimonials/reorder', [TestimonialController::class, 'reorder']);
    $r->patch('/api/testimonials/{id}/images/reorder', [ImageController::class, 'reorder']);
```

- [ ] **Step 8: Green + commit**

```bash
make unit && make integration && make lint && make stan && make up && make api
git add -A && git commit -m "feat(reorder): PATCH .../reorder for testimonials and images"
```

### Task 4: Drag-and-drop UI for testimonials table and image grid; merge phase 11

**Files:**
- Modify: `public/assets/js/views/testimonials.js`, `public/assets/js/components/imageUploader.js`, `public/assets/css/app.css`, `docs/time-log.md`

**Interfaces:**
- Uses native HTML5 drag-and-drop (`draggable`, `dragstart`/`dragover`/`drop`/`dragend`) — no library.

- [ ] **Step 1: Add a drag-handle column to the testimonials table**

In `public/assets/js/views/testimonials.js`'s `row()` function, prepend a handle cell (before the `#` cell) and mark the `<tr>` draggable when not inherited:
```js
  function row(t, inherited) {
    const stars = `<span class="tm-stars" title="${t.rating === null ? 'Random: shown between 4.0 and 5.0' : 'Fixed rating'}">★ ${t.rating_display.toFixed(1)}</span>${t.rating === null ? ' <span class="badge bg-warning text-dark" title="Random rating">🎲</span>' : ''}`;
    const thumbs = (t.images || []).slice(0, 3).map((i) => `<img src="media/${esc(i.thumb_filename)}" alt="" class="tm-thumb">`).join('');
    return `
      <tr data-id="${t.id}" ${inherited ? '' : 'draggable="true"'}>
        <td class="tm-drag-handle text-muted" title="${inherited ? '' : 'Drag to reorder'}">${inherited ? '' : '<i class="bi bi-grip-vertical"></i>'}</td>
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
```
And the `<thead>` row gains a matching empty `<th></th>` before `<th>#</th>`:
```html
<thead><tr><th></th><th>#</th><th>Author</th><th>Text</th><th>Rating</th><th>Images</th><th>Active</th><th></th></tr></thead>
```
Update the `colspan` on the empty-state row from `7` to `8`.

- [ ] **Step 2: Wire drag-and-drop after the table renders**

Add this helper function at module scope in `testimonials.js` (above `window.Views.testimonials`):
```js
  let dragEl = null;
  function enableReorder(tbody, onReordered) {
    tbody.querySelectorAll('tr[draggable="true"]').forEach((tr) => {
      tr.addEventListener('dragstart', () => { dragEl = tr; tr.classList.add('tm-dragging'); });
      tr.addEventListener('dragend', () => { tr.classList.remove('tm-dragging'); dragEl = null; });
      tr.addEventListener('dragover', (e) => {
        e.preventDefault();
        if (!dragEl || dragEl === tr) return;
        const rect = tr.getBoundingClientRect();
        const before = (e.clientY - rect.top) < rect.height / 2;
        tr.parentNode.insertBefore(dragEl, before ? tr : tr.nextSibling);
      });
      tr.addEventListener('drop', async (e) => {
        e.preventDefault();
        const ids = [...tbody.querySelectorAll('tr[data-id]')].map((r) => parseInt(r.dataset.id, 10));
        try {
          await onReordered(ids);
        } catch (err) {
          Toast.error('Could not save order: ' + err.message);
        }
      });
    });
  }
```
At the end of `window.Views.testimonials`, after the existing `change` listener block, add (only when the view is editable):
```js
    if (!inherited) {
      enableReorder(document.querySelector('#testimonials-table tbody'), async (ids) => {
        await Api.patch(`/api/landings/${landingId}/testimonials/reorder`, { ids });
        Toast.success('Order saved');
      });
    }
```

- [ ] **Step 3: Same pattern for the image grid**

In `public/assets/js/components/imageUploader.js`, update `thumbCard()` to make cards draggable, and add drag wiring in `mount()` right after the existing `grid.addEventListener('click', …)` block:
```js
  function thumbCard(img) {
    return `<div class="tm-img-card" draggable="true" data-image-id="${img.id}">
      <img src="media/${esc(img.thumb_filename)}" alt="" width="96" height="96">
      <button type="button" class="btn btn-sm btn-danger tm-img-delete" title="Delete image" aria-label="Delete image"><i class="bi bi-x-lg"></i></button>
      <small class="text-muted d-block text-truncate">${img.width}×${img.height}</small>
    </div>`;
  }
```
```js
      let dragImg = null;
      grid.querySelectorAll('.tm-img-card').forEach(wireDrag);
      function wireDrag(card) {
        card.addEventListener('dragstart', () => { dragImg = card; card.classList.add('tm-dragging'); });
        card.addEventListener('dragend', () => { card.classList.remove('tm-dragging'); dragImg = null; });
        card.addEventListener('dragover', (e) => {
          e.preventDefault();
          if (!dragImg || dragImg === card) return;
          const rect = card.getBoundingClientRect();
          const before = (e.clientX - rect.left) < rect.width / 2;
          card.parentNode.insertBefore(dragImg, before ? card : card.nextSibling);
        });
        card.addEventListener('drop', async (e) => {
          e.preventDefault();
          const ids = [...grid.querySelectorAll('.tm-img-card')].map((c) => parseInt(c.dataset.imageId, 10));
          try {
            await Api.patch(`/api/testimonials/${testimonial.id}/images/reorder`, { ids });
            Toast.success('Order saved');
          } catch (err) {
            Toast.error('Could not save order: ' + err.message);
          }
        });
      }
```
And in `send()`'s success branch, after `grid.insertAdjacentHTML('beforeend', thumbCard(img))`, wire the newly-added card too: `wireDrag(grid.lastElementChild);`.

- [ ] **Step 4: CSS**

`app.css` append:
```css
.tm-drag-handle { cursor: grab; width: 1.5rem; }
.tm-dragging { opacity: .4; }
.tm-img-card[draggable="true"] { cursor: grab; }
```

- [ ] **Step 5: Verify, commit, merge phase 11**

`make up && make seed`; open a landing's testimonials, drag a row to a new position → order persists on reload; open a testimonial with 2+ images, drag a thumbnail → order persists.
```bash
make unit && make lint && make stan
git add -A && git commit -m "feat(reorder): drag-and-drop UI for testimonials and image grid"
```
Time-log row `| 11 | Drag & drop reorder (testimonials, images) | 2h |`, push, PR `Phase 11: reorder`, wait for CI, merge.

---

## Phase 12 — Copy between countries

### Task 5: Copy-preview and copy endpoints

**Files:**
- Modify: `src/Infrastructure/Repository/TestimonialRepository.php`, `src/Application/TestimonialService.php`, `src/Http/Controller/TestimonialController.php`, `config/routes.php`
- Test: `tests/Integration/Repository/TestimonialRepositoryTest.php`, `tests/Integration/Application/TestimonialServiceTest.php`, `tests/Api/TestimonialsTest.php`

**Interfaces:**
- `TestimonialRepository::replaceOrAppend(int $targetLandingId, list<Row> $sourceRows, bool $replace, ?int $userId): void` — in one transaction: if `$replace`, deletes every existing testimonial of `$targetLandingId` first (FK cascade removes their `testimonial_images` rows — the service layer deletes the image *files* before calling this, same pattern as `delete()`); inserts a copy of each `$sourceRows` entry with fresh `sort_order` (0-based if replacing, appended after the current max otherwise).
- `TestimonialService::copyPreview(int $targetLandingId, int $sourceLandingId, string $mode): array{source: array{id:int,country:string,title:string}, mode:string, will_add:int, will_remove:int, items: list<array{author_name:string,text:string}>}`.
- `TestimonialService::copy(int $targetLandingId, int $sourceLandingId, string $mode): array` — same return shape as `listForLanding()`.
- Both validate: `$mode` ∈ `{replace,append}` (422 `fields.mode`); source ≠ target (422 `fields.source_landing_id`); source landing exists & active (404); source belongs to the same product as target (422 `fields.source_landing_id`).
- Routes: `GET /api/landings/{id}/testimonials/copy-preview?source_landing_id=&mode=`, `POST /api/landings/{id}/testimonials/copy` (body `{source_landing_id, mode}`).

- [ ] **Step 1: Failing repository test**

Add to `tests/Integration/Repository/TestimonialRepositoryTest.php`:
```php
    public function testReplaceOrAppend(): void
    {
        $repo = new TestimonialRepository(self::$pdo);
        $p = self::insert('products', ['parent_sku' => 'copy-sku', 'title' => 'T']);
        $src = self::insert('landings', ['id' => 601, 'product_id' => $p, 'country' => 'EN', 'is_master' => 1, 'url' => 'u', 'title' => 'T', 'last_synced_at' => '2026-01-01 00:00:00']);
        $dst = self::insert('landings', ['id' => 602, 'product_id' => $p, 'country' => 'SI', 'is_master' => 0, 'url' => 'u', 'title' => 'T', 'last_synced_at' => '2026-01-01 00:00:00']);
        $repo->insert($src, ['author_name' => 'A', 'text' => 'a', 'rating' => null, 'gender' => 'unisex', 'url' => null, 'is_active' => true, 'sort_order' => null], null);
        $repo->insert($src, ['author_name' => 'B', 'text' => 'b', 'rating' => null, 'gender' => 'unisex', 'url' => null, 'is_active' => true, 'sort_order' => null], null);
        $existing = $repo->insert($dst, ['author_name' => 'Old', 'text' => 'old', 'rating' => null, 'gender' => 'unisex', 'url' => null, 'is_active' => true, 'sort_order' => null], null);

        $repo->replaceOrAppend($dst, $repo->listByLanding($src), true, 7);
        $rows = $repo->listByLanding($dst);
        self::assertSame(['A', 'B'], array_column($rows, 'author_name'));
        self::assertNull($repo->find($existing));
        self::assertSame([0, 1], array_column($rows, 'sort_order'));
        self::assertSame(7, $rows[0]['created_by']);

        $repo->replaceOrAppend($dst, $repo->listByLanding($src), false, null);
        self::assertSame(4, $repo->countByLanding($dst));
    }
```

- [ ] **Step 2: Run — expect "method does not exist"**

`make integration`

- [ ] **Step 3: Implement `TestimonialRepository::replaceOrAppend`**

```php
    /** @param list<Row> $sourceRows */
    public function replaceOrAppend(int $targetLandingId, array $sourceRows, bool $replace, ?int $userId): void
    {
        $this->pdo->beginTransaction();
        try {
            if ($replace) {
                $this->pdo->prepare('DELETE FROM testimonials WHERE landing_id = ?')->execute([$targetLandingId]);
            }
            $next = $replace ? 0 : $this->nextSortOrder($targetLandingId);
            $stmt = $this->pdo->prepare(
                'INSERT INTO testimonials (landing_id, author_name, text, rating, gender, url, is_active, sort_order, created_by, updated_by)
                 VALUES (:landing_id, :author_name, :text, :rating, :gender, :url, :is_active, :sort_order, :created_by, :updated_by)',
            );
            foreach ($sourceRows as $i => $row) {
                $stmt->execute([
                    'landing_id' => $targetLandingId, 'author_name' => $row['author_name'], 'text' => $row['text'],
                    'rating' => $row['rating'], 'gender' => $row['gender'], 'url' => $row['url'],
                    'is_active' => (int) $row['is_active'], 'sort_order' => $next + $i,
                    'created_by' => $userId, 'updated_by' => $userId,
                ]);
            }
            $this->pdo->commit();
        } catch (\Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }
    }
```

- [ ] **Step 4: Run — repository test passes**

`make integration`

- [ ] **Step 5: Failing service and API tests**

Add to `tests/Integration/Application/TestimonialServiceTest.php`:
```php
    public function testCopyPreviewAndCopyAppend(): void
    {
        $this->svc->create(1, ['author_name' => 'EN one', 'text' => 'T']);
        $this->svc->create(1, ['author_name' => 'EN two', 'text' => 'T']);
        $this->svc->create(2, ['author_name' => 'SI existing', 'text' => 'T']);

        $preview = $this->svc->copyPreview(2, 1, 'append');
        self::assertSame('EN', $preview['source']['country']);
        self::assertSame(2, $preview['will_add']);
        self::assertSame(0, $preview['will_remove']);

        $res = $this->svc->copy(2, 1, 'append');
        self::assertSame(['SI existing', 'EN one', 'EN two'], array_column($res['data'], 'author_name'));
    }

    public function testCopyReplaceRemovesExistingAndTheirImages(): void
    {
        $this->svc->create(1, ['author_name' => 'EN one', 'text' => 'T']);
        $old = $this->svc->create(2, ['author_name' => 'SI old', 'text' => 'T']);
        $names = $this->imageStorage->store(ImageFixtures::png(sys_get_temp_dir()), 'png');
        $this->imageRepo->insert($old['id'], [
            'filename' => $names['filename'], 'thumb_filename' => $names['thumb_filename'],
            'mime' => 'image/png', 'size_bytes' => 100, 'width' => 10, 'height' => 10,
        ], null);

        $this->svc->copy(2, 1, 'replace');

        self::assertFileDoesNotExist($this->imageStorage->path($names['filename']));
        $res = $this->svc->listForLanding(2);
        self::assertSame(['EN one'], array_column($res['data'], 'author_name'));
    }

    public function testCopyRejectsSameLandingAndBadMode(): void
    {
        try {
            $this->svc->copy(1, 1, 'append');
            self::fail('expected ValidationException');
        } catch (ValidationException $e) {
            self::assertArrayHasKey('source_landing_id', $e->getFields());
        }
        try {
            $this->svc->copy(2, 1, 'overwrite');
            self::fail('expected ValidationException');
        } catch (ValidationException $e) {
            self::assertArrayHasKey('mode', $e->getFields());
        }
    }
```

`tests/Api/TestimonialsTest.php` — add (uses the seeded `abforge` product's EN master `61763`; pick any other landing of the same product from `GET /api/products/abforge/landings` for the target):
```php
    public function testCopyPreviewAndCopy(): void
    {
        $landings = $this->request('GET', '/api/products/abforge/landings')['json']['data'];
        $target = array_values(array_filter($landings, fn ($l) => !$l['is_master']))[0];

        $preview = $this->request('GET', "/api/landings/{$target['id']}/testimonials/copy-preview?source_landing_id=61763&mode=append");
        self::assertSame(200, $preview['status']);
        self::assertSame('EN', $preview['json']['source']['country']);

        $r = $this->request('POST', "/api/landings/{$target['id']}/testimonials/copy", ['source_landing_id' => 61763, 'mode' => 'append']);
        self::assertSame(200, $r['status']);
        self::assertGreaterThan(0, count($r['json']['data']));
    }
```

- [ ] **Step 6: Run — expect failures**

`make integration`, then `make api` once wired.

- [ ] **Step 7: Implement `TestimonialService::copyPreview`/`copy` and wire the controller/route**

`TestimonialService` — add:
```php
    /** @return array{source: array{id:int,country:string,title:string}, mode:string, will_add:int, will_remove:int, items: list<array{author_name:string,text:string}>} */
    public function copyPreview(int $targetLandingId, int $sourceLandingId, string $mode): array
    {
        [, $source] = $this->copySources($targetLandingId, $sourceLandingId, $mode);
        $sourceRows = $this->testimonials->listByLanding($sourceLandingId);
        return [
            'source' => ['id' => (int) $source['id'], 'country' => (string) $source['country'], 'title' => (string) $source['title']],
            'mode' => $mode,
            'will_add' => count($sourceRows),
            'will_remove' => $mode === 'replace' ? $this->testimonials->countByLanding($targetLandingId) : 0,
            'items' => array_map(fn (array $r) => ['author_name' => $r['author_name'], 'text' => $r['text']], $sourceRows),
        ];
    }

    /** @return array<string,mixed> */
    public function copy(int $targetLandingId, int $sourceLandingId, string $mode): array
    {
        $this->copySources($targetLandingId, $sourceLandingId, $mode);
        if ($mode === 'replace') {
            $existing = $this->testimonials->listByLanding($targetLandingId);
            $images = $this->images->listByTestimonialIds(array_column($existing, 'id'));
            foreach ($existing as $row) {
                foreach ($images[$row['id']] ?? [] as $image) {
                    $this->imageStorage->delete($image['filename'], $image['thumb_filename']);
                }
            }
        }
        $sourceRows = $this->testimonials->listByLanding($sourceLandingId);
        $this->testimonials->replaceOrAppend($targetLandingId, $sourceRows, $mode === 'replace', $this->user->id());
        return $this->listForLanding($targetLandingId);
    }

    /** @return array{0: array<string,mixed>, 1: array<string,mixed>} [$target, $source] */
    private function copySources(int $targetLandingId, int $sourceLandingId, string $mode): array
    {
        if (!in_array($mode, ['replace', 'append'], true)) {
            throw new ValidationException(['mode' => 'Must be "replace" or "append"']);
        }
        if ($sourceLandingId === $targetLandingId) {
            throw new ValidationException(['source_landing_id' => 'Source and target must be different landings']);
        }
        $target = $this->activeLanding($targetLandingId);
        $source = $this->landings->find($sourceLandingId);
        if ($source === null || $source['removed_at'] !== null) {
            throw new NotFoundException("Landing $sourceLandingId not found");
        }
        if ((int) $source['product_id'] !== (int) $target['product_id']) {
            throw new ValidationException(['source_landing_id' => 'Source must belong to the same product']);
        }
        return [$target, $source];
    }
```

`TestimonialController` — add:
```php
    public function copyPreview(Request $request): Response
    {
        return Response::json($this->service->copyPreview(
            self::id($request, 'id'),
            (int) $request->query('source_landing_id', 0),
            (string) $request->query('mode', 'append'),
        ));
    }

    public function copy(Request $request): Response
    {
        return Response::json($this->service->copy(
            self::id($request, 'id'),
            (int) $request->input('source_landing_id', 0),
            (string) $request->input('mode', 'append'),
        ));
    }
```

`config/routes.php` — add:
```php
    $r->get('/api/landings/{id}/testimonials/copy-preview', [TestimonialController::class, 'copyPreview']);
    $r->post('/api/landings/{id}/testimonials/copy', [TestimonialController::class, 'copy']);
```

- [ ] **Step 8: Green + commit**

```bash
make unit && make integration && make lint && make stan && make up && make api
git add -A && git commit -m "feat(copy): copy-preview and copy endpoints for testimonials between landings"
```

### Task 6: `CopyTestimonials` component and wiring; merge phase 12

**Files:**
- Create: `public/assets/js/components/copyTestimonials.js`
- Modify: `public/assets/js/views/testimonials.js`, `public/index.html`, `docs/time-log.md`

**Interfaces:**
- `CopyTestimonials.open({landingId, sku, onCopied})` — fetches `GET /api/products/{sku}/landings` for the source picker (excluding the current landing), lets the user pick replace/append, shows a live preview via `GET /api/landings/{id}/testimonials/copy-preview`, and on confirm calls `POST /api/landings/{id}/testimonials/copy`.

- [ ] **Step 1: Create the component**

`public/assets/js/components/copyTestimonials.js`:
```js
(function () {
  'use strict';
  let el, modal;

  function ensure() {
    if (el) return;
    el = document.createElement('div');
    el.className = 'modal fade';
    el.id = 'copy-modal';
    el.tabIndex = -1;
    el.innerHTML = `<div class="modal-dialog modal-dialog-centered"><div class="modal-content">
      <div class="modal-header"><h5 class="modal-title">Copy testimonials</h5><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button></div>
      <div class="modal-body">
        <div class="mb-3"><label class="form-label" for="cp-source">Copy from</label><select class="form-select" id="cp-source"></select></div>
        <div class="mb-3">
          <div class="form-check"><input class="form-check-input" type="radio" name="cp-mode" id="cp-append" value="append" checked><label class="form-check-label" for="cp-append">Append — add to the existing testimonials</label></div>
          <div class="form-check"><input class="form-check-input" type="radio" name="cp-mode" id="cp-replace" value="replace"><label class="form-check-label" for="cp-replace">Replace — remove the existing testimonials first</label></div>
        </div>
        <div id="cp-preview" class="small text-muted"></div>
      </div>
      <div class="modal-footer"><button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button><button type="button" class="btn btn-primary" id="cp-go" disabled>Copy</button></div>
    </div></div>`;
    document.body.appendChild(el);
    modal = new bootstrap.Modal(el);
  }

  window.CopyTestimonials = {
    async open({ landingId, sku, onCopied }) {
      ensure();
      const select = el.querySelector('#cp-source');
      const preview = el.querySelector('#cp-preview');
      const go = el.querySelector('#cp-go');
      select.innerHTML = '<option>Loading…</option>';
      preview.textContent = '';
      go.disabled = true;
      modal.show();

      let landings;
      try {
        landings = (await Api.get(`/api/products/${encodeURIComponent(sku)}/landings`)).data.filter((l) => l.id !== landingId);
      } catch (err) {
        select.innerHTML = '';
        preview.textContent = 'Could not load landings: ' + err.message;
        return;
      }
      if (!landings.length) {
        select.innerHTML = '';
        preview.textContent = 'No other landings for this product yet.';
        return;
      }
      select.innerHTML = landings.map((l) => `<option value="${l.id}">${esc(l.country)} — ${esc(l.title)} (${l.testimonial_count})</option>`).join('');

      async function refreshPreview() {
        const mode = el.querySelector('input[name="cp-mode"]:checked').value;
        preview.textContent = 'Loading preview…';
        go.disabled = true;
        try {
          const p = await Api.get(`/api/landings/${landingId}/testimonials/copy-preview?source_landing_id=${select.value}&mode=${mode}`);
          preview.innerHTML = mode === 'replace'
            ? `Will remove <strong>${p.will_remove}</strong> existing and add <strong>${p.will_add}</strong> from ${esc(p.source.country)}.`
            : `Will add <strong>${p.will_add}</strong> testimonials from ${esc(p.source.country)}.`;
          go.disabled = p.will_add === 0;
        } catch (err) {
          preview.textContent = 'Could not preview: ' + err.message;
        }
      }
      select.onchange = refreshPreview;
      el.querySelectorAll('input[name="cp-mode"]').forEach((r) => { r.onchange = refreshPreview; });
      refreshPreview();

      go.onclick = async () => {
        go.disabled = true;
        const mode = el.querySelector('input[name="cp-mode"]:checked').value;
        try {
          await Api.post(`/api/landings/${landingId}/testimonials/copy`, { source_landing_id: parseInt(select.value, 10), mode });
          Toast.success('Testimonials copied');
          modal.hide();
          onCopied();
        } catch (err) {
          Toast.error('Could not copy: ' + err.message);
          go.disabled = false;
        }
      };
    },
  };
})();
```

- [ ] **Step 2: Wire a "Copy…" button into the testimonials view**

In `public/assets/js/views/testimonials.js`, the header button row currently has only `#add-testimonial`. Add a second button right after it in the template:
```html
<button class="btn btn-primary" id="add-testimonial"><i class="bi bi-plus-lg me-1"></i>Add testimonial</button>
<button class="btn btn-outline-primary" id="copy-testimonials"><i class="bi bi-files me-1"></i>Copy…</button>
```
And after the existing `document.getElementById('add-testimonial').addEventListener(...)` line:
```js
    document.getElementById('copy-testimonials').addEventListener('click', () =>
      CopyTestimonials.open({ landingId, sku, onCopied: reload }));
```
(`sku` is already computed earlier in this function from `L.url`'s last path segment — reuse it, no new derivation needed.)

- [ ] **Step 3: Load the new script**

`public/index.html` — add `<script src="assets/js/components/copyTestimonials.js"></script>` after `components/confirm.js` and before `components/testimonialForm.js`.

- [ ] **Step 4: Verify, commit, merge phase 12**

`make up && make seed`; open a country landing that inherits from EN, click "Copy…", pick the EN master with "Append", preview shows the right counts, confirm → the landing now owns real rows (inheritance banner disappears on reload). Try "Replace" on a landing that already has its own testimonials → preview shows a non-zero `will_remove`.
```bash
make unit && make lint && make stan
git add -A && git commit -m "feat(copy): CopyTestimonials modal wired into the testimonials view"
```
Time-log row `| 12 | Copy testimonials between countries (replace/append, preview) | 2.5h |`, push, PR `Phase 12: copy between countries`, wait for CI, merge.

---

## Phase 13 — Bulk actions

### Task 7: Bulk activate/deactivate/delete endpoint

**Files:**
- Modify: `src/Infrastructure/Repository/TestimonialRepository.php`, `src/Application/TestimonialService.php`, `src/Http/Controller/TestimonialController.php`, `config/routes.php`
- Test: `tests/Integration/Repository/TestimonialRepositoryTest.php`, `tests/Integration/Application/TestimonialServiceTest.php`, `tests/Api/TestimonialsTest.php`

**Interfaces:**
- `TestimonialRepository::bulkSetActive(list<int> $ids, bool $active, ?int $userId): int` (rows affected); `bulkDelete(list<int> $ids): int`.
- `TestimonialService::BULK_ACTIONS = ['activate','deactivate','delete']`; `bulkUpdate(int $landingId, list<int> $ids, string $action): array` — 422 (`fields.action`) for an unknown action; ids are intersected with the landing's own testimonial ids (ids from elsewhere are silently dropped, not an error, since a stale client-side selection after a concurrent delete shouldn't 4xx); 422 (`fields.ids`) if nothing remains after the intersection; `delete` cleans up image files first (same pattern as the single-delete path); returns the same shape as `listForLanding()`.
- Route: `POST /api/landings/{id}/testimonials/bulk` (body `{ids: list<int>, action: string}`).

- [ ] **Step 1: Failing repository test**

Add to `tests/Integration/Repository/TestimonialRepositoryTest.php`:
```php
    public function testBulkSetActiveAndBulkDelete(): void
    {
        $repo = new TestimonialRepository(self::$pdo);
        $p = self::insert('products', ['parent_sku' => 'bulk-sku', 'title' => 'T']);
        $l = self::insert('landings', ['id' => 701, 'product_id' => $p, 'country' => 'EN', 'is_master' => 1, 'url' => 'u', 'title' => 'T', 'last_synced_at' => '2026-01-01 00:00:00']);
        $ids = [
            $repo->insert($l, ['author_name' => 'A', 'text' => 'a', 'rating' => null, 'gender' => 'unisex', 'url' => null, 'is_active' => true, 'sort_order' => null], null),
            $repo->insert($l, ['author_name' => 'B', 'text' => 'b', 'rating' => null, 'gender' => 'unisex', 'url' => null, 'is_active' => true, 'sort_order' => null], null),
            $repo->insert($l, ['author_name' => 'C', 'text' => 'c', 'rating' => null, 'gender' => 'unisex', 'url' => null, 'is_active' => true, 'sort_order' => null], null),
        ];
        self::assertSame(2, $repo->bulkSetActive([$ids[0], $ids[1]], false, 9));
        $rows = $repo->listByLanding($l);
        self::assertSame([false, false, true], array_column($rows, 'is_active'));
        self::assertSame(1, $repo->bulkDelete([$ids[2]]));
        self::assertCount(2, $repo->listByLanding($l));
    }
```

- [ ] **Step 2: Run — expect "method does not exist"**

`make integration`

- [ ] **Step 3: Implement the repository methods**

```php
    /** @param list<int> $ids */
    public function bulkSetActive(array $ids, bool $active, ?int $userId): int
    {
        if ($ids === []) {
            return 0;
        }
        $marks = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $this->pdo->prepare("UPDATE testimonials SET is_active = ?, updated_by = ? WHERE id IN ($marks)");
        $stmt->execute([(int) $active, $userId, ...$ids]);
        return $stmt->rowCount();
    }

    /** @param list<int> $ids */
    public function bulkDelete(array $ids): int
    {
        if ($ids === []) {
            return 0;
        }
        $marks = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $this->pdo->prepare("DELETE FROM testimonials WHERE id IN ($marks)");
        $stmt->execute($ids);
        return $stmt->rowCount();
    }
```

- [ ] **Step 4: Run — repository test passes**

`make integration`

- [ ] **Step 5: Failing service and API tests**

Add to `tests/Integration/Application/TestimonialServiceTest.php`:
```php
    public function testBulkUpdateActivateAndDelete(): void
    {
        $a = $this->svc->create(1, ['author_name' => 'A', 'text' => 'a', 'is_active' => false]);
        $b = $this->svc->create(1, ['author_name' => 'B', 'text' => 'b', 'is_active' => false]);
        $c = $this->svc->create(1, ['author_name' => 'C', 'text' => 'c']);

        $res = $this->svc->bulkUpdate(1, [$a['id'], $b['id']], 'activate');
        $byId = array_column($res['data'], null, 'id');
        self::assertTrue($byId[$a['id']]['is_active']);
        self::assertTrue($byId[$b['id']]['is_active']);

        $res = $this->svc->bulkUpdate(1, [$c['id']], 'delete');
        self::assertCount(2, $res['data']);
    }

    public function testBulkUpdateRejectsUnknownAction(): void
    {
        $t = $this->svc->create(1, ['author_name' => 'A', 'text' => 'a']);
        $this->expectException(ValidationException::class);
        $this->svc->bulkUpdate(1, [$t['id']], 'archive');
    }

    public function testBulkUpdateIgnoresIdsFromOtherLandings(): void
    {
        $mine = $this->svc->create(1, ['author_name' => 'Mine', 'text' => 'a']);
        $other = $this->svc->create(2, ['author_name' => 'Other', 'text' => 'a']);
        $this->svc->bulkUpdate(1, [$mine['id'], $other['id']], 'deactivate');
        self::assertFalse($this->svc->get($mine['id'])['is_active']);
        self::assertTrue($this->svc->get($other['id'])['is_active']);   // untouched
    }
```

`tests/Api/TestimonialsTest.php` — add:
```php
    public function testBulkActivateDeactivateDelete(): void
    {
        $created = [];
        foreach (['Bulk A', 'Bulk B'] as $name) {
            $r = $this->request('POST', '/api/landings/61763/testimonials', ['author_name' => $name, 'text' => 't']);
            $created[] = $r['json']['testimonial']['id'];
        }
        $r = $this->request('POST', '/api/landings/61763/testimonials/bulk', ['ids' => $created, 'action' => 'deactivate']);
        self::assertSame(200, $r['status']);
        $byId = array_column($r['json']['data'], null, 'id');
        self::assertFalse($byId[$created[0]]['is_active']);

        $r = $this->request('POST', '/api/landings/61763/testimonials/bulk', ['ids' => $created, 'action' => 'delete']);
        self::assertSame(200, $r['status']);
        $remainingIds = array_column($r['json']['data'], 'id');
        self::assertEmpty(array_intersect($created, $remainingIds));
    }

    public function testBulkRejectsUnknownAction(): void
    {
        $r = $this->request('POST', '/api/landings/61763/testimonials/bulk', ['ids' => [1], 'action' => 'nope']);
        self::assertSame(422, $r['status']);
    }
```

- [ ] **Step 6: Run — expect failures**

`make integration`, then `make api` once wired.

- [ ] **Step 7: Implement `TestimonialService::bulkUpdate`, controller and route**

`TestimonialService` — add:
```php
    public const BULK_ACTIONS = ['activate', 'deactivate', 'delete'];

    /** @param list<int> $ids @return array<string,mixed> */
    public function bulkUpdate(int $landingId, array $ids, string $action): array
    {
        $this->activeLanding($landingId);
        if (!in_array($action, self::BULK_ACTIONS, true)) {
            throw new ValidationException(['action' => 'Must be one of: ' . implode(', ', self::BULK_ACTIONS)]);
        }
        $owned = array_column($this->testimonials->listByLanding($landingId), 'id');
        $ids = array_values(array_intersect($ids, $owned));
        if ($ids === []) {
            throw new ValidationException(['ids' => 'No matching testimonials for this landing']);
        }
        if ($action === 'delete') {
            $images = $this->images->listByTestimonialIds($ids);
            foreach ($ids as $id) {
                foreach ($images[$id] ?? [] as $image) {
                    $this->imageStorage->delete($image['filename'], $image['thumb_filename']);
                }
            }
            $this->testimonials->bulkDelete($ids);
        } else {
            $this->testimonials->bulkSetActive($ids, $action === 'activate', $this->user->id());
        }
        return $this->listForLanding($landingId);
    }
```

`TestimonialController` — add:
```php
    public function bulk(Request $request): Response
    {
        $raw = $request->input('ids');
        if (!is_array($raw)) {
            throw new ValidationException(['ids' => 'Must be an array of testimonial ids']);
        }
        return Response::json($this->service->bulkUpdate(self::id($request, 'id'), array_map('intval', $raw), (string) $request->input('action', '')));
    }
```

`config/routes.php` — add:
```php
    $r->post('/api/landings/{id}/testimonials/bulk', [TestimonialController::class, 'bulk']);
```

- [ ] **Step 8: Green + commit**

```bash
make unit && make integration && make lint && make stan && make up && make api
git add -A && git commit -m "feat(bulk): activate/deactivate/delete multiple testimonials in one call"
```

### Task 8: Bulk-select UI and toolbar; merge phase 13

**Files:**
- Modify: `public/assets/js/views/testimonials.js`, `public/assets/css/app.css`, `docs/time-log.md`

- [ ] **Step 1: Add checkboxes to the table**

In `public/assets/js/views/testimonials.js`'s `row()` function, add a checkbox cell right after the drag-handle cell (before the `#` cell):
```js
        <td class="tm-drag-handle text-muted" title="${inherited ? '' : 'Drag to reorder'}">${inherited ? '' : '<i class="bi bi-grip-vertical"></i>'}</td>
        <td><input type="checkbox" class="form-check-input tm-select" ${inherited ? 'disabled' : ''} aria-label="Select"></td>
        <td class="text-muted tabular">${t.sort_order}</td>
```
`<thead>` gains a matching header checkbox before `<th></th><th>#</th>`:
```html
<thead><tr><th></th><th><input type="checkbox" class="form-check-input" id="tm-select-all" aria-label="Select all"></th><th>#</th><th>Author</th><th>Text</th><th>Rating</th><th>Images</th><th>Active</th><th></th></tr></thead>
```
Bump the empty-state row's `colspan` from `8` to `9`.

- [ ] **Step 2: Add the bulk toolbar placeholder**

Right after the header button row (`#add-testimonial` / `#copy-testimonials`) and before the inheritance banner, add:
```html
<div id="bulk-toolbar" class="alert alert-secondary d-none d-flex align-items-center gap-2 py-2 mb-3"></div>
```

- [ ] **Step 3: Wire selection and the toolbar**

Add this function at module scope (above `window.Views.testimonials`), and call it from within the view after rendering:
```js
  function wireBulkToolbar(landingId, reload) {
    function updateToolbar() {
      const checked = [...document.querySelectorAll('.tm-select:checked')];
      const toolbar = document.getElementById('bulk-toolbar');
      if (!checked.length) { toolbar.classList.add('d-none'); toolbar.innerHTML = ''; return; }
      toolbar.classList.remove('d-none');
      toolbar.innerHTML = `<strong>${checked.length}</strong> selected
        <button class="btn btn-sm btn-outline-success" id="bulk-activate">Activate</button>
        <button class="btn btn-sm btn-outline-secondary" id="bulk-deactivate">Deactivate</button>
        <button class="btn btn-sm btn-outline-danger ms-auto" id="bulk-delete">Delete</button>`;
      const ids = () => [...document.querySelectorAll('.tm-select:checked')].map((c) => parseInt(c.closest('tr').dataset.id, 10));
      const run = async (action) => {
        try {
          await Api.post(`/api/landings/${landingId}/testimonials/bulk`, { ids: ids(), action });
          Toast.success('Updated');
          reload();
        } catch (err) {
          Toast.error('Could not update: ' + err.message);
        }
      };
      document.getElementById('bulk-activate').addEventListener('click', () => run('activate'));
      document.getElementById('bulk-deactivate').addEventListener('click', () => run('deactivate'));
      document.getElementById('bulk-delete').addEventListener('click', async () => {
        const ok = await Confirm.ask({ title: 'Delete testimonials', body: `Delete ${checked.length} testimonial(s)? This cannot be undone.`, confirmLabel: 'Delete' });
        if (ok) run('delete');
      });
    }
    document.getElementById('testimonials-table').addEventListener('change', (e) => {
      if (e.target.id === 'tm-select-all') {
        document.querySelectorAll('.tm-select:not(:disabled)').forEach((c) => { c.checked = e.target.checked; });
        updateToolbar();
      } else if (e.target.classList.contains('tm-select')) {
        updateToolbar();
      }
    });
  }
```
Call it at the end of `window.Views.testimonials`, alongside the existing `enableReorder(...)` call:
```js
    wireBulkToolbar(landingId, reload);
```

- [ ] **Step 4: CSS**

`app.css` append:
```css
#bulk-toolbar { position: sticky; top: 0; z-index: 5; }
```

- [ ] **Step 5: Verify, commit, merge phase 13**

`make up && make seed`; open a landing's testimonials, check 2 rows → toolbar appears with the count; Activate/Deactivate update in place; Delete asks to confirm then removes them.
```bash
make unit && make lint && make stan
git add -A && git commit -m "feat(bulk): row selection and bulk activate/deactivate/delete toolbar"
```
Time-log row `| 13 | Bulk actions (select, activate/deactivate/delete) | 1.5h |`, push, PR `Phase 13: bulk actions`, wait for CI, merge.

---

## Phase 14 — Change log

### Task 9: `ChangeLogRepository`, wiring into testimonial/image mutations, history endpoint

**Files:**
- Create: `src/Infrastructure/Repository/ChangeLogRepository.php`
- Modify: `src/Application/TestimonialService.php`, `src/Application/ImageService.php`, `src/Http/Controller/TestimonialController.php`, `config/container.php`, `config/routes.php`, `tests/Integration/Application/TestimonialServiceTest.php` (constructor call gains the new dependency)
- Test: `tests/Integration/Repository/ChangeLogRepositoryTest.php`, `tests/Api/TestimonialsTest.php`

**Interfaces:**
- `ChangeLogRepository::record(string $entityType, int $entityId, string $action, ?array<string,mixed> $changes, ?int $userId): void`; `listForEntity(string $entityType, int $entityId, int $limit=50): list<array{id:int,action:string,changes:?array<string,mixed>,user_id:?int,user_name:?string,created_at:string}>` (newest first, left-joins `users` for the display name so a deleted user still shows history with `user_name: null`).
- `TestimonialService` gains a `ChangeLogRepository $changeLog` constructor parameter (**last** position — every existing positional arg keeps its place): `create()` logs `action:'created'` with the inserted fields; `update()` logs `action:'updated'` with a `{field: {old,new}}` diff of only the changed keys (nothing logged when nothing actually changed); `delete()` logs `action:'deleted'` (no `changes`) before the row is removed. New method `history(int $id): list<array<string,mixed>>` (404 if the testimonial doesn't exist).
- `ImageService` gains the same `ChangeLogRepository` param (last position): `upload()` logs one `action:'image_added'` entry per stored image (entity is the **testimonial**, not the image, so both a testimonial's and its images' history share one timeline); `delete()` logs `action:'image_removed'` before removing the row.
- Route: `GET /api/testimonials/{id}/history`.

- [ ] **Step 1: Failing repository test**

`tests/Integration/Repository/ChangeLogRepositoryTest.php`:
```php
<?php

declare(strict_types=1);

namespace Tests\Integration\Repository;

use App\Infrastructure\Repository\ChangeLogRepository;
use Tests\Integration\DatabaseTestCase;

final class ChangeLogRepositoryTest extends DatabaseTestCase
{
    public function testRecordAndListForEntityNewestFirstWithUserJoin(): void
    {
        $userId = self::insert('users', ['username' => 'admin', 'password_hash' => 'x', 'display_name' => 'Admin']);
        $repo = new ChangeLogRepository(self::$pdo);

        $repo->record('testimonial', 42, 'created', ['author_name' => 'A'], $userId);
        $repo->record('testimonial', 42, 'updated', ['author_name' => ['old' => 'A', 'new' => 'B']], $userId);
        $repo->record('testimonial', 42, 'deleted', null, null);
        $repo->record('testimonial', 99, 'created', ['author_name' => 'Other'], $userId);   // different entity

        $rows = $repo->listForEntity('testimonial', 42);
        self::assertCount(3, $rows);
        self::assertSame(['deleted', 'updated', 'created'], array_column($rows, 'action'));
        self::assertSame(['author_name' => ['old' => 'A', 'new' => 'B']], $rows[1]['changes']);
        self::assertNull($rows[0]['changes']);
        self::assertSame('Admin', $rows[1]['user_name']);
        self::assertNull($rows[0]['user_name']);   // user_id was null
    }

    public function testLimit(): void
    {
        $repo = new ChangeLogRepository(self::$pdo);
        for ($i = 0; $i < 5; $i++) {
            $repo->record('testimonial', 1, 'updated', null, null);
        }
        self::assertCount(2, $repo->listForEntity('testimonial', 1, 2));
    }
}
```

- [ ] **Step 2: Run — expect class-not-found**

`make integration`

- [ ] **Step 3: Implement `ChangeLogRepository`**

```php
<?php

declare(strict_types=1);

namespace App\Infrastructure\Repository;

final class ChangeLogRepository
{
    public function __construct(private readonly \PDO $pdo)
    {
    }

    /** @param array<string,mixed>|null $changes */
    public function record(string $entityType, int $entityId, string $action, ?array $changes, ?int $userId): void
    {
        $stmt = $this->pdo->prepare('INSERT INTO change_log (entity_type, entity_id, action, changes, user_id) VALUES (?, ?, ?, ?, ?)');
        $stmt->execute([$entityType, $entityId, $action, $changes === null ? null : json_encode($changes, JSON_THROW_ON_ERROR), $userId]);
    }

    /** @return list<array{id:int,action:string,changes:?array<string,mixed>,user_id:?int,user_name:?string,created_at:string}> */
    public function listForEntity(string $entityType, int $entityId, int $limit = 50): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT c.*, u.display_name FROM change_log c LEFT JOIN users u ON u.id = c.user_id
             WHERE c.entity_type = ? AND c.entity_id = ? ORDER BY c.created_at DESC, c.id DESC LIMIT ?',
        );
        $stmt->bindValue(1, $entityType);
        $stmt->bindValue(2, $entityId, \PDO::PARAM_INT);
        $stmt->bindValue(3, $limit, \PDO::PARAM_INT);
        $stmt->execute();
        return array_map(static function (array $r): array {
            return [
                'id' => (int) $r['id'], 'action' => (string) $r['action'],
                'changes' => $r['changes'] === null ? null : json_decode((string) $r['changes'], true),
                'user_id' => $r['user_id'] === null ? null : (int) $r['user_id'],
                'user_name' => $r['display_name'] === null ? null : (string) $r['display_name'],
                'created_at' => (string) $r['created_at'],
            ];
        }, $stmt->fetchAll());
    }
}
```

- [ ] **Step 4: Run — repository test passes**

`make integration`

- [ ] **Step 5: Failing API test for the history endpoint**

`tests/Api/TestimonialsTest.php` — add:
```php
    public function testHistoryRecordsCreateUpdateAndImageEvents(): void
    {
        $t = $this->request('POST', '/api/landings/61763/testimonials', ['author_name' => 'Hist', 'text' => 'orig'])['json']['testimonial'];
        $this->request('PATCH', "/api/testimonials/{$t['id']}", ['text' => 'changed']);

        $r = $this->request('GET', "/api/testimonials/{$t['id']}/history");
        self::assertSame(200, $r['status']);
        $actions = array_column($r['json']['data'], 'action');
        self::assertSame(['updated', 'created'], $actions);   // newest first
        $updated = $r['json']['data'][0];
        self::assertSame(['old' => 'orig', 'new' => 'changed'], $updated['changes']['text']);
        self::assertSame('Demo Admin', $updated['user_name']);

        $this->request('DELETE', "/api/testimonials/{$t['id']}");
    }

    public function testHistoryOnMissingTestimonialIs404(): void
    {
        self::assertSame(404, $this->request('GET', '/api/testimonials/999999999/history')['status']);
    }
```

- [ ] **Step 6: Run — expect failures**

`make integration`, then `make api` once wired.

- [ ] **Step 7: Wire `ChangeLogRepository` into `TestimonialService`, `ImageService`, container and routes**

`TestimonialService` — add `use App\Infrastructure\Repository\ChangeLogRepository;` to imports and add the new constructor param **at the end**:
```php
    public function __construct(
        private readonly TestimonialRepository $testimonials,
        private readonly LandingRepository $landings,
        private readonly ImageRepository $images,
        private readonly ImageStorage $imageStorage,
        private readonly TestimonialValidator $validator,
        private readonly RatingResolver $ratings,
        private readonly CurrentUser $user,
        private readonly ChangeLogRepository $changeLog,
    ) {
    }
```
Update `create()` to log after insert:
```php
    public function create(int $landingId, array $input): array
    {
        $this->activeLanding($landingId);
        $fields = $this->validator->validate($input);
        $id = $this->testimonials->insert($landingId, $fields, $this->user->id());
        $this->changeLog->record('testimonial', $id, 'created', $fields, $this->user->id());
        return $this->get($id);
    }
```
Update `update()` to capture the before-row and log a diff:
```php
    public function update(int $id, array $input): array
    {
        $before = $this->existing($id);
        $fields = $this->validator->validate($input, true);
        $this->testimonials->update($id, $fields, $this->user->id());
        $diff = [];
        foreach ($fields as $key => $value) {
            if ($before[$key] !== $value) {
                $diff[$key] = ['old' => $before[$key], 'new' => $value];
            }
        }
        if ($diff !== []) {
            $this->changeLog->record('testimonial', $id, 'updated', $diff, $this->user->id());
        }
        return $this->get($id);
    }
```
Update `delete()` to log before removing:
```php
    public function delete(int $id): void
    {
        $this->existing($id);
        $images = $this->images->listByTestimonialIds([$id])[$id] ?? [];
        foreach ($images as $image) {
            $this->imageStorage->delete($image['filename'], $image['thumb_filename']);
        }
        $this->changeLog->record('testimonial', $id, 'deleted', null, $this->user->id());
        $this->testimonials->delete($id);
    }
```
Add the `history()` method:
```php
    /** @return list<array<string,mixed>> */
    public function history(int $id): array
    {
        $this->existing($id);
        return $this->changeLog->listForEntity('testimonial', $id);
    }
```

`ImageService` — add `use App\Infrastructure\Repository\ChangeLogRepository;`, the constructor param at the end:
```php
    public function __construct(
        private readonly ImageRepository $images,
        private readonly TestimonialRepository $testimonials,
        private readonly ImageValidator $validator,
        private readonly ImageStorage $storage,
        private readonly CurrentUser $user,
        private readonly ChangeLogRepository $changeLog,
    ) {
    }
```
Update `upload()` to log per stored image:
```php
        foreach ($checked as $c) {
            $names = $this->storage->store($c['tmp_path'], $c['ext']);
            $id = $this->images->insert($testimonialId, [
                'filename' => $names['filename'], 'thumb_filename' => $names['thumb_filename'], 'mime' => $c['mime'],
                'size_bytes' => $c['size'], 'width' => $c['width'], 'height' => $c['height'],
            ], $this->user->id());
            $row = $this->images->find($id);
            if ($row !== null) {
                $stored[] = $row;
                $this->changeLog->record('testimonial', $testimonialId, 'image_added', ['filename' => $names['filename']], $this->user->id());
            }
        }
```
Update `delete()` to log before removing:
```php
    public function delete(int $imageId): void
    {
        $row = $this->images->find($imageId);
        if ($row === null) {
            throw new NotFoundException("Image $imageId not found");
        }
        $this->changeLog->record('testimonial', $row['testimonial_id'], 'image_removed', ['filename' => $row['filename']], $this->user->id());
        $this->images->delete($imageId);
        $this->storage->delete($row['filename'], $row['thumb_filename']);
    }
```

`TestimonialController` — add:
```php
    public function history(Request $request): Response
    {
        return Response::json(['data' => $this->service->history(self::id($request, 'id'))]);
    }
```

`config/routes.php` — add:
```php
    $r->get('/api/testimonials/{id}/history', [TestimonialController::class, 'history']);
```

`config/container.php` — add `use App\Infrastructure\Repository\ChangeLogRepository;`, a binding, and append the new arg to both existing factories:
```php
    $c->set(ChangeLogRepository::class, fn (Container $c) => new ChangeLogRepository($c->get(PDO::class)));
```
```php
    $c->set(TestimonialService::class, fn (Container $c) => new TestimonialService(
        $c->get(TestimonialRepository::class),
        $c->get(LandingRepository::class),
        $c->get(ImageRepository::class),
        $c->get(ImageStorage::class),
        $c->get(TestimonialValidator::class),
        $c->get(RatingResolver::class),
        $c->get(CurrentUser::class),
        $c->get(ChangeLogRepository::class),
    ));
```
```php
    $c->set(ImageService::class, fn (Container $c) => new ImageService(
        $c->get(ImageRepository::class),
        $c->get(TestimonialRepository::class),
        $c->get(ImageValidator::class),
        $c->get(ImageStorage::class),
        $c->get(CurrentUser::class),
        $c->get(ChangeLogRepository::class),
    ));
```

- [ ] **Step 8: Fix the one test that constructs `TestimonialService` directly**

`tests/Integration/Application/TestimonialServiceTest.php` — add `use App\Infrastructure\Repository\ChangeLogRepository;` and, in `setUp()`, append `new ChangeLogRepository(self::$pdo)` as the last constructor arg to the existing `$this->svc = new TestimonialService(...)` call.

- [ ] **Step 9: Green + commit**

```bash
make unit && make integration && make lint && make stan && make up && make api
git add -A && git commit -m "feat(history): ChangeLogRepository, audit trail on create/update/delete/images, GET .../history"
```

### Task 10: `HistoryPanel` component and a "History" button in the form; merge phase 14

**Files:**
- Create: `public/assets/js/components/historyPanel.js`
- Modify: `public/assets/js/components/testimonialForm.js`, `public/index.html`, `docs/time-log.md`

**Interfaces:**
- `HistoryPanel.open(testimonialId)` — fetches `GET /api/testimonials/{id}/history` and renders it in a modal.

- [ ] **Step 1: Create the component**

`public/assets/js/components/historyPanel.js`:
```js
(function () {
  'use strict';
  let el, modal;

  function ensure() {
    if (el) return;
    el = document.createElement('div');
    el.className = 'modal fade';
    el.id = 'history-modal';
    el.tabIndex = -1;
    el.innerHTML = `<div class="modal-dialog modal-dialog-scrollable"><div class="modal-content">
      <div class="modal-header"><h5 class="modal-title">History</h5><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button></div>
      <div class="modal-body"><ul class="list-group list-group-flush" id="history-list"></ul></div>
    </div></div>`;
    document.body.appendChild(el);
    modal = new bootstrap.Modal(el);
  }

  const VERBS = { created: 'created', updated: 'updated', deleted: 'deleted', image_added: 'added an image', image_removed: 'removed an image' };

  function describe(entry) {
    const who = entry.user_name || 'System';
    const when = new Date(entry.created_at.replace(' ', 'T') + 'Z').toLocaleString();
    let detail = '';
    if (entry.action === 'updated' && entry.changes) {
      detail = Object.entries(entry.changes).map(([k, v]) => `${esc(k)}: “${esc(String(v.old))}” → “${esc(String(v.new))}”`).join('<br>');
    }
    return `<li class="list-group-item"><div class="d-flex justify-content-between"><strong>${esc(who)} ${esc(VERBS[entry.action] || entry.action)}</strong><small class="text-muted">${esc(when)}</small></div>${detail ? `<div class="small text-muted mt-1">${detail}</div>` : ''}</li>`;
  }

  window.HistoryPanel = {
    async open(testimonialId) {
      ensure();
      const list = el.querySelector('#history-list');
      list.innerHTML = '<li class="list-group-item text-muted">Loading…</li>';
      modal.show();
      try {
        const res = await Api.get(`/api/testimonials/${testimonialId}/history`);
        list.innerHTML = res.data.length ? res.data.map(describe).join('') : '<li class="list-group-item text-muted">No history yet.</li>';
      } catch (err) {
        list.innerHTML = `<li class="list-group-item text-danger">Could not load history: ${esc(err.message)}</li>`;
      }
    },
  };
})();
```

- [ ] **Step 2: Add a "History" button to the form footer**

In `public/assets/js/components/testimonialForm.js`'s `ensure()` template, the modal footer currently has only Cancel/Save. Add a History button before Cancel:
```html
<div class="modal-footer justify-content-between">
  <span id="tf-status" class="tm-save-status"></span>
  <div><button type="button" class="btn btn-outline-secondary" id="tf-history">History</button> <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button> <button type="submit" class="btn btn-primary" id="tf-save">Save</button></div>
</div>
```
In `window.TestimonialForm.open(...)`, right after `el.querySelector('.modal-title').textContent = …`, wire the button (it's static markup so re-set `onclick`/`disabled` on every open, matching how `.modal-title` is refreshed each time):
```js
      const historyBtn = el.querySelector('#tf-history');
      historyBtn.disabled = !testimonial;
      historyBtn.onclick = testimonial ? () => HistoryPanel.open(testimonial.id) : null;
```

- [ ] **Step 3: Load the new script**

`public/index.html` — add `<script src="assets/js/components/historyPanel.js"></script>` before `components/testimonialForm.js` (testimonialForm references the `HistoryPanel` global).

- [ ] **Step 4: Verify, commit, merge phase 14**

`make up && make seed`; edit an existing testimonial, click "History" → shows at least the "created" entry; change a field and Save, reopen the same testimonial's History → shows the new "updated" entry with the before/after diff; upload an image → History shows "added an image". "History" is disabled when creating a brand-new (unsaved) testimonial.
```bash
make unit && make lint && make stan
git add -A && git commit -m "feat(history): HistoryPanel component and History button in the testimonial form"
```
Time-log row `| 14 | Change log (audit trail, per-record history panel) | 2h |`, push, PR `Phase 14: change log`, wait for CI, merge.

---

## Phase 15 — Image processing

### Task 11: Downscale, opt-in WebP conversion, opt-in square crop

**Files:**
- Modify: `src/Infrastructure/Storage/ImageStorage.php`, `src/Application/ImageService.php`, `src/Http/Controller/ImageController.php`
- Test: `tests/Unit/Infrastructure/Storage/ImageStorageTest.php`, `tests/Api/ImagesTest.php`

**Interfaces:**
- `ImageStorage::store(string $sourcePath, string $ext, ?string $crop=null, bool $convertWebp=false): array{filename:string,thumb_filename:string,mime:string,width:int,height:int}` — **backward compatible**: called with 2 args (as every existing call site does), behavior is unchanged except the main (non-thumbnail) image is now also downscaled to at most 1600px on its longest side (never upscaled — a no-op for every existing test fixture, all ≤1200px). `$crop='square'` center-crops before scaling; `$crop=null` (default) leaves the aspect ratio untouched. `$convertWebp=true` re-encodes both the main image and thumbnail as WebP regardless of the source format (filename/thumb_filename extension becomes `.webp`, returned `mime` becomes `image/webp`); `false` (default) keeps the original format exactly as today.
- `ImageService::upload(int $testimonialId, list<array{...}> $files, ?string $crop=null, bool $convertWebp=false): list<ImgRow>` — passes `$crop`/`$convertWebp` through to `ImageStorage::store()`; the DB row's `mime`/`width`/`height`/`size_bytes` now reflect the **final stored** image (post-crop/scale/convert), not the original upload.
- `POST /api/testimonials/{id}/images` accepts two new optional multipart fields: `crop` (`"square"` or absent) and `convert_webp` (`"1"`/`"true"` or absent).

- [ ] **Step 1: Failing unit tests**

Add to `tests/Unit/Infrastructure/Storage/ImageStorageTest.php` (the 5 existing tests in this file must keep passing unmodified — these are new tests only):
```php
    public function testDownscalesLargeMainImageButNeverUpscales(): void
    {
        $storage = new ImageStorage($this->dir, 300);
        $big = $storage->store(ImageFixtures::jpeg(sys_get_temp_dir(), 2000, 1000), 'jpg');
        self::assertSame([1600, 800], $this->dimensions($storage->path($big['filename'])));
        self::assertSame(1600, $big['width']);
        self::assertSame(800, $big['height']);

        $small = $storage->store(ImageFixtures::jpeg(sys_get_temp_dir(), 640, 480), 'jpg');
        self::assertSame([640, 480], $this->dimensions($storage->path($small['filename'])));
    }

    public function testConvertsToWebpWhenRequested(): void
    {
        $storage = new ImageStorage($this->dir, 300);
        $r = $storage->store(ImageFixtures::png(sys_get_temp_dir(), 640, 480), 'png', null, true);
        self::assertStringEndsWith('.webp', $r['filename']);
        self::assertStringEndsWith('.webp', $r['thumb_filename']);
        self::assertSame('image/webp', $r['mime']);
        self::assertSame('image/webp', mime_content_type($storage->path($r['filename'])));
        self::assertSame('image/webp', mime_content_type($storage->path($r['thumb_filename'])));
    }

    public function testCropsToSquareWhenRequested(): void
    {
        $storage = new ImageStorage($this->dir, 300);
        $r = $storage->store(ImageFixtures::jpeg(sys_get_temp_dir(), 800, 400), 'jpg', 'square');
        self::assertSame(400, $r['width']);
        self::assertSame(400, $r['height']);
        self::assertSame([400, 400], $this->dimensions($storage->path($r['filename'])));
    }

    public function testDefaultCallSignatureIsUnchanged(): void
    {
        // The exact call every existing production and test call site makes — must keep working.
        $storage = new ImageStorage($this->dir, 300);
        $r = $storage->store(ImageFixtures::png(sys_get_temp_dir()), 'png');
        self::assertStringEndsWith('.png', $r['filename']);
        self::assertSame('image/png', $r['mime']);
    }
```

- [ ] **Step 2: Run — expect failures (new optional params/return keys don't exist yet)**

`make unit`

- [ ] **Step 3: Rewrite `ImageStorage`**

Replace the whole class body (keep the existing `SAFE`/`MIMES` constants, `delete()`, `path()`, `isSafeFilename()`, `mimeFor()`, `uuid4()` unchanged; replace `store()` and `writeThumbnail()`):
```php
<?php

declare(strict_types=1);

namespace App\Infrastructure\Storage;

/**
 * Files live outside the web root under generated UUID names; the main image is downscaled to a
 * sane maximum and a thumbnail is made with GD. Conversion to WebP and square cropping are opt-in
 * per upload — the default call (2 args) behaves exactly as before, only larger main images shrink.
 */
final class ImageStorage
{
    private const SAFE = '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}(_thumb)?\.(jpg|png|webp)$/';
    private const MIMES = ['jpg' => 'image/jpeg', 'png' => 'image/png', 'webp' => 'image/webp'];
    private const MAX_MAIN = 1600;
    private const MAIN_QUALITY = ['jpg' => 88, 'png' => 6, 'webp' => 85];
    private const THUMB_QUALITY = ['jpg' => 85, 'png' => 6, 'webp' => 82];

    public function __construct(private readonly string $dir, private readonly int $thumbSize = 300)
    {
    }

    /** @return array{filename:string,thumb_filename:string,mime:string,width:int,height:int} */
    public function store(string $sourcePath, string $ext, ?string $crop = null, bool $convertWebp = false): array
    {
        if (!isset(self::MIMES[$ext])) {
            throw new \InvalidArgumentException("Unsupported extension '$ext'");
        }
        if ($crop !== null && $crop !== 'square') {
            throw new \InvalidArgumentException("Unsupported crop mode '$crop'");
        }
        if (!is_dir($this->dir) && !mkdir($this->dir, 0775, true) && !is_dir($this->dir)) {
            throw new \RuntimeException("Upload directory '{$this->dir}' is not writable");
        }

        $image = $this->decode($sourcePath, $ext);
        @unlink($sourcePath);

        if ($crop === 'square') {
            $side = min(imagesx($image), imagesy($image));
            $cropped = imagecrop($image, [
                'x' => intdiv(imagesx($image) - $side, 2), 'y' => intdiv(imagesy($image) - $side, 2),
                'width' => $side, 'height' => $side,
            ]);
            if ($cropped !== false) {
                $image = $cropped;
            }
        }
        $image = $this->downscale($image, self::MAX_MAIN);

        $finalExt = $convertWebp ? 'webp' : $ext;
        $uuid = self::uuid4();
        $filename = "$uuid.$finalExt";
        $thumb = "{$uuid}_thumb.$finalExt";
        $this->writeImage($image, $this->path($filename), $finalExt, self::MAIN_QUALITY[$finalExt]);
        $this->writeImage($this->downscale($image, $this->thumbSize), $this->path($thumb), $finalExt, self::THUMB_QUALITY[$finalExt]);

        return [
            'filename' => $filename, 'thumb_filename' => $thumb, 'mime' => self::MIMES[$finalExt],
            'width' => imagesx($image), 'height' => imagesy($image),
        ];
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

    private function decode(string $path, string $ext): \GdImage
    {
        $image = match ($ext) {
            'jpg' => imagecreatefromjpeg($path),
            'png' => imagecreatefrompng($path),
            'webp' => imagecreatefromwebp($path),
        };
        if ($image === false) {
            throw new \RuntimeException('Could not decode image');
        }
        return $image;
    }

    /** Scales down so the longest side is at most $maxSide; never upscales. */
    private function downscale(\GdImage $image, int $maxSide): \GdImage
    {
        $w = imagesx($image);
        $h = imagesy($image);
        $scale = min(1.0, $maxSide / max($w, $h));
        if ($scale >= 1.0) {
            return $image;
        }
        $tw = max(1, (int) round($w * $scale));
        $th = max(1, (int) round($h * $scale));
        $scaled = imagescale($image, $tw, $th, IMG_BICUBIC);
        if ($scaled === false) {
            throw new \RuntimeException('Could not scale image');
        }
        return $scaled;
    }

    /** @param 'jpg'|'png'|'webp' $ext */
    private function writeImage(\GdImage $image, string $target, string $ext, int $quality): bool
    {
        if ($ext === 'png') {
            imagealphablending($image, false);
            imagesavealpha($image, true);
        }
        $ok = match ($ext) {
            'jpg' => imagejpeg($image, $target, $quality),
            'png' => imagepng($image, $target, $quality),
            'webp' => imagewebp($image, $target, $quality),
        };
        if (!$ok) {
            throw new \RuntimeException('Could not write image');
        }
        return $ok;
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

- [ ] **Step 4: Run — unit tests pass, including the 5 pre-existing ones unmodified**

`make unit` → all of `ImageStorageTest` green (9 tests: 5 existing + 4 new).

- [ ] **Step 5: Update `ImageService::upload()` to use the storage's final mime/dimensions/size, and thread the new params through**

```php
    /**
     * @param list<array{name:string,type:string,tmp_name:string,error:int,size:int}> $files
     * @return list<ImgRow>
     */
    public function upload(int $testimonialId, array $files, ?string $crop = null, bool $convertWebp = false): array
    {
        if ($this->testimonials->find($testimonialId) === null) {
            throw new NotFoundException("Testimonial $testimonialId not found");
        }
        if ($files === []) {
            throw new ValidationException(['images' => 'Choose at least one image']);
        }
        $checked = array_map(fn (array $f) => $this->validator->validate($f), $files);
        $stored = [];
        foreach ($checked as $c) {
            $names = $this->storage->store($c['tmp_path'], $c['ext'], $crop, $convertWebp);
            $size = (int) filesize($this->storage->path($names['filename']));
            $id = $this->images->insert($testimonialId, [
                'filename' => $names['filename'], 'thumb_filename' => $names['thumb_filename'], 'mime' => $names['mime'],
                'size_bytes' => $size, 'width' => $names['width'], 'height' => $names['height'],
            ], $this->user->id());
            $row = $this->images->find($id);
            if ($row !== null) {
                $stored[] = $row;
                $this->changeLog->record('testimonial', $testimonialId, 'image_added', ['filename' => $names['filename']], $this->user->id());
            }
        }
        return $stored;
    }
```
(`size_bytes` now reflects the actual on-disk bytes of the stored — possibly re-encoded — file rather than the original upload's byte count; this is more accurate than before, not a behavior change any test asserts on.)

- [ ] **Step 6: `ImageController::store()` reads the two new optional fields**

```php
    public function store(Request $request): Response
    {
        $files = UploadedFiles::normalize($request->files, 'images');
        $crop = $request->input('crop');
        $convertWebp = filter_var($request->input('convert_webp', false), FILTER_VALIDATE_BOOLEAN);
        return Response::json(['images' => $this->service->upload(
            TestimonialController::id($request, 'id'),
            $files,
            $crop === null || $crop === '' ? null : (string) $crop,
            $convertWebp,
        )], 201);
    }
```

- [ ] **Step 7: Failing API test, then green**

Add to `tests/Api/ImagesTest.php`:
```php
    public function testUploadWithConvertWebpAndCrop(): void
    {
        $id = $this->newTestimonial();
        $r = $this->upload("/api/testimonials/$id/images", ['images[0]' => ImageFixtures::jpeg(sys_get_temp_dir(), 800, 400)], ['convert_webp' => '1', 'crop' => 'square']);
        self::assertSame(201, $r['status'], json_encode($r['json']));
        $img = $r['json']['images'][0];
        self::assertStringEndsWith('.webp', $img['filename']);
        self::assertSame(400, $img['width']);
        self::assertSame(400, $img['height']);

        $media = $this->raw('/media/' . $img['filename']);
        self::assertSame('image/webp', $media['content_type']);
        $this->request('DELETE', "/api/testimonials/$id");
    }
```
`make api` → green (the pre-existing `testUploadListServeDelete` test, which asserts `filename` matches `/^[0-9a-f-]{36}\.png$/` with no `convert_webp` field sent, keeps passing since the default is `false`).

- [ ] **Step 8: Green + commit**

```bash
make unit && make integration && make lint && make stan && make up && make api
git add -A && git commit -m "feat(images): downscale to 1600px, opt-in WebP conversion and square crop"
```

### Task 12: "Convert to WebP" / "Crop to square" upload options; merge phase 15

**Files:**
- Modify: `public/assets/js/components/imageUploader.js`, `public/assets/css/app.css`, `docs/time-log.md`

- [ ] **Step 1: Add the two checkboxes to the dropzone**

In `public/assets/js/components/imageUploader.js`'s `mount()` template, right after the `<div class="tm-dropzone" …>` block and before the status line, add:
```html
<div class="d-flex gap-3 mt-1 small">
  <div class="form-check"><input class="form-check-input" type="checkbox" id="iu-webp"><label class="form-check-label" for="iu-webp">Convert to WebP</label></div>
  <div class="form-check"><input class="form-check-input" type="checkbox" id="iu-crop"><label class="form-check-label" for="iu-crop">Crop to square</label></div>
</div>
```

- [ ] **Step 2: Send the flags with the upload**

In `mount()`, after `const status = SaveStatus.bind(...)`, grab the two new checkboxes:
```js
      const webpCheckbox = slot.querySelector('#iu-webp');
      const cropCheckbox = slot.querySelector('#iu-crop');
```
In `send()`, right before `status.saving();`, append the fields to the `FormData` when checked:
```js
        if (webpCheckbox.checked) fd.append('convert_webp', '1');
        if (cropCheckbox.checked) fd.append('crop', 'square');
```

- [ ] **Step 3: CSS (optional polish)**

`app.css` append:
```css
.tm-dropzone + .form-check { margin-right: 1rem; }
```

- [ ] **Step 4: Verify, commit, merge phase 15**

`make up && make seed`; open a testimonial's images, check "Convert to WebP", drop a JPG → thumbnail renders (browser natively displays WebP) and the stored filename ends `.webp` (confirm via the `/api/testimonials/{id}` response or the Task 11 API test); check "Crop to square" alone → uploaded image's thumbnail is square.
```bash
make unit && make lint && make stan
git add -A && git commit -m "feat(images): WebP/crop checkboxes in the uploader"
```
Time-log row `| 15 | Image processing (downscale, WebP conversion, square crop) | 1.5h |`, push, PR `Phase 15: image processing`, wait for CI, merge.

---

## Phase 16 — Release

### Task 13: Release ZIP script

**Files:**
- Create: `scripts/build-zip.sh`, `scripts/zip-exclude.txt`
- Modify: none (`Makefile`'s `zip:` target already calls `./scripts/build-zip.sh` from plan 2 — this task only has to make that script exist)

**Interfaces:**
- `make zip` (or `./scripts/build-zip.sh` directly) produces `testimonials-manager-<timestamp>.zip` at the repo root, containing the full source tree plus a freshly-installed production `vendor/` (so the assignment's stated "no Composer required" claim in the README holds for the ZIP), excluding dev/build artifacts.

- [ ] **Step 1: Exclude list**

`scripts/zip-exclude.txt`:
```
.git
.github
.superpowers
docs/superpowers
node_modules
tests/e2e/node_modules
tests/e2e/playwright-report
tests/e2e/test-results
storage/uploads
vendor
.env
dist
*.zip
.DS_Store
```

- [ ] **Step 2: Build script**

`scripts/build-zip.sh`:
```bash
#!/usr/bin/env bash
set -euo pipefail
ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$ROOT"

VERSION="$(date +%Y%m%d-%H%M%S)"
NAME="testimonials-manager-$VERSION"
DIST="dist/$NAME"

rm -rf dist
mkdir -p "$DIST"

echo "==> Copying source (excluding dev/build artifacts)"
rsync -a --exclude-from=scripts/zip-exclude.txt ./ "$DIST/"

echo "==> Installing production vendor/ inside the app container"
docker compose run --rm -v "$PWD/$DIST:/dist" -w /dist app composer install --no-dev --optimize-autoloader --no-interaction

echo "==> Zipping"
(cd dist && zip -rq "../$NAME.zip" "$NAME")

echo "Built $NAME.zip ($(du -h "$NAME.zip" | cut -f1))"
```
`chmod +x scripts/build-zip.sh`.

- [ ] **Step 3: Verify**

```bash
make up
make zip
unzip -l testimonials-manager-*.zip | head -20
```
Confirm the ZIP contains `vendor/autoload.php`, `public/index.html`, `src/`, `database/`, `README.md`, `deploy/`, and does **not** contain `.git`, `node_modules`, or `storage/uploads/*.jpg` (the seeded demo photos are regenerated by `bin/install.php`/`seed-images.php` on first run, not shipped in the ZIP). Extract to a scratch directory and confirm `php -l public/index.php` (or equivalent smoke check) succeeds without a `composer install` step.

- [ ] **Step 4: Commit**

```bash
git add -A && git commit -m "feat(release): build-zip.sh packages a self-contained release archive with vendor/"
```

### Task 14: Final README pass, submission checklist, time-log rollup; merge phase 16

**Files:**
- Create: `docs/submission-checklist.md`
- Modify: `README.md`, `docs/time-log.md`

- [ ] **Step 1: Consolidate the "Deliberate shortcuts" section**

Read the current `## Deliberate shortcuts` section in `README.md` and make sure it lists every shortcut accumulated across plans 1–3 (add any missing from this list — don't duplicate ones already there):
- SKU is derived from the landing URL's last path segment (works for the current upstream URL shape; brittle if it changes).
- `ImageService::upload()` is not transactional across a multi-file batch (validation-first makes this reachable only on a disk/GD failure mid-batch).
- `ImageRepository::insert()`'s `sort_order` allocation (`SELECT MAX+1` then `INSERT`) isn't atomic under concurrent uploads to the same testimonial.
- No login rate-limiting/throttling; `session.use_strict_mode` isn't set (session-fixation is still covered by `session_regenerate_id()` on login).
- `database/seed-images.php` always writes JPEG bytes regardless of the target filename's extension (safe today because every seeded row's filename ends `.jpg`).
- Copying testimonials between countries (phase 12) copies testimonial fields only, not their images — a copied testimonial starts with zero images.
- Bulk delete (phase 13) is not transactional across the selected ids — a failure partway through can leave a partial delete.
- The AI providers (phase 10) are mocks: `translate()` only tags text with `[CC]`, and all three providers produce byte-identical translations (only their `authorName()` sample pools and `name()` differ) — swapping in real HTTP-backed providers later only touches `AbstractMockProvider`'s subclasses.
- Image "WebP conversion" and "crop to square" (phase 15) are opt-in per upload, not applied retroactively to already-stored images.

- [ ] **Step 2: Submission checklist**

`docs/submission-checklist.md`:
```markdown
# Submission checklist

- [ ] `main` is green in CI (unit, integration, API, e2e, lint, stan)
- [ ] Live demo reachable: https://tm-dfvu.fly.dev/api/health returns `{"status":"ok","db":true}`
- [ ] Live demo login works with the documented demo credentials
- [ ] `docs/time-log.md` has a row for every phase 0–16
- [ ] README "Deliberate shortcuts" section is current (see phase 16, Task 14, Step 1)
- [ ] `README.md` Quick start works on a clean checkout (`cp .env.example .env && make build && make install && make up && make seed`)
- [ ] `make zip` produces a ZIP that runs without a local Composer install (vendor/ is shipped)
- [ ] Repository is public: https://github.com/mitjafortuna/testimonials-manager
- [ ] `LANDINGS_API_KEY` is set to the real DFVU key on the live app (owner-only step, tracked separately — see `docs/questions-for-dfvu.md`/project memory)
- [ ] Assignment contact (kadrovska@dfvu.org) has the repo link and live link
```

- [ ] **Step 3: Time-log rollup**

`docs/time-log.md` — add the phase 16 row and a total row at the bottom:
```
| 16 | Release (ZIP workflow, docs, submission checklist) | 1h |
```
```
| **Total** | | **(sum every row above)** |
```

- [ ] **Step 4: Verify, commit, merge phase 16**

```bash
make unit && make integration && make lint && make stan && make up && make api
git add -A && git commit -m "docs: final shortcuts list, submission checklist, time-log rollup"
```
Push, PR `Phase 16: release`, wait for CI, merge — this is the last phase of plan 3.

---

## Self-review notes

- **Spec coverage:** §8 AI mock providers → Tasks 1–2; §12 phase 10 (AI mocks) → Tasks 1–2; phase 11 (drag & drop) → Tasks 3–4; phase 12 (copy between countries) → Tasks 5–6; phase 13 (bulk actions) → Tasks 7–8; phase 14 (change log) → Tasks 9–10; phase 15 (image processing) → Tasks 11–12; phase 16 (release) → Tasks 13–14. §4's "copy from EN materialises the inherited set into real rows" is satisfied by phase 12 (copying the EN master's own rows into a target landing turns off `inherits_from_master` on next read, since the target then has `testimonial_count > 0`).
- **Placeholder scan:** none — every step has complete code; the two repository-test steps (Task 3, Task 4/`ImageRepositoryTest`) that say "read the file first to match its exact fixture helper names" are explicit about *why* (matching an existing file's established fixture conventions, not a stand-in for missing logic) and give the exact assertions the finished test must make.
- **Type consistency:** `TestimonialService`'s constructor gains exactly one new trailing param (`ChangeLogRepository`) in Task 9, after which Tasks 11 (image processing) touch `ImageService` only — no further constructor changes. `TestimonialController::id()` is reused, never reimplemented, by every new controller method. `ImgRow`/`Row` phpstan types are referenced by name throughout, never redefined. `ProviderRegistry`'s id↔instance map (`'openai'|'gemini'|'claude'`) is defined once in `container.php` and consumed everywhere else by string id, never hardcoded elsewhere.
- **Cross-task interface check:** Task 5 (copy) and Task 7 (bulk) both call `TestimonialRepository::listByLanding()` and `ImageRepository::listByTestimonialIds()` — unchanged existing signatures, no conflict. Task 9 (history) is the only task that changes `TestimonialService`/`ImageService` constructors; it runs after Tasks 3/5/7 (reorder/copy/bulk) have already added their methods to the same two classes, so by the time Task 9's diff lands, `create()`/`update()`/`delete()`/`upload()`'s bodies it patches are the plan-2 originals, not affected by the intervening tasks' additions (which only ever *add* new methods, never touch those four). Task 11 (image processing) modifies `ImageService::upload()`'s body again, after Task 9 already added the `changeLog->record()` call inside it — Task 11's Step 5 code block includes that line so the two edits compose correctly when applied in task order.
- **Known follow-ups for a future plan (not in scope here):** copying testimonials could optionally also copy images (currently explicitly out of scope, documented as a shortcut); `ProviderRegistry` could be extended with a real HTTP-backed provider without touching call sites; bulk actions could gain "bulk copy" once phase 12 and 13 both exist; the change log's `changes` diff format could grow a richer renderer than the simple before/after string in `historyPanel.js`.

