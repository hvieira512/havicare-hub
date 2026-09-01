<?php

declare(strict_types=1);

namespace Hub\Api\Http;

use Hub\Domain\Capability\Contacts\WonlexContactCodec;

/**
 * O que a dashboard precisa para desenhar os campos de cada protocolo: etiquetas,
 * comprimentos máximos, `allowPartialRows` e os modos de toque.
 *
 * Está aqui e não no `src/Domain/` porque são decisões sobre formulários, e um segundo
 * cliente ou um segundo idioma tem assim um sítio só onde mexer. O `ProtocolService` junta
 * isto ao `describe()` do registo, e a chave `dashboard` sai como sempre saiu.
 */
final class ProtocolDashboardMeta
{
    /**
     * Os protocolos que não aparecem aqui não têm nada de especial a dizer à dashboard, e
     * recebem o mesmo vazio que recebiam quando a tabela os listava um a um.
     *
     * @return array<string, array<string, mixed>>
     */
    private static function table(): array
    {
        static $cache = null;

        return $cache ??= [
            'wonlex-json' => [
                'groupedCapabilities' => [
                    'phonebook' => [
                        'label' => 'Lista telefónica',
                        'limit' => 10,
                    ],
                    'sos_contacts' => [
                        'label' => 'Contactos SOS',
                        'limit' => 10,
                    ],
                    'whitelist_enabled' => [
                        'label' => 'Restringir chamadas recebidas',
                        'limit' => 0,
                    ],
                ],
                'fieldConstraints' => [
                    'phonebook' => [
                        'name' => ['maxLength' => WonlexContactCodec::NAME_MAX_LENGTH],
                        'phone' => ['maxLength' => 20, 'asciiOnly' => true],
                        'allowPartialRows' => true,
                    ],
                ],
            ],
            'four-p-touch' => [
                'groupedCapabilities' => [
                    'sos_contacts' => [
                        'label' => 'Contactos SOS',
                        'limit' => 3,
                    ],
                    'call_whitelist' => [
                        'label' => 'Lista branca',
                        'limit' => 10,
                    ],
                    'whitelist_enabled' => [
                        'label' => 'Lista branca ativa',
                        'limit' => 0,
                    ],
                ],
                'fieldConstraints' => [
                    'phonebook' => [
                        'name' => ['maxLength' => 10],
                        'phone' => ['maxLength' => 20, 'asciiOnly' => true],
                        'allowPartialRows' => true,
                    ],
                ],
            ],
            'moko-w6b' => [
                'helpCallPressModes' => ['single', 'double', 'long'],
            ],
            // Não tem toque longo: o firmware BXP Nordic dá cliques simples, duplos e
            // triplos, e é por triplo que o longo da W6B é substituído.
            'moko-w6' => [
                'helpCallPressModes' => ['single', 'double', 'triple'],
            ],
        ];
    }

    /** @return array{groupedCapabilities: array<string, mixed>, fieldConstraints: array<string, mixed>, helpCallPressModes: list<string>} */
    public static function forProtocol(string $protocol): array
    {
        $meta = self::table()[trim($protocol)] ?? [];

        return [
            'groupedCapabilities' => is_array($meta['groupedCapabilities'] ?? null) ? $meta['groupedCapabilities'] : [],
            'fieldConstraints' => is_array($meta['fieldConstraints'] ?? null) ? $meta['fieldConstraints'] : [],
            'helpCallPressModes' => is_array($meta['helpCallPressModes'] ?? null) ? $meta['helpCallPressModes'] : [],
        ];
    }
}
