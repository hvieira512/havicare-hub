<?php

declare(strict_types=1);

namespace Hub\Runtime;

use Hub\Device\HubMqttBridge;
use Hub\Log\Logger;

final class StartupBanner
{
    /**
     * @param array<string, mixed> $config the full hub config
     * @param list<string> $enabledIngresses keys of the suppliers that were started
     */
    public static function log(
        array $config,
        HubMqttBridge $mqttBridge,
        array $enabledIngresses,
        ?SystemdWatchdog $watchdog = null,
    ): void {
        $log = Logger::channel('hub');

        $log->info('=== Havicare Hub ===');

        // A implementação do event loop não vem de configuração nossa: o ReactPHP escolhe a
        // melhor das extensões instaladas, e cai no `StreamSelectLoop` -- `select(2)`, preso
        // nos 1024 descritores -- quando não há nenhuma. Fica registada porque instalar uma
        // extensão a troca em silêncio, sem uma linha de diferença no repositório.
        $loop = get_class(\React\EventLoop\Loop::get());
        $log->info(sprintf(
            'Event loop: %s%s',
            $loop,
            str_contains($loop, 'StreamSelect') ? ' (select: teto de 1024 descritores)' : ' (epoll)',
        ));

        // Silêncio aqui significa que o systemd não está a vigiar, e é a diferença entre um
        // processo pendurado ser reiniciado em segundos ou ficar vivo e calado.
        $log->info($watchdog === null
            ? 'Watchdog: desligado (sem WatchdogSec na unit)'
            : sprintf('Watchdog: ping a cada %.1fs', $watchdog->pingIntervalSeconds()));

        $log->info("Dashboard: http://{$config['dashboard']['host']}:{$config['dashboard']['port']}/dashboard");
        $log->info("TCP ingress: tcp://{$config['tcp_ingress']['host']}:{$config['tcp_ingress']['port']}");
        $log->info(sprintf(
            'Redis downlink queue: %s:%s ttl=%ss',
            $config['redis']['host'],
            $config['redis']['port'],
            $config['hub']['downlink_queue_ttl_seconds'],
        ));

        // Com que identidade cada ligação se apresenta ao broker.
        //
        // Dois hubs com o mesmo identificador expulsam-se em ciclo, e cada expulsão tira a
        // ingestão do ar. Nos dois lados o log só diz «connection lost», que é o sintoma;
        // aqui fica a causa, à vista no arranque.
        $log->info(sprintf(
            'MQTT client id: %s-*',
            trim((string)($config['mqtt']['client_id_prefix'] ?? '')),
        ));
        $qinglanstPrefix = trim((string)($config['qinglanst']['client_id_prefix'] ?? ''));
        if ($qinglanstPrefix !== '') {
            $log->info("Qinglanst client id: {$qinglanstPrefix}-*");
        }

        foreach (['status', 'events', 'raw', 'telemetry'] as $channel) {
            $label = $channel === 'events' ? 'event' : $channel;
            $log->info("MQTT {$label} topics: " . $mqttBridge->topic('{company}/{licenseId}/watch/{deviceKey}/' . $channel));
        }

        // A terceira coluna é a secção de onde sai o filtro, e não se deduz da chave: a
        // ingestão Veepoo lê o mesmo espaço de tópicos dos gateways, e não tem secção
        // própria. Enquanto a chave servia de índice à configuração, acrescentar aqui uma
        // linha para ela dava índice indefinido no arranque.
        $descriptions = [
            'ncs' => [
                'NCS ingress topics',
                '{company}/{licenseId}/ncs/{deviceKey}/{raw|status|events|telemetry}',
                'ncs',
            ],
            'gateway' => [
                'Gateway ingress topics',
                '{company}/{licenseId}/gateway/{gatewayMac}/{raw|status|events|telemetry}',
                'gateway',
            ],
            'veepoo' => [
                'Veepoo bracelet ingress topics',
                '{company}/{licenseId}/bracelet/{deviceKey}/{raw|status|events|telemetry}',
                'gateway',
            ],
            'qinglanst' => [
                'Qinglanst radar ingress',
                '{company}/{licenseId}/radar/{deviceKey}/{telemetry|events}',
                'qinglanst',
            ],
        ];

        foreach ($enabledIngresses as $key) {
            if (!isset($descriptions[$key])) {
                continue;
            }

            [$label, $target, $configSection] = $descriptions[$key];
            $filter = trim((string)$config[$configSection]['topic_filter']);
            $log->info("{$label}: {$filter} -> " . $mqttBridge->topic($target));
        }
    }
}
