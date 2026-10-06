<?php

declare(strict_types=1);

namespace Hub\Domain\Capability\Definition;

use Hub\Domain\ProtocolRegistry;

/**
 * A base dos sete ficheiros de definições, um por tipo de aparelho. As quatro bandeiras de cada
 * linha só aparecem em cinco combinações, e os ficheiros escrevem esse eixo: o papel.
 */
abstract class CapabilityDefinitions
{
    /**
     * O que cada papel liga no contrato. O `isTelemetry` sai da secção, em `all()`, e um papel que
     * não esteja aqui rebenta.
     */
    private const FLAGS = [
        // O aparelho mede e reporta.
        'measurement' => ['isConfigurable' => false, 'isRequestable' => false],
        'measurementOnRequest' => ['isConfigurable' => false, 'isRequestable' => true],
        // Propriedade do avistamento, e não do aparelho: nasce de um gateway o ter ouvido.
        // Nunca é pedível, porque não há a quem a pedir.
        'sighting' => ['isConfigurable' => false, 'isRequestable' => false],
        // Escreve-se no aparelho por downlink.
        'setting' => ['isConfigurable' => true, 'isRequestable' => false],
        // Escreve-se, mas quem a aplica é o hub: não há downlink nem ciclo de confirmação.
        'hubSetting' => ['isConfigurable' => true, 'isRequestable' => false],
        // Dispara-se. Não há estado a guardar.
        'action' => ['isConfigurable' => false, 'isRequestable' => true],
        'event' => ['isConfigurable' => false, 'isRequestable' => false, 'isEvent' => true],
    ];

    /** Os papéis que só fazem sentido na secção de telemetria. */
    private const TELEMETRY_ROLES = ['measurement', 'measurementOnRequest', 'sighting'];

    /** O tipo de aparelho de todas as linhas do ficheiro. */
    abstract protected static function deviceType(): string;

    /**
     * @return array<string, array<string, array<string, string>>> secção => papel => chave => etiqueta
     */
    abstract protected static function rows(): array;

    /**
     * Que protocolos publicam cada capacidade fora da regra do ficheiro. Publicar -- sair em
     * `telemetry` ou `events` -- não é suportar, e a lista vazia quer dizer nenhum.
     *
     * @return array<string, list<string>> chave => protocolos
     */
    protected static function publishedBy(): array
    {
        return [];
    }

    /**
     * Quem publica uma capacidade que não se declare. `null` quer dizer todos os protocolos
     * deste tipo de aparelho.
     *
     * @return list<string>|null
     */
    protected static function defaultPublishedBy(): ?array
    {
        return null;
    }

    /**
     * Se o aparelho devolve no fio a sua própria configuração, como a pulseira Veepoo; nos relógios
     * escreve-se e nunca mais se vê.
     */
    protected static function publishesOwnConfiguration(): bool
    {
        return false;
    }

    /**
     * O papel de cada chave. Não entra no `all()` porque não é contrato -- é o conceito que
     * o ficheiro declara, e serve a quem o queira prender.
     *
     * @return array<string, string> chave => papel
     */
    final public static function roles(): array
    {
        $roles = [];
        foreach (static::rows() as $entriesByRole) {
            foreach ($entriesByRole as $role => $entries) {
                foreach (array_keys($entries) as $key) {
                    $roles[(string)$key] = (string)$role;
                }
            }
        }

        return $roles;
    }

    /**
     * Os protocolos que publicam cada chave do ficheiro, já com o valor por omissão
     * aplicado. Só as chaves que alguém publica aparecem.
     *
     * @return array<string, list<string>> chave => protocolos
     */
    final public static function publishers(): array
    {
        $declared = static::publishedBy();
        $fallback = static::defaultPublishedBy()
            ?? ProtocolRegistry::protocolsForDeviceType(static::deviceType());

        $publishers = [];
        foreach (static::all() as $definition) {
            $key = (string)$definition['key'];
            // Uma definição só se publica sozinha se for leitura ou acontecimento: uma
            // escrita chega ao aparelho e não volta, salvo nos aparelhos que a devolvem.
            $publishesByDefault = $definition['isTelemetry']
                || ($definition['isEvent'] ?? false)
                || static::publishesOwnConfiguration();
            $published = $declared[$key] ?? ($publishesByDefault ? $fallback : []);
            if ($published !== []) {
                $publishers[$key] = $published;
            }
        }

        $ours = ProtocolRegistry::protocolsForDeviceType(static::deviceType());
        foreach ($declared as $key => $protocols) {
            if (!isset($publishers[$key]) && $protocols !== []) {
                throw new \LogicException(sprintf(
                    '%s: o `publishedBy` declara "%s", que o ficheiro não define.',
                    static::class,
                    $key,
                ));
            }
            // Um protocolo de outro tipo de aparelho nunca casaria, e o catálogo ficava a
            // declarar uma capacidade que ninguém publica.
            foreach (array_diff($protocols, $ours) as $foreign) {
                throw new \LogicException(sprintf(
                    '%s: "%s" é publicada por "%s", que não serve %s.',
                    static::class,
                    $key,
                    $foreign,
                    static::deviceType(),
                ));
            }
        }

        return $publishers;
    }

    /**
     * @return list<array{deviceType: string, section: string, key: string, label: string, isTelemetry: bool, isConfigurable: bool, isRequestable: bool, isEvent?: bool}>
     */
    final public static function all(): array
    {
        $definitions = [];

        foreach (static::rows() as $section => $roles) {
            foreach ($roles as $role => $entries) {
                if (!isset(self::FLAGS[$role])) {
                    throw new \LogicException(sprintf(
                        '%s: papel desconhecido "%s" na secção "%s".',
                        static::class,
                        $role,
                        $section,
                    ));
                }

                if (in_array($role, self::TELEMETRY_ROLES, true) !== ($section === 'telemetry')) {
                    throw new \LogicException(sprintf(
                        '%s: o papel "%s" não pertence à secção "%s".',
                        static::class,
                        $role,
                        $section,
                    ));
                }

                foreach ($entries as $key => $label) {
                    $definitions[] = [
                        'deviceType' => static::deviceType(),
                        'section' => $section,
                        'key' => $key,
                        'label' => $label,
                        'isTelemetry' => $section === 'telemetry',
                    ] + self::FLAGS[$role];
                }
            }
        }

        return $definitions;
    }
}
