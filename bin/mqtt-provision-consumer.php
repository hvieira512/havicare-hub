#!/usr/bin/env php
<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$passwdPath = $root . '/config/mosquitto.passwd';
$aclPath = $root . '/config/mosquitto.acl';

$args = parseArgs($argv);

$username = trim((string)($args['username'] ?? ''));
$password = (string)($args['password'] ?? '');
$topicsRaw = trim((string)($args['topics'] ?? 'devices/#'));
$mode = strtolower(trim((string)($args['mode'] ?? 'read')));

if ($username === '' || $password === '') {
    fwrite(STDERR, "Usage: php bin/mqtt-provision-consumer.php --username <user> --password <pass> [--topics topicA,topicB] [--mode read|write|readwrite]\n");
    exit(1);
}

if (!preg_match('/^[a-zA-Z0-9_.-]{3,64}$/', $username)) {
    fwrite(STDERR, "Invalid username format. Use [a-zA-Z0-9_.-] with length 3..64.\n");
    exit(1);
}

if (!in_array($mode, ['read', 'write', 'readwrite'], true)) {
    fwrite(STDERR, "Invalid mode '$mode'. Allowed: read, write, readwrite.\n");
    exit(1);
}

$topics = array_values(array_filter(array_map(
    static fn(string $v): string => trim($v),
    explode(',', $topicsRaw)
), static fn(string $v): bool => $v !== ''));

if ($topics === []) {
    fwrite(STDERR, "At least one MQTT topic is required.\n");
    exit(1);
}

foreach ($topics as $topic) {
    if (str_contains($topic, "\n") || str_contains($topic, "\r")) {
        fwrite(STDERR, "Invalid topic value.\n");
        exit(1);
    }
}

upsertPasswd($passwdPath, $username, $password);
upsertAcl($aclPath, $username, $topics, $mode);

fwrite(STDOUT, "Provisioned MQTT user '$username' in:\n");
fwrite(STDOUT, "- $passwdPath\n");
fwrite(STDOUT, "- $aclPath\n");
fwrite(STDOUT, "Next step: restart broker with `docker compose restart mosquitto`.\n");

function parseArgs(array $argv): array
{
    $result = [];
    $count = count($argv);
    for ($i = 1; $i < $count; $i++) {
        $token = $argv[$i];
        if (!str_starts_with($token, '--')) {
            continue;
        }
        $name = substr($token, 2);
        $value = true;
        if ($i + 1 < $count && !str_starts_with($argv[$i + 1], '--')) {
            $value = $argv[++$i];
        }
        $result[$name] = $value;
    }
    return $result;
}

function upsertPasswd(string $path, string $username, string $password): void
{
    $binary = trim((string)shell_exec('command -v mosquitto_passwd 2>/dev/null'));
    if ($binary === '') {
        fwrite(STDERR, "mosquitto_passwd not found on PATH. Install Mosquitto tools first.\n");
        exit(1);
    }

    if (!file_exists($path)) {
        $cmd = sprintf(
            '%s -b -c %s %s %s',
            escapeshellarg($binary),
            escapeshellarg($path),
            escapeshellarg($username),
            escapeshellarg($password)
        );
        exec($cmd, $_, $exitCode);
        if ($exitCode !== 0) {
            fwrite(STDERR, "Failed to create password file.\n");
            exit(1);
        }
        chmod($path, 0600);
        return;
    }

    $deleteCmd = sprintf('%s -D %s %s', escapeshellarg($binary), escapeshellarg($path), escapeshellarg($username));
    exec($deleteCmd, $_, $deleteExitCode);
    if ($deleteExitCode !== 0) {
        // Ignore not-found user, keep going.
    }

    $addCmd = sprintf(
        '%s -b %s %s %s',
        escapeshellarg($binary),
        escapeshellarg($path),
        escapeshellarg($username),
        escapeshellarg($password)
    );
    exec($addCmd, $_, $addExitCode);
    if ($addExitCode !== 0) {
        fwrite(STDERR, "Failed to upsert password for user '$username'.\n");
        exit(1);
    }
    chmod($path, 0600);
}

function upsertAcl(string $path, string $username, array $topics, string $mode): void
{
    $content = file_exists($path) ? (string)file_get_contents($path) : '';
    $lines = preg_split('/\R/', $content) ?: [];

    $result = [];
    $inUserBlock = false;
    foreach ($lines as $line) {
        $trimmed = trim($line);
        if (str_starts_with($trimmed, 'user ')) {
            $currentUser = trim(substr($trimmed, 5));
            $inUserBlock = ($currentUser === $username);
            if ($inUserBlock) {
                continue;
            }
        }
        if ($inUserBlock) {
            if ($trimmed === '' || str_starts_with($trimmed, 'topic ')) {
                continue;
            }
            $inUserBlock = false;
        }
        $result[] = $line;
    }

    while ($result !== [] && trim((string)end($result)) === '') {
        array_pop($result);
    }

    if ($result !== []) {
        $result[] = '';
    }
    $result[] = 'user ' . $username;
    foreach ($topics as $topic) {
        if ($mode === 'read' || $mode === 'readwrite') {
            $result[] = 'topic read ' . $topic;
        }
        if ($mode === 'write' || $mode === 'readwrite') {
            $result[] = 'topic write ' . $topic;
        }
    }

    file_put_contents($path, implode(PHP_EOL, $result) . PHP_EOL);
    chmod($path, 0600);
}
