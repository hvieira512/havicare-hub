#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Encontra um dispositivo BLE pelo que *muda* quando se interage com ele, em vez de por uma
 * assinatura de anúncio conhecida.
 *
 * Regista uma linha de base de tudo o que o gateway já vê e depois reporta cada endereço
 * novo, e cada endereço cujo payload de anúncio mude. A verificação do payload é o que conta:
 * um botão que já anuncia em repouso não aparece como endereço novo, só como alterado.
 *
 * Usage:
 *   php simulator/ble-scan-diff.php                  # 30s baseline, then watch
 *   php simulator/ble-scan-diff.php --baseline=45
 *   php simulator/ble-scan-diff.php --min-rssi=-70   # ignore anything far away
 */

require __DIR__ . '/../vendor/autoload.php';

use Hub\Ingress\Mqtt\Moko\MokoMessageDecoder;
use Hub\Mqtt\BrokerSettings;
use Hub\Mqtt\ConnectionFactory;
use Hub\Runtime\CliBootstrap;

/** Relatórios de scan: 3070 no MKGW3 (JSON), 30a0/30b2 no MKGW4 (binário). */
const SCAN_MESSAGES = [3070, '30a0', '30b2'];

$options = getopt('', ['baseline::', 'min-rssi::', 'topic::', 'help']);
if (isset($options['help'])) {
    fwrite(STDOUT, "Usage: php simulator/ble-scan-diff.php [--baseline=N] [--min-rssi=-70] [--topic=filter]\n");
    exit(0);
}

$baselineSeconds = max(5, (int)($options['baseline'] ?? 30));
$minRssi = isset($options['min-rssi']) ? (int)$options['min-rssi'] : -127;
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

function adTypeName(int $type): string
{
    return [
        0x01 => 'Flags',
        0x02 => 'Incomplete 16-bit UUIDs',
        0x03 => 'Complete 16-bit UUIDs',
        0x08 => 'Shortened local name',
        0x09 => 'Complete local name',
        0x0a => 'Tx power',
        0x16 => 'Service data',
        0xff => 'Manufacturer data',
    ][$type] ?? sprintf('type 0x%02x', $type);
}

function explain(string $advHex, string $rspHex): string
{
    $lines = [];
    foreach ([['ADV', $advHex], ['RSP', $rspHex]] as [$label, $hex]) {
        foreach (adStructures($hex) as $structure) {
            $hexData = implode('', array_map(static fn (int $b): string => sprintf('%02x', $b), $structure['data']));
            $note = '';
            if ($structure['type'] === 0x16 && count($structure['data']) >= 2) {
                $note = sprintf('  <- UUID 0x%02X%02X', $structure['data'][1], $structure['data'][0]);
            }
            if ($structure['type'] === 0xff && count($structure['data']) >= 2) {
                $note = sprintf('  <- company 0x%02X%02X', $structure['data'][1], $structure['data'][0]);
            }
            if ($structure['type'] === 0x09 || $structure['type'] === 0x08) {
                $text = trim(implode('', array_map(
                    static fn (int $b): string => $b >= 32 && $b < 127 ? chr($b) : '.',
                    $structure['data']
                )));
                $note = "  <- \"{$text}\"";
            }
            $lines[] = sprintf('    %s %-24s %s%s', $label, adTypeName($structure['type']), $hexData, $note);
        }
    }

    return implode("\n", $lines);
}

$connections = new ConnectionFactory(BrokerSettings::fromHubConfig($config['mqtt']));
$client = $connections->build('ble-diff-' . substr(bin2hex(random_bytes(3)), 0, 6));

$baseline = [];
$reported = [];
$phase = 'baseline';

$gatewayDecoder = new MokoMessageDecoder();

$handler = function (string $topic, string $message) use (&$baseline, &$reported, &$phase, $minRssi, $gatewayDecoder): void {
    $decoded = $gatewayDecoder->decode($message);
    if (!in_array($decoded['messageId'] ?? null, SCAN_MESSAGES, true) || !is_array($decoded['data'] ?? null)) {
        return;
    }

    foreach ($decoded['data'] as $observation) {
        if (!is_array($observation) || !isset($observation['mac'])) {
            continue;
        }
        $rssi = (int)($observation['rssi'] ?? -127);
        if ($rssi < $minRssi) {
            continue;
        }

        $mac = strtolower((string)$observation['mac']);
        $adv = strtolower((string)($observation['adv_data'] ?? ''));
        $rsp = strtolower((string)($observation['rsp_data'] ?? ''));
        $fingerprint = md5($adv . '|' . $rsp);

        if ($phase === 'baseline') {
            $baseline[$mac][$fingerprint] = true;
            continue;
        }

        $isNewDevice = !isset($baseline[$mac]);
        $isNewPayload = !$isNewDevice && !isset($baseline[$mac][$fingerprint]);
        if (!$isNewDevice && !$isNewPayload) {
            continue;
        }

        // Cada payload distinto é reportado uma vez, para um aparelho falador não inundar.
        $key = $mac . '|' . $fingerprint;
        if (isset($reported[$key])) {
            continue;
        }
        $reported[$key] = true;
        $baseline[$mac][$fingerprint] = true;

        $banner = $isNewDevice ? 'NEW DEVICE' : 'PAYLOAD CHANGED';
        fwrite(STDOUT, sprintf(
            "\n[%s] %s  mac=%s  rssi=%d\n  adv=%s\n  rsp=%s\n%s\n",
            gmdate('H:i:s'),
            $banner,
            $mac,
            $rssi,
            $adv !== '' ? $adv : '(empty)',
            $rsp !== '' ? $rsp : '(empty)',
            explain($adv, $rsp)
        ));
    }
};

$client->subscribe($topicFilter, $handler, 0);

fwrite(STDOUT, "Watching {$topicFilter}\n");
fwrite(STDOUT, "Baseline for {$baselineSeconds}s -- do NOT touch the device yet.\n");
$deadline = microtime(true) + $baselineSeconds;
while (microtime(true) < $deadline) {
    $client->loopOnce(microtime(true), false, 100000);
}

$phase = 'watch';
fwrite(STDOUT, sprintf("Baseline complete: %d addresses.\n", count($baseline)));
fwrite(STDOUT, str_repeat('=', 78) . "\n");
fwrite(STDOUT, "NOW press the button (single, double, long). Ctrl-C to stop.\n");
fwrite(STDOUT, str_repeat('=', 78) . "\n");

while (true) {
    $client->loopOnce(microtime(true), false, 100000);
}
