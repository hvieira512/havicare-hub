#!/usr/bin/env php
<?php

require __DIR__ . '/../vendor/autoload.php';

use App\Bootstrap;
use App\Repository\DeviceRepository;
use App\Repository\EventRepository;
use App\Log\Logger;

$config = Bootstrap::config();
$dbConfig = $config['database'] ?? null;
$redisConfig = $config['redis'] ?? [];

$workerId = (gethostname() ?: 'worker') . ':' . getmypid();
$streamKey = 'events';
$groupName = 'stream:worker';
$consumerName = $workerId;
$totalProcessed = 0;
$running = true;

Logger::channel('worker')->info('Starting (PID: ' . getmypid() . ')');

$pdo = Bootstrap::requireDatabase($dbConfig);
$eventsRepo = new EventRepository($pdo);
$devicesRepo = new DeviceRepository($pdo);

$redis = Bootstrap::requireRedis($redisConfig);

$redis->xGroupCreate($groupName, $streamKey, '0', true);
Logger::channel('worker')->info("Group '{$groupName}' ready on stream '{$streamKey}'");

if (extension_loaded('pcntl')) {
    pcntl_signal(SIGINT, function () use (&$running) {
        Logger::channel('worker')->info('SIGINT received. Shutting down gracefully...');
        $running = false;
    });
    pcntl_signal(SIGTERM, function () use (&$running) {
        Logger::channel('worker')->info('SIGTERM received. Shutting down gracefully...');
        $running = false;
    });
}

Logger::channel('worker')->info("Consuming events from '{$streamKey}' (consumer: {$consumerName})");

while ($running) {
    if (extension_loaded('pcntl')) {
        pcntl_signal_dispatch();
    }

    try {
        $messages = $redis->xReadGroup($groupName, $consumerName, 50, 2000);
    } catch (\Throwable $e) {
        Logger::channel('worker')->error("xReadGroup: {$e->getMessage()}");
        sleep(1);
        continue;
    }

    if (empty($messages)) {
        continue;
    }

    $ackIds = [];
    foreach ($messages as $event) {
        try {
            $devicesRepo->ensureExists($event['imei']);
            $eventsRepo->insert($event);
            $ackIds[] = $event['streamId'];
            $totalProcessed++;

            if ($totalProcessed % 100 === 0) {
                Logger::channel('worker')->info("{$totalProcessed} events processed");
            }
        } catch (\Throwable $e) {
            Logger::channel('worker')->error("Failed to insert event {$event['streamId']}: {$e->getMessage()}");
            $ackIds[] = $event['streamId'];
        }
    }

    if (!empty($ackIds)) {
        try {
            $ackd = $redis->xAck($streamKey, $groupName, $ackIds);
            if ($ackd !== count($ackIds)) {
                Logger::channel('worker')->warning("Acknowledged {$ackd}/" . count($ackIds) . " events");
            }
        } catch (\Throwable $e) {
            Logger::channel('worker')->error("xAck: {$e->getMessage()}");
        }
    }
}

Logger::channel('worker')->info("Stopped. Total processed: {$totalProcessed} events");
