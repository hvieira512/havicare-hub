<?php

declare(strict_types=1);

namespace Tests\Unit\Domain;

use Hub\Domain\Capability\Medication\MedicationRemindersCapability;
use Hub\Domain\Capability\Medication\MedicationRemindersHandler;
use PHPUnit\Framework\TestCase;

/**
 * Cada protocolo é servido pelo seu tratador. Os tratadores falsos dizem quem são, para não
 * depender do que cada fornecedor real faz com os dados.
 */
final class MedicationRemindersDispatchTest extends TestCase
{
    private function capability(): MedicationRemindersCapability
    {
        return new MedicationRemindersCapability(
            new NamedRemindersHandler('wonlex'),
            new NamedRemindersHandler('4p'),
            new NamedRemindersHandler('dispensador'),
        );
    }

    /** @return list<array{0: string, 1: string}> */
    public static function protocols(): array
    {
        return [
            ['wonlex-json', 'wonlex'],
            ['four-p-touch', '4p'],
            ['zayata-m228', 'dispensador'],
        ];
    }

    /**
     * @dataProvider protocols
     */
    public function testEachProtocolIsServedByItsOwnHandler(string $protocol, string $expected): void
    {
        $capability = $this->capability();

        self::assertSame(['who' => $expected], $capability->toNative($protocol, []), 'toNative');
        self::assertSame($expected, $capability->fromNative($protocol, '', []), 'fromNative');
        self::assertSame($expected, $capability->defaultValue($protocol), 'defaultValue');
        self::assertSame(['who' => $expected], $capability->meta($protocol), 'meta');
        self::assertSame(
            ['who' => $expected],
            $capability->responseEntry($protocol, '', null, []),
            'responseEntry',
        );
    }

    /** A lista de protocolos suportados sai do mesmo sítio que o despacho, para não discordarem. */
    public function testTheAdvertisedProtocolsAreExactlyTheOnesItServes(): void
    {
        $capability = $this->capability();

        foreach ($capability->supportedProtocols() as $protocol) {
            $capability->toNative($protocol, []);
        }

        self::assertSame(['wonlex-json', 'four-p-touch', 'zayata-m228'], $capability->supportedProtocols());
    }

    public function testAnUnknownProtocolIsRefusedOnTheWayIn(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Unsupported protocol vivistar-iw for medication_reminders');

        $this->capability()->toNative('vivistar-iw', []);
    }

    /**
     * Na leitura, um protocolo sem tratador devolve o que lá está: não rebenta, mas também não
     * descodifica com o tratador de outro.
     */
    public function testAnUnknownProtocolIsNotDecodedBySomebodyElsesHandler(): void
    {
        $desired = ['plans' => [['hour' => 8, 'minute' => 30]]];

        self::assertSame($desired, $this->capability()->fromNative('vivistar-iw', '', $desired));
    }
}

/** Um tratador que só diz o seu nome, para o teste ver por onde a chamada passou. */
final class NamedRemindersHandler implements MedicationRemindersHandler
{
    public function __construct(private readonly string $name)
    {
    }

    public function nativeKey(): string
    {
        return $this->name;
    }

    public function toNative(mixed $value): array
    {
        return ['who' => $this->name];
    }

    public function fromNative(array $desired): mixed
    {
        return $this->name;
    }

    public function defaultValue(): mixed
    {
        return $this->name;
    }

    public function meta(array $accumulatedMeta = []): array
    {
        return ['who' => $this->name];
    }

    public function merge(mixed $existing, mixed $incoming): mixed
    {
        return $this->name;
    }

    public function responseEntry(string $protocol, string $nativeKey, mixed $value, array $meta): array
    {
        return ['who' => $this->name];
    }
}
