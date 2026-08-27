<?php

namespace Hub\Api\OpenApi;

/**
 * Construtores de parâmetros OpenAPI reutilizáveis, partilhados pelas definições de rotas.
 */
final class Parameters
{
    /**
     * Um parâmetro de rota documentado, com esquema escalar.
     */
    public static function path(string $name, string $description, string $type, string|int $example): array
    {
        return [
            'name' => $name,
            'in' => 'path',
            'required' => true,
            'description' => $description,
            'schema' => ['type' => $type, 'example' => $example],
        ];
    }

    /**
     * Path parameter carrying an explicit schema, such as an enum or a bounded integer.
     */
    public static function pathSchema(string $name, array $schema): array
    {
        return [
            'name' => $name,
            'in' => 'path',
            'required' => true,
            'schema' => $schema,
        ];
    }

    public static function id(string $description, string $type = 'integer', string|int $example = 1): array
    {
        return self::path('id', $description, $type, $example);
    }

    public static function imei(): array
    {
        return self::path('imei', 'Device IMEI', 'string', '865028000000306');
    }

    public static function linkedImei(): array
    {
        return self::path('linkedImei', 'Linked device canonical key', 'string', 'eec5000202f9');
    }

    public static function query(string $name, array $schema): array
    {
        return ['name' => $name, 'in' => 'query', 'required' => false, 'schema' => $schema];
    }

    public static function stringQuery(string $name): array
    {
        return self::query($name, ['type' => 'string']);
    }

    /**
     * Um filtro que aceita vários valores.
     *
     * `explode: true` sem `style` dá `chave[]=a&chave[]=b`, que é a forma que o `parse_str`
     * do lado do servidor lê como array.
     */
    public static function stringList(string $name): array
    {
        return [
            'name' => $name,
            'in' => 'query',
            'required' => false,
            'explode' => true,
            'schema' => ['type' => 'array', 'items' => ['type' => 'string']],
        ];
    }

    /**
     * @return array<int, array<string, mixed>> page and limit query parameters
     */
    public static function pagination(int $defaultLimit = 20): array
    {
        return [
            self::query('page', ['type' => 'integer', 'default' => 1]),
            self::query('limit', ['type' => 'integer', 'default' => $defaultLimit]),
        ];
    }
}
