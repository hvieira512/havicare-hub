<?php

declare(strict_types=1);

namespace Tests\Unit\Hub;

use Hub\Device\DeviceHubServer;
use Hub\Device\DeviceSession;
use PHPUnit\Framework\TestCase;

/**
 * A camada TCP não pode ter um tipo de dispositivo por omissão.
 *
 * Um `deviceType = 'watch'` por omissão não dá erro nenhum: dá telemetria publicada no tópico
 * errado, e quem consome o contrato recebe um dispensador debaixo de `/watch/` e acredita. O
 * tipo sai da whitelist, e quem não estiver na whitelist não chega aqui.
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
        $assumem = [];

        foreach ($reflection->getMethods() as $method) {
            foreach ($method->getParameters() as $parameter) {
                if ($parameter->getName() !== 'deviceType' || !$parameter->isDefaultValueAvailable()) {
                    continue;
                }
                if ($parameter->getDefaultValue() === 'watch') {
                    $assumem[] = $method->getName();
                }
            }
        }

        self::assertSame([], $assumem, 'Estes métodos assumem que um aparelho TCP é um relógio.');
    }

    /**
     * E o código também não. O `?? 'watch'` é o mesmo defeito escrito de outra maneira, e
     * escapa a qualquer verificação de assinaturas.
     */
    public function testNoSourceLineFallsBackToWatch(): void
    {
        $ficheiros = [
            'src/Device/DeviceHubServer.php',
            'src/Device/DeviceSession.php',
            'src/Device/HubTcpIngress.php',
        ];

        $linhas = [];
        foreach ($ficheiros as $ficheiro) {
            $conteudo = file_get_contents(dirname(__DIR__, 3) . '/' . $ficheiro);
            self::assertIsString($conteudo, $ficheiro);

            foreach (explode("\n", $conteudo) as $numero => $linha) {
                if (str_contains($linha, "'watch'")) {
                    $linhas[] = $ficheiro . ':' . ($numero + 1) . ' -> ' . trim($linha);
                }
            }
        }

        self::assertSame([], $linhas);
    }
}
