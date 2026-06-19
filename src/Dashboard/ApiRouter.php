<?php

namespace Hub\Dashboard;

final class ApiRouter
{
    /**
     * @param list<ApiRoute> $routes
     */
    public function __construct(
        private array $routes,
    ) {
    }

    /**
     * @return array{route: ApiRoute, parameters: array<string, string>}|null
     */
    public function match(string $method, string $path): ?array
    {
        foreach ($this->routes as $route) {
            if (!$route->matches($method, $path)) {
                continue;
            }

            return [
                'route' => $route,
                'parameters' => $route->parameters($path),
            ];
        }

        return null;
    }
}
