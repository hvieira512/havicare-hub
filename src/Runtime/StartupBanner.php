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
    public static function log(array $config, HubMqttBridge $mqttBridge, array $enabledIngresses): void
    {
        $log = Logger::channel('hub');

        $log->info('=== Hitecosystem Devices Hub ===');
        $log->info("Dashboard: http://{$config['dashboard']['host']}:{$config['dashboard']['port']}/dashboard");
        $log->info("TCP ingress: tcp://{$config['tcp_ingress']['host']}:{$config['tcp_ingress']['port']}");
        $log->info(sprintf(
            'Redis downlink queue: %s:%s ttl=%ss',
            $config['redis']['host'],
            $config['redis']['port'],
            $config['hub']['downlink_queue_ttl_seconds'],
        ));

        foreach (['status', 'events', 'raw', 'downlink'] as $channel) {
            $label = $channel === 'events' ? 'event' : $channel;
            $log->info("MQTT {$label} topics: " . $mqttBridge->topic('{licenseId}/watch/{deviceKey}/' . $channel));
        }

        $descriptions = [
            'ncs' => [
                'NCS ingress topics',
                '{licenseId}/ncs/{deviceKey}/{raw|status|events|telemetry}',
            ],
            'moko' => [
                'MOKO MKGW3 ingress topics',
                '{company}/{licenseId}/gateway/{gatewayMac}/{raw|status|events|telemetry}',
            ],
            'qinglanst' => [
                'Qinglanst radar ingress',
                '{company}/{licenseId}/radar/{deviceUid}/{telemetry|events}',
            ],
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
