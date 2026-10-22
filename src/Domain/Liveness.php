<?php

declare(strict_types=1);

namespace Hub\Domain;

/**
 * Quanto silêncio um aparelho aguenta antes de se dar por desligado: dois sinais de vida
 * falhados, mais uma margem. O intervalo é o confirmado no aparelho, ou o documentado.
 */
final class Liveness
{
    private const GRACE_SECONDS = 30;

    /** O intervalo do sinal de vida de cada protocolo, em segundos; `null` quando não o há. */
    private const INTERVAL_SECONDS = [
        // A especificação Wonlex não fixa o `heartbeat`; sai de 303 em 303 s (observado em produção, 22/10/2026).
        'wonlex-json' => 300,
        // O LK sai de 5 a 8 minutos pela especificação 4P, e de 300 em 300 s (observado em produção, 22/10/2026).
        'four-p-touch' => 300,
        // O AP03 só se regula por SMS (`#hb#`); sai de 480 em 480 s (observado em produção, 22/10/2026).
        'vivistar-iw' => 480,
        // O heartbeat `0x02`, com os 60 s que o M228 declara no `0x8004` do registo (docs/19a).
        'zayata-m228' => 60,
        // O `position` sai de 30 em 30 s sem gente (Cloud2cloud V2.2.3 §3.3.1); a frota chega a 62 s
        // no p99 (observado em produção, 22/10/2026).
        'qinglanst-radar' => 60,
        // O estado de rede `3004`, de 30 em 30 s por omissão (MKGW3 MQTT V2.3 §3.3.3).
        'moko-mkgw3' => 30,
        // O estado `0x3004` regula-se no `0x100C`, que o hub não guarda; 600 s é o mais lento da
        // frota (observado em produção, 22/10/2026).
        'moko-mkgw4' => 600,
        // O `heartbeat` do gateway da Havicare, de minuto a minuto (havicare-ble-gateway, `HEARTBEAT_MS`).
        'havicare-ble-gateway' => 60,
        // Sem intervalo de anúncio documentado: chegam ao ritmo do MKGW4, que sobe o que viu de
        // 600 em 600 s (MKGW4 Config APP V2.1 §4.3.1).
        'monit-mecs-pro-ble' => 600,
        'moko-w6' => 600,
        'moko-w6b' => 600,
        // O gateway repete a sessão da pulseira de 30 em 30 s (havicare-ble-gateway, `HEARTBEAT_SECONDS`).
        'veepoo-ble' => 30,
        // O `status/online` repete-se de 13 a 20 minutos (observado em produção, 22/10/2026); o
        // testamento no broker diz o `offline` sem esperar (Voerka §4.2).
        'voerka-ncs' => 1200,
        // O `deviceOnline` e o `deviceOffline` da cloud dizem quando o botão acorda e adormece.
        'tuya-cloud' => null,
    ];

    private const DEFAULT_INTERVAL_SECONDS = 300;

    /**
     * Ligam-se para medir e desligam-se: fora da sessão não estão desligados, estão à espera da
     * próxima leitura (`idle`), e dentro dela estão a medir (`measuring`).
     */
    private const ON_DEMAND = ['lefu-torre', 'joytech-bp', 'joytech-bp-ecg', 'joytech-et', 'joytech-spo2'];

    /** Uma sessão GATT de que não se ouve nada há este tempo acabou, mesmo sem o `connected: false`. */
    public const SESSION_SILENCE_SECONDS = 120;

    /** As configurações de que o intervalo pode depender, pela chave do contrato. */
    public const CONFIGURATION_KEYS = ['working_mode'];

    /**
     * O silêncio, em segundos, a partir do qual o aparelho se dá por desligado; `null` quando o
     * silêncio não diz nada e o estado vem de outro lado.
     *
     * @param array<string, mixed> $configurations o valor confirmado de cada chave de `CONFIGURATION_KEYS`
     */
    public static function idleTimeout(string $protocol, array $configurations = []): ?int
    {
        if (self::isOnDemand($protocol)) {
            return self::SESSION_SILENCE_SECONDS;
        }
        $interval = self::interval($protocol, $configurations);

        return $interval === null ? null : 2 * $interval + self::GRACE_SECONDS;
    }

    public static function isOnDemand(string $protocol): bool
    {
        return in_array($protocol, self::ON_DEMAND, true);
    }

    /** O estado de presença: `online`/`offline`, ou `measuring`/`idle` em quem só se liga para medir. */
    public static function presence(string $protocol, bool $connected): string
    {
        if (self::isOnDemand($protocol)) {
            return $connected ? 'measuring' : 'idle';
        }

        return $connected ? 'online' : 'offline';
    }

    /** Se o intervalo deste protocolo pode vir de uma configuração confirmada. */
    public static function isConfigurable(string $protocol): bool
    {
        return $protocol === 'vivistar-iw';
    }

    /** @param array<string, mixed> $configurations */
    private static function interval(string $protocol, array $configurations): ?int
    {
        $default = array_key_exists($protocol, self::INTERVAL_SECONDS)
            ? self::INTERVAL_SECONDS[$protocol]
            : self::DEFAULT_INTERVAL_SECONDS;
        if ($protocol !== 'vivistar-iw') {
            return $default;
        }

        // O modo de poupança reporta de hora a hora e o personalizado ao intervalo pedido (BP33).
        $mode = is_array($configurations['working_mode'] ?? null) ? $configurations['working_mode'] : [];
        $configured = match ($mode['mode'] ?? null) {
            'power_saving' => 3600,
            'custom' => (int)($mode['intervalSeconds'] ?? 0),
            default => 0,
        };

        return max((int)$default, $configured);
    }
}
