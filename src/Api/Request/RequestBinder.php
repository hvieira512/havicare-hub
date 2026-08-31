<?php

declare(strict_types=1);

namespace Hub\Api\Request;

use Hub\Api\Http\ApiError;
use Symfony\Component\Validator\Validation;
use Symfony\Component\Validator\Validator\ValidatorInterface;

/**
 * Transforma o corpo de um pedido no objecto que o descreve, ou no erro que o recusa.
 *
 * Duas coisas mudam em relação ao que os serviços faziam à mão.
 *
 * A primeira é que os erros vêm todos de uma vez. O `trim((string)($payload['x'] ?? ''))`
 * seguido de um `return` ao primeiro campo em falta obrigava quem preenche um formulário com
 * três campos errados a três idas ao servidor para os descobrir.
 *
 * A segunda é que um valor do tipo errado passa a ser recusado em vez de convertido. O
 * `(int)($payload['licenseRefId'] ?? 0)` transformava `"abc"` em `0`, e o `0` quer dizer
 * alguma coisa nas regras de licença -- uma entrada inválida entrava como uma entrada válida.
 *
 * A construção do objecto é feita aqui, por reflexão sobre o construtor, e não pelo
 * `symfony/serializer`. O serializer fazia exactamente isto e trazia atrás o
 * `property-access`, o `property-info`, o `string` e o `type-info` -- seis pacotes para
 * converter um array num objecto, dois dos quais exigem PHP mais recente do que o
 * `composer.json` declara. O que ele fazia a mais aqui não se usava: não há grafos de
 * objectos nem formatos além de arrays já descodificados.
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
     * @param bool $fromForm o corpo veio de `multipart/form-data`, onde tudo é string
     * @param array<string, string> $codeByField códigos de erro próprios, por campo
     * @return T|array{error: array<string, mixed>}
     */
    public function bind(
        array $payload,
        string $requestClass,
        array $groups = [],
        bool $fromForm = false,
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

            $cast = self::cast($payload[$name], $parameter->getType(), $fromForm);
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
     * O erro, com o código próprio do campo quando só um campo falhou.
     *
     * O serviço antigo devolvia um erro de cada vez, e por isso um papel inválido tinha o seu
     * `invalid_role` e um cliente distinguia-o. Enquanto falha um campo só, esse contrato
     * mantém-se exactamente como era. Vários campos a falhar é situação que antes não
     * existia -- não havia como dois códigos viajarem numa resposta -- e aí é o
     * `invalid_request` com o `fields` a dizer tudo.
     *
     * @param array<string, list<string>> $fieldErrors
     * @param array<string, string> $codeByField
     * @return array{error: array<string, mixed>}
     */
    private static function error(array $fieldErrors, array $codeByField): array
    {
        if (count($fieldErrors) === 1) {
            $field = array_key_first($fieldErrors);
            $code = $codeByField[$field] ?? null;
            if ($code !== null) {
                return ApiError::withFields($code, $fieldErrors[$field][0], $fieldErrors)->toArray();
            }
        }

        return ApiError::invalidFields($fieldErrors)->toArray();
    }

    /**
     * O valor convertido para o tipo declarado, ou `null` se não for convertível.
     *
     * Devolve um array de um elemento e não o valor: um campo pode legitimamente valer
     * `null`, e um `null` de retorno tem de querer dizer "recusado" e mais nada.
     *
     * O `multipart/form-data` não tem tipos -- o `supplier_id` chega como `"3"` e o `enabled`
     * como `"1"` --, e por isso a conversão de strings numéricas só é permitida quando o
     * corpo veio de um formulário. Num corpo JSON, um `"3"` onde se espera um inteiro é um
     * cliente com um erro, e é melhor dizer-lho.
     *
     * @return array{0: mixed}|null
     */
    private static function cast(mixed $value, ?\ReflectionType $type, bool $fromForm): ?array
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
                $fromForm && is_string($value) && preg_match('/^-?\d+$/', $value) === 1 => [(int)$value],
                default => null,
            },
            'float' => match (true) {
                is_float($value) || is_int($value) => [(float)$value],
                $fromForm && is_string($value) && is_numeric($value) => [(float)$value],
                default => null,
            },
            'bool' => match (true) {
                is_bool($value) => [$value],
                $fromForm && is_string($value) => self::formBool($value),
                default => null,
            },
            'string' => is_string($value) ? [$value] : null,
            'array' => is_array($value) ? [array_values($value)] : null,
            default => [$value],
        };
    }

    /** @return array{0: bool}|null */
    private static function formBool(string $value): ?array
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
     * Aceita `license_ref_id` como `licenseRefId`, e `capabilities[]` como `capabilities`.
     *
     * As duas grafias sempre foram aceites, campo a campo, com um `?? $payload['snake_case']`
     * escrito à mão em cada uma -- quinze deles espalhados pelos serviços. Aqui é a regra uma
     * vez. O sufixo `[]` é como um formulário nomeia um campo repetido e não faz parte do
     * nome do campo. A chave em camelCase ganha quando as duas vêm no mesmo corpo, porque é
     * a que a especificação documenta.
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
