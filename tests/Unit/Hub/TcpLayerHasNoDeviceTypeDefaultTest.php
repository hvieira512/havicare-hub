<?php

declare(strict_types=1);

namespace Tests\Unit\Hub;

use Hub\Device\DeviceHubServer;
use Hub\Device\DeviceSession;
use PHPUnit\Framework\TestCase;

/**
 * A camada TCP não tem tipo de dispositivo por omissão: um `'watch'` por omissão publica um
 * dispensador debaixo de `/watch/` sem erro nenhum. O tipo sai da whitelist.
 */
final class TcpLayerHasNoDeviceTypeDefaultTest extends TestCase
{
    public function testTheSessionDoesNotInventADeviceType(): void
    {
        $reflection = new \ReflectionClass(DeviceSession::class);

        foreach ($reflection->getMethods() as $method) {
            foreach ($method->getParameters() as $parameter) {
                if ($parameter->getName() !== 'deviceType' || !$parameter->isDefaultValueAvailable()) {
                    continue;
                }

                self::assertNotSame(
                    'watch',
                    $parameter->getDefaultValue(),
                    "DeviceSession::{$method->getName()} assume que um aparelho TCP é um relógio.",
                );
            }
        }
    }

    public function testTheHubServerDoesNotInventADeviceType(): void
    {
        $reflection = new \ReflectionClass(DeviceHubServer::class);
        $assuming = [];

        foreach ($reflection->getMethods() as $method) {
            foreach ($method->getParameters() as $parameter) {
                if ($parameter->getName() !== 'deviceType' || !$parameter->isDefaultValueAvailable()) {
                    continue;
                }
                if ($parameter->getDefaultValue() === 'watch') {
                    $assuming[] = $method->getName();
                }
            }
        }

        self::assertSame([], $assuming, 'Estes métodos assumem que um aparelho TCP é um relógio.');
    }

    /** Nem no código: um `?? 'watch'` escapa a qualquer verificação de assinaturas. */
    public function testNoSourceLineFallsBackToWatch(): void
    {
        $files = [
            'src/Device/DeviceHubServer.php',
            'src/Device/DeviceSession.php',
            'src/Ingress/Tcp/HubTcpIngress.php',
        ];

        $lines = [];
        foreach ($files as $file) {
            $contents = file_get_contents(dirname(__DIR__, 3) . '/' . $file);
            self::assertIsString($contents, $file);

            foreach (explode("\n", $contents) as $number => $line) {
                if (str_contains($line, "'watch'")) {
                    $lines[] = $file . ':' . ($number + 1) . ' -> ' . trim($line);
                }
            }
        }

        self::assertSame([], $lines);
    }
}
