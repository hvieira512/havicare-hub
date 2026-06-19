<?php

namespace Hub\Dashboard;

final class ApiRoute
{
    private string $regex;

    /**
     * @param callable(array<string, string>, mixed): mixed $handler
     */
    public function __construct(
        private string $method,
        private string $pattern,
        private $handler,
    ) {
        $this->regex = $this->compilePattern($pattern);
    }

    public function method(): string
    {
        return $this->method;
    }

    public function pattern(): string
    {
        return $this->pattern;
    }

    public function matches(string $method, string $path): bool
    {
        return $this->method === $method && preg_match($this->regex, $path) === 1;
    }

    /**
     * @return array<string, string>
     */
    public function parameters(string $path): array
    {
        $matches = [];
        if (preg_match($this->regex, $path, $matches) !== 1) {
            return [];
        }

        $parameters = [];
        foreach ($matches as $key => $value) {
            if (!is_string($key)) {
                continue;
            }

            $parameters[$key] = rawurldecode((string)$value);
        }

        return $parameters;
    }

    /**
     * @return callable(array<string, string>, mixed): mixed
     */
    public function handler(): callable
    {
        return $this->handler;
    }

    private function compilePattern(string $pattern): string
    {
        $trimmed = trim($pattern);
        $regex = preg_replace_callback(
            '/\{([a-zA-Z_][a-zA-Z0-9_]*)(?::([^}]+))?\}/',
            static function (array $matches): string {
                $name = $matches[1];
                $constraint = isset($matches[2]) && $matches[2] !== '' ? $matches[2] : '[^/]+';

                return '(?P<' . $name . '>' . $constraint . ')';
            },
            $trimmed
        );

        if (!is_string($regex)) {
            throw new \RuntimeException('Failed to compile API route pattern.');
        }

        return '#^' . $regex . '$#';
    }
}
