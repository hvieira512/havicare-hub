<?php

declare(strict_types=1);

namespace Hub\Api\Request;

use Hub\Api\Http\ApiError;
use Symfony\Component\Validator\Validation;
use Symfony\Component\Validator\Validator\ValidatorInterface;

/**
 * Transforma o corpo de um pedido no objecto que o descreve, ou no erro que o recusa.
 *
 * Os erros vêm todos de uma vez, e um valor do tipo errado é recusado em vez de convertido --
 * um `(int)"abc"` dava `0`, que quer dizer alguma coisa nas regras de licença.
 *
 * A construção é por reflexão sobre o construtor, e não pelo `symfony/serializer`: esse
 * trazia atrás seis pacotes para converter um array num objecto.
 */
final class RequestBinder
{
    private ValidatorInterface $validator;

    public function __construct(?ValidatorInterface $validator = null)
    {
        $this->validator = $validator ?? Validation::createValidatorBuilder()
            ->enableAttributeMapping()
            ->getValidator();
    }

    /**
     * @template T of object
     * @param array<string, mixed> $payload
     * @param class-string<T> $requestClass
     * @param list<string> $groups grupos de validação além do `Default`
     * @param bool $coerceStrings aceita escalares codificados como string
     * @param array<string, string> $codeByField códigos de erro próprios, por campo
     * @return T|array{error: array<string, mixed>}
     */
    public function bind(
        array $payload,
        string $requestClass,
        array $groups = [],
        bool $coerceStrings = false,
        array $codeByField = []
    ): object|array {
        $payload = self::canonicalKeys($payload);
        $parameters = (new \ReflectionClass($requestClass))->getConstructor()?->getParameters() ?? [];

        $arguments = [];
        $fieldErrors = [];
        foreach ($parameters as $parameter) {
            $name = $parameter->getName();
            if (!array_key_exists($name, $payload)) {
                continue;
            }

            $cast = self::cast($payload[$name], $parameter->getType(), $coerceStrings);
            if ($cast === null) {
                $fieldErrors[$name][] = 'must be of type ' . self::typeName($parameter->getType());
                continue;
            }
            $arguments[$name] = $cast[0];
        }

        if ($fieldErrors !== []) {
            return self::error($fieldErrors, $codeByField);
        }

        // Os argumentos vão por nome e não por posição: um campo omitido fica com o valor
        // por omissão do construtor sem ninguém ter de o repetir aqui.
        $request = new $requestClass(...$arguments);

        // O `Default` entra sempre; os grupos extra juntam-se a ele. Sem o `Default`
        // explícito, pedir o grupo `create` desligava todas as regras que não o declaram.
        foreach ($this->validator->validate($request, null, array_merge(['Default'], $groups)) as $violation) {
            $fieldErrors[(string)$violation->getPropertyPath()][] = (string)$violation->getMessage();
        }

        return $fieldErrors === [] ? $request : self::error($fieldErrors, $codeByField);
    }

    /**
     * Com um campo só a falhar, o código e a mensagem da constraint são preservados, e o
     * `fields` vem por acréscimo. Com vários, a mensagem genérica é a honesta.
     *
     * @param array<string, list<string>> $fieldErrors
     * @param array<string, string> $codeByField
     * @return array{error: array<string, mixed>}
     */
    private static function error(array $fieldErrors, array $codeByField): array
    {
        // Contam-se as mensagens e não os campos: `company and licenseId are required` é um
        // erro só dito por dois sítios.
        $messages = array_unique(array_merge(...array_values($fieldErrors)));
        if (count($messages) !== 1) {
            return ApiError::invalidFields($fieldErrors)->toArray();
        }

        // O código próprio só se aplica quando há um campo só a falhar: com mais do que um
        // não há como escolher entre os deles, e o genérico é o honesto.
        $code = count($fieldErrors) === 1
            ? ($codeByField[array_key_first($fieldErrors)] ?? 'invalid_request')
            : 'invalid_request';

        return ApiError::withFields($code, reset($messages), $fieldErrors)->toArray();
    }

    /**
     * O valor convertido para o tipo declarado, ou `null` se não for convertível. Devolve um
     * array de um elemento porque um campo pode legitimamente valer `null`.
     *
     * A conversão de strings numéricas é opcional e a rota é que decide: o
     * `multipart/form-data` não tem tipos, e fora dele um `"3"` num campo inteiro é um erro
     * do cliente que vale mais apontar do que adivinhar.
     *
     * @return array{0: mixed}|null
     */
    private static function cast(mixed $value, ?\ReflectionType $type, bool $coerceStrings): ?array
    {
        if (!$type instanceof \ReflectionNamedType) {
            return [$value];
        }
        if ($value === null) {
            return $type->allowsNull() ? [null] : null;
        }

        return match ($type->getName()) {
            'int' => match (true) {
                is_int($value) => [$value],
                $coerceStrings && is_string($value) && preg_match('/^-?\d+$/', $value) === 1 => [(int)$value],
                default => null,
            },
            'float' => match (true) {
                is_float($value) || is_int($value) => [(float)$value],
                $coerceStrings && is_string($value) && is_numeric($value) => [(float)$value],
                default => null,
            },
            'bool' => match (true) {
                is_bool($value) => [$value],
                $coerceStrings && is_string($value) => self::stringBool($value),
                default => null,
            },
            'string' => is_string($value) ? [$value] : null,
            'array' => is_array($value) ? [array_values($value)] : null,
            default => [$value],
        };
    }

    /** @return array{0: bool}|null */
    private static function stringBool(string $value): ?array
    {
        return match (strtolower(trim($value))) {
            '1', 'true', 'on', 'yes' => [true],
            '0', 'false', 'off', 'no', '' => [false],
            default => null,
        };
    }

    private static function typeName(?\ReflectionType $type): string
    {
        if (!$type instanceof \ReflectionNamedType) {
            return 'a valid value';
        }

        return ($type->allowsNull() && $type->getName() !== 'null' ? '?' : '') . $type->getName();
    }

    /**
     * Aceita `license_ref_id` como `licenseRefId`, e `capabilities[]` como `capabilities`. O
     * camelCase ganha quando as duas vêm no mesmo corpo, por ser o que a especificação
     * documenta.
     *
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private static function canonicalKeys(array $payload): array
    {
        $canonical = [];
        foreach ($payload as $key => $value) {
            $key = (string)$key;
            $name = str_ends_with($key, '[]') ? substr($key, 0, -2) : $key;
            if (str_contains($name, '_')) {
                $name = lcfirst(str_replace(' ', '', ucwords(str_replace('_', ' ', $name))));
            }
            if ($name !== $key && array_key_exists($name, $payload)) {
                continue;
            }
            $canonical[$name] = $value;
        }

        return $canonical;
    }
}
