<?php

declare(strict_types=1);

namespace App\Http;

use App\Domain\Exception\HttpException;
use App\Domain\Exception\NotFoundException;

final class Router
{
    /** @var list<array{method:string,regex:string,handler:array{0:string,1:string}}> */
    private array $routes = [];

    /** @param array{0:string,1:string} $handler */
    public function add(string $method, string $pattern, array $handler): void
    {
        $regex = '#^' . preg_replace('#\{([a-zA-Z_][a-zA-Z0-9_]*)\}#', '(?P<$1>[^/]+)', rtrim($pattern, '/')) . '/?$#';
        $this->routes[] = ['method' => strtoupper($method), 'regex' => $regex, 'handler' => $handler];
    }

    /** @param array{0:string,1:string} $handler */
    public function get(string $pattern, array $handler): void
    {
        $this->add('GET', $pattern, $handler);
    }

    /** @param array{0:string,1:string} $handler */
    public function post(string $pattern, array $handler): void
    {
        $this->add('POST', $pattern, $handler);
    }

    /** @param array{0:string,1:string} $handler */
    public function patch(string $pattern, array $handler): void
    {
        $this->add('PATCH', $pattern, $handler);
    }

    /** @param array{0:string,1:string} $handler */
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
