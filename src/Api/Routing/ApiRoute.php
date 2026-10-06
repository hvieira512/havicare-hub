<?php

declare(strict_types=1);

namespace Hub\Api\Routing;

use Psr\Http\Message\ServerRequestInterface;

final class ApiRoute
{
    /** O corpo chega em JSON. */
    public const JSON_BODY = 'json';

    /** O corpo chega em JSON ou em `multipart/form-data`, porque traz um ficheiro com ele. */
    public const FORM_BODY = 'form';

    private string $regex;

    /**
     * O handler é `fn(array $params, ServerRequestInterface $request)`, e pode declarar menos. O
     * `$body` faz o kernel descodificar e recusar `invalid_json`; o `$status` é o sucesso em cru.
     *
     * @param callable $handler
     */
    public function __construct(
        private string $method,
        private string $pattern,
        private $handler,
        private ?string $body = null,
        private int $status = 200,
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

    /** `null`, `self::JSON_BODY` ou `self::FORM_BODY`. */
    public function body(): ?string
    {
        return $this->body;
    }

    public function status(): int
    {
        return $this->status;
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

    /** @return callable */
    public function handler(): callable
    {
        return $this->handler;
    }

    /**
     * @param array<string, string> $parameters
     */
    public function invoke(array $parameters, ServerRequestInterface $request): mixed
    {
        return ($this->handler)($parameters, $request);
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
