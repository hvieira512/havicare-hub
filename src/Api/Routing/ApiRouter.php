<?php

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

        // O HTTP exige que um recurso que aceita GET aceita HEAD. As rotas são todas GET;
        // um HEAD sem rota própria cai na GET equivalente. O corpo é descartado a jusante.
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
