<?php

declare(strict_types=1);

namespace Hub\Api\Routing;

use Hub\Api\Request\RequestBinder;
use Psr\Http\Message\ServerRequestInterface;

final class ApiRoute
{
    /** O corpo chega em JSON. */
    public const JSON_BODY = 'json';

    /** O corpo chega em JSON ou em `multipart/form-data`, porque traz um ficheiro com ele. */
    public const FORM_BODY = 'form';

    private string $regex;

    /**
     * O handler é `fn(array $params, ServerRequestInterface $request, object $input)`, e pode
     * declarar menos. O `$body` faz o kernel descodificar e recusar `invalid_json`; o `$request`
     * é a classe em que o kernel o liga e valida antes de chamar o handler, que recebe o `$input`.
     *
     * @param callable $handler
     * @param class-string|null $request
     * @param list<string>|\Closure(array<string, string>): list<string> $groups grupos além do `Default`
     * @param array<string, string> $codeByField
     */
    public function __construct(
        private string $method,
        private string $pattern,
        private $handler,
        private ?string $body = null,
        private int $status = 200,
        private ?string $request = null,
        private array|\Closure $groups = [],
        private bool $coerceStrings = false,
        private array $codeByField = [],
        private bool $revalidated = false,
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

    /** Só catálogos levam ETag: o estado de um dispositivo muda de segundos a segundos. */
    public function revalidated(): bool
    {
        return $this->revalidated;
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

    /**
     * O corpo ligado à classe que a rota declara, a recusa com o `error.fields`, ou `null` se a
     * rota não declara nenhuma.
     *
     * @param array<string, string> $parameters
     * @param array<string, mixed> $body
     * @return object|array{error: array<string, mixed>}|null
     */
    public function bind(array $parameters, array $body): object|array|null
    {
        if ($this->request === null) {
            return null;
        }

        $groups = $this->groups instanceof \Closure ? ($this->groups)($parameters) : $this->groups;

        return (new RequestBinder())->bind($body, $this->request, $groups, $this->coerceStrings, $this->codeByField);
    }

    /**
     * @param array<string, string> $parameters
     */
    public function invoke(array $parameters, ServerRequestInterface $request, ?object $input = null): mixed
    {
        return ($this->handler)($parameters, $request, $input);
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
