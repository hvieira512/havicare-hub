#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Watches the BLE devices a MOKO gateway is scanning and reports the ones we
 * can identify from their advertising payload.
 *
 * Identification is always payload-based: most phones use random addresses that
 * rotate every few minutes, so the MAC alone means nothing.
 *
 * Usage:
 *   php simulator/ble-scan-probe.php                     # run until Ctrl-C
 *   php simulator/ble-scan-probe.php --seconds=120       # stop after 2 minutes
 *   php simulator/ble-scan-probe.php --all               # include unidentified devices
 *   php simulator/ble-scan-probe.php --mac=eec5000202f9  # follow one device
 */

require __DIR__ . '/../vendor/autoload.php';

use Hub\Ingress\Mqtt\Moko\MokoMessageDecoder;
use Hub\Ingress\Mqtt\Moko\MonitMecsProDecoder;
use Hub\Mqtt\BrokerSettings;
use Hub\Mqtt\ConnectionFactory;
use Hub\Runtime\CliBootstrap;

/** Relatórios de scan: 3070 no MKGW3 (JSON), 30a0/30b2 no MKGW4 (binário). */
const SCAN_MESSAGES = [3070, '30a0', '30b2'];

$options = getopt('', ['seconds::', 'all', 'mac::', 'topic::', 'help']);
if (isset($options['help'])) {
    fwrite(STDOUT, "Usage: php simulator/ble-scan-probe.php [--seconds=N] [--all] [--mac=AABBCC...] [--topic=filter]\n");
    exit(0);
}

$seconds = (int)($options['seconds'] ?? 0);
$showAll = isset($options['all']);
$onlyMac = strtolower(trim((string)($options['mac'] ?? '')));
$config = CliBootstrap::config(__DIR__ . '/..');
$topicFilter = trim((string)($options['topic'] ?? $config['moko']['topic_filter']));

/**
 * Signatures we can positively identify. Service-data UUIDs are written as they
 * appear on the wire (little endian), which is how they are matched.
 *
 * BXP-B / MK Button comes from the "MOKO Beacon - ADV Format Summary Sheet",
 * BXP-B Series tab.
 */
const SERVICE_SIGNATURES = [
    'e0fe' => 'MOKO BXP-B (MK Button) — alarm frame',
    '00ea' => 'MOKO BXP-B (MK Button) — scan response',
    'abfe' => 'MOKO BXP-B (MK Button) — iBeacon frame',
    '11aa' => 'MOKO gateway (self-advertising)',
];

/** @return list<array{type: int, data: list<int>}> */
function adStructures(string $hex): array
{
    $hex = strtolower(trim($hex));
    if ($hex === '' || strlen($hex) % 2 !== 0 || preg_match('/^[0-9a-f]+$/', $hex) !== 1) {
        return [];
    }

    $bytes = array_values(unpack('C*', hex2bin($hex) ?: ''));
    $structures = [];
    $offset = 0;
    $count = count($bytes);
    while ($offset < $count) {
        $length = $bytes[$offset];
        if ($length === 0 || $offset + $length >= $count + 1) {
            break;
        }
        $structures[] = ['type' => $bytes[$offset + 1] ?? 0, 'data' => array_slice($bytes, $offset + 2, $length - 1)];
        $offset += $length + 1;
    }

    return $structures;
}

/** @param list<int> $bytes */
function asText(array $bytes): string
{
    return trim(implode('', array_map(static fn (int $b): string => $b >= 32 && $b < 127 ? chr($b) : '', $bytes)));
}

/** @return array{name: string, services: list<string>, manufacturers: list<string>} */
function describe(string $advHex, string $rspHex): array
{
    $name = '';
    $services = [];
    $manufacturers = [];

    foreach ([$advHex, $rspHex] as $hex) {
        foreach (adStructures($hex) as $structure) {
            $type = $structure['type'];
            $data = $structure['data'];

            if (($type === 0x09 || $type === 0x08) && $name === '') {
                $name = asText($data);
            }
            if ($type === 0x16 && count($data) >= 2) {
                $services[] = sprintf('%02x%02x', $data[0], $data[1]);
            }
            if ($type === 0xff && count($data) >= 2) {
                $manufacturers[] = sprintf('%02X%02X', $data[1], $data[0]);
            }
        }
    }

    return [
        'name' => $name,
        'services' => array_values(array_unique($services)),
        'manufacturers' => array_values(array_unique($manufacturers)),
    ];
}

$connections = new ConnectionFactory(BrokerSettings::fromHubConfig($config['mqtt']));
$client = $connections->build('ble-probe-' . substr(bin2hex(random_bytes(3)), 0, 6));

$monitDecoder = new MonitMecsProDecoder();
$seen = [];
$hits = 0;

fwrite(STDOUT, "Watching {$topicFilter}\n");
fwrite(STDOUT, $seconds > 0 ? "Stopping after {$seconds}s.\n" : "Press Ctrl-C to stop.\n");
fwrite(STDOUT, str_repeat('-', 78) . "\n");

$gatewayDecoder = new MokoMessageDecoder();

$client->subscribe($topicFilter, function (string $topic, string $message) use (
    &$seen, &$hits, $monitDecoder, $showAll, $onlyMac, $gatewayDecoder
): void {
    $decoded = $gatewayDecoder->decode($message);
    if (!in_array($decoded['messageId'] ?? null, SCAN_MESSAGES, true) || !is_array($decoded['data'] ?? null)) {
        return;
    }

    foreach ($decoded['data'] as $observation) {
        if (!is_array($observation) || !isset($observation['mac'])) {
            continue;
        }

        $mac = strtolower((string)$observation['mac']);
        if ($onlyMac !== '' && $mac !== $onlyMac) {
            continue;
        }

        $adv = strtolower((string)($observation['adv_data'] ?? ''));
        $rsp = strtolower((string)($observation['rsp_data'] ?? ''));
        $rssi = (int)($observation['rssi'] ?? 0);
        $info = describe($adv, $rsp);

        $label = null;
        foreach ($info['services'] as $service) {
            if (isset(SERVICE_SIGNATURES[$service])) {
                $label = SERVICE_SIGNATURES[$service];
                break;
            }
        }
        if ($label === null && $monitDecoder->decode(['mac' => $mac, 'adv_data' => $adv]) !== null) {
            $label = 'MONIT MECS Pro (diaper sensor)';
        }

        $isHit = $label !== null && str_contains($label, 'BXP-B');
        if ($label === null && !$showAll) {
            continue;
        }

        $key = $mac . '|' . ($label ?? '?');
        $first = !isset($seen[$key]);
        $seen[$key] = ($seen[$key] ?? 0) + 1;
        if (!$first && !$isHit) {
            continue;
        }

        $stamp = gmdate('H:i:s');
        if ($isHit) {
            $hits++;
            fwrite(STDOUT, "\n*** BXP-B DETECTED ***\n");
            fwrite(STDOUT, "  {$stamp}  mac={$mac}  rssi={$rssi}\n");
            fwrite(STDOUT, "  {$label}\n  adv={$adv}\n  rsp={$rsp}\n\n");
            continue;
        }

        $name = $info['name'] !== '' ? $info['name'] : '(no name)';
        $extra = $info['manufacturers'] !== [] ? ' mfg=' . implode(',', $info['manufacturers']) : '';
        fwrite(STDOUT, sprintf(
            "%s  %-13s rssi=%-5d %-34s %s%s\n",
            $stamp,
            $mac,
            $rssi,
            $label ?? 'unidentified',
            $name,
            $extra
        ));
    }
}, 0);

$deadline = $seconds > 0 ? microtime(true) + $seconds : null;
while ($deadline === null || microtime(true) < $deadline) {
    $client->loopOnce(microtime(true), false, 100000);
}
$client->disconnect();

fwrite(STDOUT, str_repeat('-', 78) . "\n");
fwrite(STDOUT, $hits > 0
    ? "BXP-B detected {$hits} time(s).\n"
    : "No BXP-B advertising seen. Press the device's button during the capture.\n");
