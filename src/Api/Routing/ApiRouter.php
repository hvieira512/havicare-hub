<?php

declare(strict_types=1);

namespace Hub\Api\Routing;

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
        $found = $this->matchExact($method, $path);
        if ($found !== null) {
            return $found;
        }

        // Um recurso com GET aceita HEAD: sem rota própria, o HEAD cai na GET e o corpo
        // descarta-se a jusante.
        if ($method === 'HEAD') {
            return $this->matchExact('GET', $path);
        }

        return null;
    }

    /**
     * @return array{route: ApiRoute, parameters: array<string, string>}|null
     */
    private function matchExact(string $method, string $path): ?array
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
