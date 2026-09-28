<?php

namespace Hub\Domain\Capability\Definition;

/**
 * A base dos sete ficheiros de definições, um por tipo de aparelho.
 *
 * Cada linha do catálogo declara quatro bandeiras, mas nas 160 que existem só aparecem
 * cinco combinações. São um eixo só, e é esse eixo que os ficheiros escrevem: o papel.
 */
abstract class CapabilityDefinitions
{
    /**
     * O que cada papel liga no contrato. O `isTelemetry` não está aqui: é a secção, e sai
     * dela em `all()` para os dois não poderem discordar.
     *
     * Um papel que não esteja nesta tabela rebenta em `all()`.
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
