<?php

namespace Hub\Api\Routing;

use Psr\Http\Message\ServerRequestInterface;

final class ApiRoute
{
    /** O handler não quer nada. */
    private const TAKES_NOTHING = 0;

    /** O handler quer o pedido. */
    private const TAKES_REQUEST = 1;

    /** O handler quer os parâmetros do caminho. */
    private const TAKES_PARAMETERS = 2;

    /** O handler quer os dois, por esta ordem. */
    private const TAKES_BOTH = 3;

    /**
     * Quantas vezes uma rota teve de olhar para a assinatura de um handler.
     *
     * Existe para o teste: o ganho desta classe é a reflexão correr uma vez por rota e não
     * uma por pedido, e sem um contador isso não se distingue de continuar a correr sempre.
     */
    private static int $shapeResolutions = 0;

    private string $regex;

    private int $shape;

    /**
     * O handler declara o que quer receber, e a rota resolve isso uma vez. As formas aceites
     * são quatro, e não há outra:
     *
     * - `fn(): mixed` -- não quer nada;
     * - `fn(ServerRequestInterface): mixed` -- quer o pedido;
     * - `fn(array): mixed` -- quer os parâmetros do caminho;
     * - `fn(array, ServerRequestInterface): mixed` -- quer os dois, por esta ordem.
     *
     * O tipo não se escreve mais apertado do que isto de propósito: uma união das quatro
     * assinaturas obrigava a mentir em três delas.
     *
     * @param callable $handler
     */
    public function __construct(
        private string $method,
        private string $pattern,
        private $handler,
    ) {
        $this->regex = $this->compilePattern($pattern);
        $this->shape = self::resolveShape($handler);
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

    /** @return callable a assinatura é uma das quatro descritas no construtor */
    public function handler(): callable
    {
        return $this->handler;
    }

    /**
     * Chama o controlador com o que ele declarou querer.
     *
     * A forma foi resolvida na construção, que corre uma vez no arranque. O `ApiKernel` fazia
     * isto por pedido, com uma `ReflectionMethod` nova de cada vez, no mesmo processo que
     * serve a ingestão TCP dos relógios.
     *
     * @param array<string, string> $parameters
     */
    public function invoke(array $parameters, ServerRequestInterface $request): mixed
    {
        $handler = $this->handler;

        return match ($this->shape) {
            self::TAKES_NOTHING => $handler(),
            self::TAKES_REQUEST => $handler($request),
            self::TAKES_PARAMETERS => $handler($parameters),
            default => $handler($parameters, $request),
        };
    }

    /**
     * Que argumentos é que este handler quer.
     *
     * Um argumento é ambíguo -- tanto pode ser o pedido como os parâmetros --, e é o tipo
     * declarado que decide. Sem tipo, são os parâmetros: é o que a maioria dos controladores
     * recebe e era o comportamento anterior.
     *
     * @param callable $handler
     */
    private static function resolveShape($handler): int
    {
        self::$shapeResolutions++;

        $reflection = is_array($handler)
            ? new \ReflectionMethod($handler[0], $handler[1])
            : new \ReflectionFunction(\Closure::fromCallable($handler));

        $count = $reflection->getNumberOfParameters();
        if ($count === 0) {
            return self::TAKES_NOTHING;
        }
        if ($count >= 2) {
            return self::TAKES_BOTH;
        }

        $parameter = $reflection->getParameters()[0] ?? null;

        return $parameter !== null && self::expectsRequest($parameter)
            ? self::TAKES_REQUEST
            : self::TAKES_PARAMETERS;
    }

    private static function expectsRequest(\ReflectionParameter $parameter): bool
    {
        $type = $parameter->getType();
        if (!$type instanceof \ReflectionNamedType) {
            return false;
        }

        $name = $type->getName();

        return $name === ServerRequestInterface::class || is_a($name, ServerRequestInterface::class, true);
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
