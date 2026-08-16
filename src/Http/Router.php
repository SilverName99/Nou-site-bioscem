<?php

declare(strict_types=1);

namespace App\Http;

final class Router
{
    private array $routes = [];

    public function get(string $pattern, callable|array $handler): void
    {
        $this->addRoute('GET', $pattern, $handler);
    }

    public function post(string $pattern, callable|array $handler): void
    {
        $this->addRoute('POST', $pattern, $handler);
    }

    public function dispatch(string $method, string $uri): void
    {
        $path = parse_url($uri, PHP_URL_PATH);
        if (!is_string($path) || $path === '') {
            $path = '/';
        }
        if ($path !== '/') {
            $path = rtrim($path, '/');
            if ($path === '') {
                $path = '/';
            }
        }
        $methodRoutes = $this->routes[$method] ?? [];

        foreach ($methodRoutes as $route) {
            [$regex, $handler] = $route;
            if (!preg_match($regex, $path, $matches)) {
                continue;
            }

            $params = [];
            foreach ($matches as $key => $value) {
                if (!is_int($key)) {
                    // Adresa vine percent-encodată de browser. Fără decodare,
                    // un slug cu diacritice („kit-vacanță" → „kit-vacan%C8%9B%C4%83")
                    // nu s-ar potrivi niciodată cu cel din baza de date.
                    $decodat = rawurldecode($value);
                    // Octeții nuli nu au ce căuta într-un parametru de rută.
                    $params[$key] = str_replace("\0", '', $decodat);
                }
            }

            if (is_array($handler)) {
                [$class, $action] = $handler;
                $controller = new $class();
                if ($params === []) {
                    $controller->{$action}();
                } else {
                    $controller->{$action}($params);
                }
                return;
            }

            if ($params === []) {
                $handler();
            } else {
                $handler($params);
            }
            return;
        }

        http_response_code(404);
        echo 'Pagina nu a fost găsită.';
    }

    private function addRoute(string $method, string $pattern, callable|array $handler): void
    {
        $regexPattern = preg_replace('#\{([a-zA-Z_][a-zA-Z0-9_]*)\*\}#', '(?P<$1>.+)', $pattern);
        if (!is_string($regexPattern)) {
            $regexPattern = $pattern;
        }
        $regexPattern = preg_replace('#\{([a-zA-Z_][a-zA-Z0-9_]*)\}#', '(?P<$1>[^/]+)', $regexPattern);
        if (!is_string($regexPattern)) {
            $regexPattern = $pattern;
        }
        $regex = '#^' . $regexPattern . '$#';
        $this->routes[$method][] = [$regex, $handler];
    }
}
