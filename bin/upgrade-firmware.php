#!/usr/bin/env php
<?php

declare(strict_types=1);

// Pede uma actualização de firmware a um dispensador. Não envia nada: regista o pedido, e a
// transferência anda à medida que o aparelho fala — o arranque no primeiro heartbeat, cada
// pedaço na confirmação do anterior.
//
// Uso: php bin/upgrade-firmware.php <imei> <ficheiro.bin> [--apply]

require __DIR__ . '/../vendor/autoload.php';

use Hub\Device\Firmware\FirmwareUpgrade;
use Hub\Device\Firmware\RedisFirmwareUpgradeStore;
use Hub\Runtime\CliBootstrap;
use Hub\Runtime\HubServices;
use Predis\Client as RedisClient;

// O `$argv` só existe com o `register_argc_argv` ligado; o `$_SERVER` traz-no sempre.
$arguments = (array)($_SERVER['argv'] ?? []);
$imei = trim((string)($arguments[1] ?? ''));
$path = trim((string)($arguments[2] ?? ''));
$apply = in_array('--apply', $arguments, true);

if ($imei === '' || $path === '') {
    fwrite(STDERR, "uso: php bin/upgrade-firmware.php <imei> <ficheiro.bin> [--apply]\n");
    exit(1);
}

$firmware = @file_get_contents($path);
if ($firmware === false || $firmware === '') {
    fwrite(STDERR, "não consigo ler {$path}\n");
    exit(1);
}

$size = strlen($firmware);
$checksum = FirmwareUpgrade::checksum($firmware);
$packets = (int)ceil($size / FirmwareUpgrade::CHUNK);

printf("aparelho    %s\n", $imei);
printf("ficheiro    %s\n", $path);
printf("tamanho     %d bytes\n", $size);
printf("checksum    %d (0x%08X)\n", $checksum, $checksum);
printf("pacotes     %d de dados + 1 marcador de fim\n", $packets);

if (!$apply) {
    fwrite(STDOUT, "\nisto é só a contagem. para pedir mesmo, repete com --apply\n");
    exit(0);
}

$config = CliBootstrap::config(__DIR__ . '/..');
$store = new RedisFirmwareUpgradeStore(new RedisClient(
    HubServices::redisParameters($config['redis']),
    HubServices::redisOptions($config['redis']),
));

$store->save($imei, [
    'status' => 'requested',
    'offset' => 0,
    'path' => $path,
    'size' => $size,
    'checksum' => $checksum,
    // O aparelho espera 60 s por resposta e retransmite duas vezes; meia hora cobre a
    // transferência inteira com folga.
    'timeout' => 1800,
    'requestedAt' => gmdate('Y-m-d\TH:i:s\Z'),
]);

fwrite(STDOUT, "\npedido registado. arranca no próximo heartbeat do aparelho.\n");
