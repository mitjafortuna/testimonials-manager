<?php

declare(strict_types=1);

use App\Application\LandingOverviewService;
use App\Application\LandingSyncService;
use App\Application\ProductSearchService;
use App\Container;
use App\Http\Controller\HealthController;
use App\Http\Controller\HomeController;
use App\Http\Controller\ProductController;
use App\Http\Controller\SyncController;
use App\Http\Kernel;
use App\Http\Middleware\RequireXhrMiddleware;
use App\Http\Router;
use App\Infrastructure\Db\PdoFactory;
use App\Infrastructure\Repository\LandingRepository;
use App\Infrastructure\Repository\ProductRepository;
use App\Infrastructure\Repository\SyncRunRepository;
use App\Infrastructure\Upstream\CurlLandingsApiClient;
use App\Infrastructure\Upstream\CurlTransport;
use App\Infrastructure\Upstream\FixtureLandingsApiClient;
use App\Infrastructure\Upstream\LandingsApiClientInterface;
use App\Support\Clock;
use App\Support\SystemClock;

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

    $c->set(Clock::class, fn () => new SystemClock());
    $c->set(ProductRepository::class, fn (Container $c) => new ProductRepository($c->get(PDO::class)));
    $c->set(LandingRepository::class, fn (Container $c) => new LandingRepository($c->get(PDO::class)));
    $c->set(SyncRunRepository::class, fn (Container $c) => new SyncRunRepository($c->get(PDO::class)));
    $c->set(LandingsApiClientInterface::class, function () use ($config) {
        $fixture = $config['landings_api']['fixture'];
        if ($fixture !== null) {
            $path = str_starts_with($fixture, '/') ? $fixture : $config['root'] . '/' . $fixture;
            return FixtureLandingsApiClient::fromFile($path);
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

    $c->set(ProductSearchService::class, fn (Container $c) => new ProductSearchService($c->get(ProductRepository::class)));
    $c->set(LandingOverviewService::class, fn (Container $c) => new LandingOverviewService($c->get(LandingRepository::class)));
    $c->set(ProductController::class, fn (Container $c) => new ProductController($c->get(ProductSearchService::class), $c->get(LandingOverviewService::class)));

    return $c;
};
