#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Watches one BLE device until it broadcasts something.
 *
 * A W6R with no advertising slot configured is visible to the gateway but
 * carries no advertising data at all, so this reports the empty state as a
 * heartbeat and shouts as soon as a real payload appears -- then decodes it, so
 * you can see immediately whether the frame is what we expect.
 *
 * Runs until Ctrl-C.
 *
 * Usage:
 *   php simulator/w6r-watch.php
 *   php simulator/w6r-watch.php --mac=fbd87c59ba8b --heartbeat=30
 */

require __DIR__ . '/../vendor/autoload.php';

use Hub\Ingress\Mqtt\Moko\MokoMessageDecoder;
use Hub\Ingress\Mqtt\Moko\W6rDecoder;
use Hub\Mqtt\BrokerSettings;
use Hub\Mqtt\ConnectionFactory;
use Hub\Runtime\CliBootstrap;

/** Relatórios de scan: 3070 no MKGW3 (JSON), 30a0/30b2 no MKGW4 (binário). */
const SCAN_MESSAGES = [3070, '30a0', '30b2'];

$options = getopt('', ['mac::', 'heartbeat::', 'topic::', 'help']);
if (isset($options['help'])) {
    fwrite(STDOUT, "Usage: php simulator/w6r-watch.php [--mac=AABBCC...] [--heartbeat=30] [--topic=filter]\n");
    exit(0);
}

$target = strtolower(trim((string)($options['mac'] ?? 'fbd87c59ba8b')));
$heartbeat = max(5, (int)($options['heartbeat'] ?? 30));
$config = CliBootstrap::config(__DIR__ . '/..');
$topicFilter = trim((string)($options['topic'] ?? $config['moko']['topic_filter']));

/** @return list<array{type: int, data: list<int>}> */
function adStructures(string $hex): array
{
    $hex = strtolower(trim($hex));
    if ($hex === '' || strlen($hex) % 2 !== 0 || preg_match('/^[0-9a-f]+$/', $hex) !== 1) {
        return [];
    }
    $bytes = array_values(unpack('C*', hex2bin($hex) ?: ''));
    $out = [];
    $offset = 0;
    $count = count($bytes);
    while ($offset < $count) {
        $length = $bytes[$offset];
        if ($length === 0 || $offset + $length >= $count + 1) {
            break;
        }
        $out[] = ['type' => $bytes[$offset + 1] ?? 0, 'data' => array_slice($bytes, $offset + 2, $length - 1)];
        $offset += $length + 1;
    }
    return $out;
}

function describe(string $label, string $hex): string
{
    $lines = [];
    foreach (adStructures($hex) as $s) {
        $data = implode('', array_map(static fn(int $b): string => sprintf('%02x', $b), $s['data']));
        $note = '';
        if ($s['type'] === 0x16 && count($s['data']) >= 2) {
            $uuid = sprintf('%02X%02X', $s['data'][1], $s['data'][0]);
            $known = ['FEE0' => ' <- MOKO alarm frame', 'EA00' => ' <- MOKO device info'][$uuid] ?? '';
            $note = "  service data UUID 0x{$uuid}{$known}";
        } elseif ($s['type'] === 0xff && count($s['data']) >= 2) {
            $note = sprintf('  manufacturer 0x%02X%02X', $s['data'][1], $s['data'][0]);
        } elseif ($s['type'] === 0x09 || $s['type'] === 0x08) {
            $note = '  name "' . trim(implode('', array_map(
                static fn(int $b): string => $b >= 32 && $b < 127 ? chr($b) : '.',
                $s['data']
            ))) . '"';
        }
        $lines[] = sprintf('    %s type 0x%02x  %s%s', $label, $s['type'], $data, $note);
    }
    return implode("\n", $lines);
}

$connections = new ConnectionFactory(BrokerSettings::fromHubConfig($config['mqtt']));
$client = $connections->build('w6r-watch-' . substr(bin2hex(random_bytes(3)), 0, 6));
$decoder = new W6rDecoder();

$state = ['sightings' => 0, 'empty' => 0, 'lastSeen' => null, 'lastRssi' => null, 'lastGateway' => null, 'payloads' => []];

fwrite(STDOUT, "Watching {$target} on {$topicFilter}\n");
fwrite(STDOUT, "Reporting every {$heartbeat}s. Ctrl-C to stop.\n");
fwrite(STDOUT, str_repeat('-', 72) . "\n");

$gatewayDecoder = new MokoMessageDecoder();

$client->subscribe($topicFilter, function (string $unusedTopic, string $message) use ($target, &$state, $decoder, $gatewayDecoder): void {
    $decoded = $gatewayDecoder->decode($message);
    if (!in_array($decoded['messageId'] ?? null, SCAN_MESSAGES, true) || !is_array($decoded['data'] ?? null)) {
        return;
    }
    $gateway = strtolower((string)($decoded['gatewayMac'] ?? '?'));

    foreach ($decoded['data'] as $observation) {
        if (!is_array($observation) || strtolower((string)($observation['mac'] ?? '')) !== $target) {
            continue;
        }

        $adv = strtolower((string)($observation['adv_data'] ?? ''));
        $rsp = strtolower((string)($observation['rsp_data'] ?? ''));
        $state['sightings']++;
        $state['lastSeen'] = gmdate('H:i:s');
        $state['lastRssi'] = (int)($observation['rssi'] ?? 0);
        $state['lastGateway'] = $gateway;

        // A MKGW3 reports MOKO beacons already parsed, so there is usually no
        // raw hex at all: ask the decoder rather than looking for bytes.
        $result = $decoder->decode($observation);
        if ($result === null) {
            $state['empty']++;
            continue;
        }

        $key = json_encode([$result['alarm']['pressMode'] ?? null, $result['alarm']['triggerCount'] ?? null, $result['info'] ?? null]);
        if (isset($state['payloads'][$key])) {
            continue;
        }
        $state['payloads'][$key] = true;

        fwrite(STDOUT, "\n" . str_repeat('=', 72) . "\n");
        fwrite(STDOUT, "  PAYLOAD RECEIVED  {$state['lastSeen']}  gateway={$gateway}  rssi={$state['lastRssi']}\n");
        fwrite(STDOUT, str_repeat('=', 72) . "\n");
        fwrite(STDOUT, "  adv=" . ($adv !== '' ? $adv : '(empty)') . "\n");
        fwrite(STDOUT, "  rsp=" . ($rsp !== '' ? $rsp : '(empty)') . "\n");
        foreach (['ADV' => $adv, 'RSP' => $rsp] as $label => $hex) {
            $described = describe($label, $hex);
            if ($described !== '') {
                fwrite(STDOUT, $described . "\n");
            }
        }

        fwrite(STDOUT, "  observation: " . json_encode($observation) . "\n");
        fwrite(STDOUT, "\n  W6rDecoder DECODED IT:\n");
        fwrite(STDOUT, '    ' . str_replace("\n", "\n    ", trim(print_r($result, true))) . "\n");
        fwrite(STDOUT, str_repeat('=', 72) . "\n\n");
    }
}, 0);

$nextReport = microtime(true) + $heartbeat;
while (true) {
    $client->loopOnce(microtime(true), false, 100000);

    if (microtime(true) < $nextReport) {
        continue;
    }
    $nextReport = microtime(true) + $heartbeat;

    if ($state['sightings'] === 0) {
        fwrite(STDOUT, sprintf("[%s] not seen yet -- device is not advertising at all\n", gmdate('H:i:s')));
        continue;
    }

    fwrite(STDOUT, sprintf(
        "[%s] %d sighting(s), %d with no advertising data | last %s rssi=%d via %s | real payloads: %d\n",
        gmdate('H:i:s'),
        $state['sightings'],
        $state['empty'],
        (string)$state['lastSeen'],
        (int)$state['lastRssi'],
        (string)$state['lastGateway'],
        count($state['payloads'])
    ));
}
