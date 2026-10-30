<?php

declare(strict_types=1);

namespace Hub\Runtime;

use Hub\Device\HubMqttBridge;
use Hub\Log\Logger;

final class StartupBanner
{
    /**
     * @param array<string, mixed> $config a configuração completa do hub
     * @param list<string> $enabledIngresses as chaves dos fornecedores arrancados
     */
    public static function log(
        array $config,
        HubMqttBridge $mqttBridge,
        array $enabledIngresses,
        ?SystemdWatchdog $watchdog = null,
    ): void {
        $log = Logger::channel('hub');

        $log->info('=== Havicare Hub ===');

        // O event loop é o que o ReactPHP escolhe das extensões instaladas, ou o `StreamSelectLoop`
        // (1024 descritores) sem nenhuma; regista-se porque instalar uma extensão o troca em silêncio.
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

        // Com que identidade cada ligação se apresenta ao broker: dois hubs com o mesmo identificador
        // expulsam-se em ciclo, e o log só diz «connection lost».
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

        // A chave de cada ingestão é a secção da configuração de onde sai o filtro.
        $descriptions = [
            'ncs' => ['NCS ingress topics', '{company}/{licenseId}/ncs/{deviceKey}/{raw|status|events|telemetry}'],
            'gateway' => ['Gateway ingress topics', '{company}/{licenseId}/{deviceType}/{deviceKey}/{raw|status|events|telemetry}'],
            'qinglanst' => ['Qinglanst radar ingress', '{company}/{licenseId}/radar/{deviceKey}/{telemetry|events}'],
        ];

        foreach ($enabledIngresses as $key) {
            if (!isset($descriptions[$key])) {
                continue;
            }

            [$label, $target] = $descriptions[$key];
            $filter = trim((string)$config[$key]['topic_filter']);
            $log->info("{$label}: {$filter} -> " . $mqttBridge->topic($target));
        }
    }
}
