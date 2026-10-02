<?php

declare(strict_types=1);

namespace Tests\Unit\Command;

use Hub\Command\Configuration\Definition\VeepooConfigurationDefinitions;
use Hub\Command\DeviceCommandCatalog;
use Hub\Command\DeviceConfigurationCatalog;
use PHPUnit\Framework\TestCase;

/**
 * A forma do que sai para o gateway de uma pulseira Veepoo.
 *
 * O hub não monta trama nenhuma aqui: o payload viaja genérico até ao SDK, que o aceita em
 * silêncio quando não o reconhece. Validar os valores não chega -- quem integra lê estes
 * nomes.
 */
final class VeepooPayloadShapeTest extends TestCase
{
    private const PROTOCOL = 'veepoo-ble';
    private const IMEI = 'dba376003185';

    /**
     * Um payload aceitável por configuração, e a forma exacta com que ela sai.
     *
     * @return array<string, array{0: string, 1: array<string, mixed>, 2: array<string, mixed>}>
     */
    public static function configurations(): array
    {
        return [
            'tom de pele' => ['skin_tone', ['level' => 4], ['level' => 4]],
            'tom de pele em texto' => ['skin_tone', ['level' => '4'], ['level' => 4]],
            'dados para cálculo' => [
                'personal_info',
                ['heightCm' => 175, 'weightKg' => 72, 'age' => 34, 'sex' => 'male', 'stepGoal' => 8000, 'sleepGoalMinutes' => 480],
                ['heightCm' => 175, 'weightKg' => 72, 'age' => 34, 'sex' => 'male', 'stepGoal' => 8000, 'sleepGoalMinutes' => 480],
            ],
            'alerta de frequência cardíaca' => [
                'heart_rate_alert',
                ['enabled' => 1, 'maxBpm' => '150', 'minBpm' => '50'],
                ['enabled' => true, 'maxBpm' => 150, 'minBpm' => 50],
            ],
            'janela de oxigénio' => [
                'blood_oxygen_window',
                ['enabled' => true, 'range' => '22:00-08:00'],
                ['enabled' => true, 'range' => '22:00-08:00'],
            ],
            'interruptor de saúde' => ['heart_rate_continuous', ['enabled' => false], ['enabled' => false]],
            'interruptor de alarme' => ['blood_oxygen_alert', ['enabled' => true], ['enabled' => true]],
            'acção transiente' => ['find_device', ['enabled' => true], ['enabled' => true]],
        ];
    }

    /**
     * @dataProvider configurations
     * @param array<string, mixed> $input
     * @param array<string, mixed> $expected
     */
    public function testEachConfigurationProducesTheDeclaredPayload(string $key, array $input, array $expected): void
    {
        $built = DeviceConfigurationCatalog::commandPayload(self::PROTOCOL, $key, $input);

        self::assertSame('config:' . $key, $built['command']);
        // Identidade estrita: um campo a mais vai no fio para quem integra ler.
        self::assertSame($expected, $built['payload']);
    }

    /**
     * O que desce é o nome da operação, e não uma trama: quem escreve na pulseira é o gateway
     * que tem a sessão BLE.
     *
     * @dataProvider configurations
     * @param array<string, mixed> $input
     * @param array<string, mixed> $expected
     */
    public function testTheQueuedBytesAreTheOperationName(string $key, array $input, array $expected): void
    {
        $command = DeviceConfigurationCatalog::commandPayload(self::PROTOCOL, $key, $input)['command'];

        self::assertSame($command, DeviceCommandCatalog::buildDownlink(self::PROTOCOL, self::IMEI, $command));
    }

    /**
     * Um campo declarado que o construtor não escreve nunca chega à pulseira, e nada o diz:
     * o SDK ignora o que não reconhece e a configuração fica por aplicar.
     */
    public function testEveryDeclaredFieldIsWrittenIntoThePayload(): void
    {
        $samples = [];
        foreach (self::configurations() as [$key, $input, $expected]) {
            $samples[$key] = $input;
        }

        foreach (VeepooConfigurationDefinitions::all() as $definition) {
            $key = (string)$definition['key'];
            $input = $samples[$key] ?? ['enabled' => true];
            $built = DeviceConfigurationCatalog::commandPayload(self::PROTOCOL, $key, $input);

            self::assertSame(
                $definition['fields'],
                array_keys($built['payload']),
                "`{$key}` declara campos que o payload não leva",
            );
        }
    }
}
