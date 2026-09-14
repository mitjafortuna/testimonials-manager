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
