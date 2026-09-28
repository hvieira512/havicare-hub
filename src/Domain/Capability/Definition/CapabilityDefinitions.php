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
    /** As quatro bandeiras de cada papel. Um papel que não esteja aqui rebenta em `all()`. */
    private const FLAGS = [
        'reading' => ['isTelemetry' => true, 'isConfigurable' => false, 'isRequestable' => false],
        'readingOnRequest' => ['isTelemetry' => true, 'isConfigurable' => false, 'isRequestable' => true],
        'setting' => ['isTelemetry' => false, 'isConfigurable' => true, 'isRequestable' => false],
        'action' => ['isTelemetry' => false, 'isConfigurable' => false, 'isRequestable' => true],
        'event' => ['isTelemetry' => false, 'isConfigurable' => false, 'isRequestable' => false, 'isEvent' => true],
    ];

    /** O tipo de aparelho de todas as linhas do ficheiro. */
    abstract protected static function deviceType(): string;

    /**
     * @return array<string, array<string, array<string, string>>> secção => papel => chave => etiqueta
     */
    abstract protected static function rows(): array;

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

                foreach ($entries as $key => $label) {
                    $definitions[] = [
                        'deviceType' => static::deviceType(),
                        'section' => $section,
                        'key' => $key,
                        'label' => $label,
                    ] + self::FLAGS[$role];
                }
            }
        }

        return $definitions;
    }
}
