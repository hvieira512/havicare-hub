<?php

namespace Hub\Domain;

/**
 * O que cada tipo de dispositivo é, num sítio só.
 *
 * `identity` é o campo que identifica a unidade e o que se lhe escreve ao lado; `sim` diz se
 * há número de SIM; `gatewayLinks` diz se o aparelho é retransmitido por um gateway em vez
 * de falar por conta própria.
 *
 * Estava escrito quatro vezes: a lista dos tipos em PHP e outra vez em `domain.js`, os tipos
 * que um gateway retransmite em PHP e na mesma tabela do JS, e o `sim` na tabela do JS e
 * outra vez como um `deviceType !== "watch"` dentro do `saveDevice`. Acrescentar um tipo
 * obrigava a encontrar os quatro, e o `sim` já tinha divergido -- a tabela dizia uma coisa e
 * o guardar fazia outra.
 *
 * A tabela vive num JSON e não neste ficheiro porque os dois lados precisam dela: o PHP
 * serve-a ao browser em `window.hubDeviceTypes`, e os testes do frontend, que correm em node
 * sem PHP nenhum, lêem o mesmo ficheiro. Um artefacto, dois leitores, nenhuma cópia.
 */
final class DeviceTypeCatalog
{
    private const FILE = __DIR__ . '/../../config/device-types.json';

    /** @var array<string, array<string, mixed>>|null */
    private static ?array $cache = null;

    /**
     * @return array<string, array{label: string, identity: array{field: string, label: string, help: string, placeholder: string}, sim: bool, gatewayLinks: bool}>
     */
    public static function all(): array
    {
        if (self::$cache !== null) {
            return self::$cache;
        }

        $raw = @file_get_contents(self::FILE);
        $decoded = $raw === false ? null : json_decode($raw, true);
        if (!is_array($decoded) || $decoded === []) {
            throw new \RuntimeException('config/device-types.json is missing or unreadable');
        }

        return self::$cache = $decoded;
    }

    /** @return list<string> */
    public static function keys(): array
    {
        return array_keys(self::all());
    }

    /**
     * Os tipos que um gateway retransmite por BLE.
     *
     * @return list<string>
     */
    public static function linkedToGateway(): array
    {
        return array_values(array_keys(array_filter(
            self::all(),
            static fn (array $descriptor): bool => (bool)($descriptor['gatewayLinks'] ?? false),
        )));
    }

    public static function hasSim(string $deviceType): bool
    {
        return (bool)(self::all()[$deviceType]['sim'] ?? false);
    }

    /** O JSON tal e qual, para o `index.php` o servir sem o voltar a codificar. */
    public static function asJson(): string
    {
        return json_encode(self::all(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}';
    }
}
