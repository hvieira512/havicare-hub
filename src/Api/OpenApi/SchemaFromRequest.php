<?php

declare(strict_types=1);

namespace Hub\Api\OpenApi;

use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Mapping\ClassMetadataInterface;
use Symfony\Component\Validator\Validation;

/**
 * O esquema OpenAPI de um pedido, lido do objecto que o descreve.
 *
 * É isto que acaba com a duplicação. Os esquemas de pedido eram escritos à mão no
 * `Schemas/*.php` e as regras viviam outra vez, noutra forma, no serviço que as aplicava --
 * e divergiam sem nada as confrontar: o `ApiUserWriteRequest` declarava o `role` obrigatório
 * quando o serviço lhe dava um valor por omissão, e não mencionava dois campos que o serviço
 * lia. Aqui a declaração é uma só, e o documento é derivado dela.
 *
 * Traduz o que as constraints deste projecto usam, e mais nada. Uma constraint que não esteja
 * na lista é ignorada em silêncio no esquema -- continua a validar em execução, só não fica
 * descrita. O `SchemaFromRequestTest` prende cada tradução para o silêncio não crescer sem
 * ninguém reparar.
 */
final class SchemaFromRequest
{
    /**
     * @param class-string $requestClass
     * @param list<string> $groups grupos de validação que a rota corre, além do `Default`
     * @return array<string, mixed>
     */
    public static function schema(string $requestClass, array $groups = []): array
    {
        $metadata = Validation::createValidatorBuilder()
            ->enableAttributeMapping()
            ->getValidator()
            ->getMetadataFor($requestClass);

        $required = self::requiredFields($requestClass, $groups);
        $properties = [];
        foreach ((new \ReflectionClass($requestClass))->getConstructor()?->getParameters() ?? [] as $parameter) {
            $name = $parameter->getName();
            $properties[$name] = self::property(
                $parameter,
                self::constraintsFor($metadata, $name, $groups),
                in_array($name, $required, true),
            );
        }

        $schema = ['type' => 'object', 'properties' => $properties];
        if ($required !== []) {
            // Antes do `properties` porque é a ordem em que o resto do documento os escreve.
            $schema = ['type' => 'object', 'required' => $required, 'properties' => $properties];
        }

        return $schema;
    }

    /**
     * @param list<object> $constraints
     * @return array<string, mixed>
     */
    private static function property(\ReflectionParameter $parameter, array $constraints, bool $required): array
    {
        $type = $parameter->getType();
        $property = ['type' => self::jsonType($type instanceof \ReflectionNamedType ? $type->getName() : 'string')];
        if ($type instanceof \ReflectionNamedType && $type->allowsNull()) {
            $property['nullable'] = true;
        }

        foreach ($constraints as $constraint) {
            match (true) {
                $constraint instanceof Assert\Choice => $property['enum'] = self::choices($constraint),
                $constraint instanceof Assert\Length => $property = self::withLength($property, $constraint),
                $constraint instanceof Assert\Positive => $property['minimum'] = 1,
                $constraint instanceof Assert\PositiveOrZero => $property['minimum'] = 0,
                default => null,
            };
        }

        // Um campo obrigatório não tem valor por omissão que se documente: o valor com que
        // nasce é precisamente o que as regras recusam -- a string vazia do `NotBlank`, o
        // zero do `Positive` --, e anunciá-lo dizia a quem lê que omitir o campo é legítimo.
        $default = $parameter->isDefaultValueAvailable() ? $parameter->getDefaultValue() : null;
        if (!$required && $default !== null) {
            $property['default'] = $default;
        }

        return $property;
    }

    /**
     * @param array<string, mixed> $property
     * @return array<string, mixed>
     */
    private static function withLength(array $property, Assert\Length $constraint): array
    {
        if ($constraint->max !== null) {
            $property['maxLength'] = (int)$constraint->max;
        }
        if ($constraint->min !== null && (int)$constraint->min > 0) {
            $property['minLength'] = (int)$constraint->min;
        }

        return $property;
    }

    /** @return list<mixed> */
    private static function choices(Assert\Choice $constraint): array
    {
        if (is_array($constraint->choices) && $constraint->choices !== []) {
            return array_values($constraint->choices);
        }

        // O `callback` é a forma que este projecto usa, para a lista de valores viver onde
        // pertence -- o `ApiAuthContext::roles()` -- em vez de ser copiada para a constraint.
        $callback = $constraint->callback;

        return is_callable($callback) ? array_values((array)$callback()) : [];
    }

    /**
     * Obrigatório é o campo cujo valor por omissão as próprias regras recusam.
     *
     * Perguntar ao validador em vez de enumerar classes de constraint. A versão anterior
     * procurava `NotBlank` e `NotNull` e mais nada, e por isso o `licenseId` da associação --
     * que é obrigatório porque o `Positive` recusa o `0` com que nasce -- saía documentado
     * como opcional. Qualquer regra nova passava a ter de ser acrescentada aqui à mão, e o
     * esquema mentia em silêncio até alguém reparar.
     *
     * Instanciar sem argumentos é o que isto exige de um objecto de pedido: todos os campos
     * têm valor por omissão, porque é assim que um campo omitido se representa.
     *
     * @param class-string $requestClass
     * @param list<string> $groups
     * @return list<string>
     */
    private static function requiredFields(string $requestClass, array $groups): array
    {
        $violations = Validation::createValidatorBuilder()
            ->enableAttributeMapping()
            ->getValidator()
            ->validate(new $requestClass(), null, array_merge(['Default'], $groups));

        $required = [];
        foreach ($violations as $violation) {
            $required[(string)$violation->getPropertyPath()] = true;
        }

        return array_keys($required);
    }

    /**
     * As constraints de uma propriedade que correm nos grupos que a rota usa.
     *
     * É o que faz o `password` ser obrigatório no `POST` e opcional no `PUT` a partir da
     * mesma declaração: a regra está no grupo `create`, e só o esquema do criar o pede.
     *
     * @param list<string> $groups
     * @return list<object>
     */
    private static function constraintsFor(ClassMetadataInterface $metadata, string $property, array $groups): array
    {
        $active = array_merge(['Default'], $groups);
        $constraints = [];
        foreach ($metadata->getPropertyMetadata($property) as $propertyMetadata) {
            foreach ($propertyMetadata->getConstraints() as $constraint) {
                if (array_intersect($constraint->groups, $active) !== []) {
                    $constraints[] = $constraint;
                }
            }
        }

        return $constraints;
    }

    private static function jsonType(string $phpType): string
    {
        return match ($phpType) {
            'int' => 'integer',
            'float' => 'number',
            'bool' => 'boolean',
            'array' => 'array',
            default => 'string',
        };
    }
}
