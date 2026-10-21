<?php

declare(strict_types=1);

namespace Tests\Unit\Domain;

use Hub\Domain\Capability\CapabilityRegistry;
use PHPUnit\Framework\TestCase;

/** Os interruptores com contrato: `{enabled}` nos dois sentidos, debaixo da chave nativa de cada protocolo. */
final class SwitchCapabilityTest extends TestCase
{
    /** @return iterable<string, array{string, string, string}> */
    public static function nativeKeys(): iterable
    {
        yield 'whitelist on vivistar' => ['whitelist_enabled', 'vivistar-iw', 'whitelist_enabled'];
        yield 'whitelist on wonlex' => ['whitelist_enabled', 'wonlex-json', 'wonlexCallInLimitSwitch'];
        yield 'whitelist on four-p-touch' => ['whitelist_enabled', 'four-p-touch', 'rejectUnknownCalls'];
        yield 'sos sms on wonlex' => ['sos_sms_alert', 'wonlex-json', 'wonlexSOSSwitch'];
        yield 'sos sms on four-p-touch' => ['sos_sms_alert', 'four-p-touch', 'sosSmsAlerts'];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('nativeKeys')]
    public function testTheSwitchRoundTripsUnderTheNativeKey(string $key, string $protocol, string $nativeKey): void
    {
        $switch = (new CapabilityRegistry())->get($key);
        self::assertNotNull($switch);

        self::assertSame([$nativeKey => ['enabled' => false]], $switch->toNative($protocol, ['enabled' => false]));
        self::assertSame(['enabled' => true], $switch->fromNative($protocol, $nativeKey, ['enabled' => true]));
    }

    public function testAProtocolWithoutTheSwitchIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new CapabilityRegistry())->get('sos_sms_alert')?->toNative('vivistar-iw', ['enabled' => true]);
    }

    /** Spec DEVREFUSEPHONESWITCH: a restrição do 4P Touch vem desligada de fábrica. */
    public function testTheFactoryValueComesFromTheProtocol(): void
    {
        $registry = new CapabilityRegistry();

        self::assertSame(['enabled' => false], $registry->get('whitelist_enabled')?->defaultValue('four-p-touch'));
        self::assertSame(['enabled' => true], $registry->get('whitelist_enabled')?->defaultValue('vivistar-iw'));
        self::assertSame(['enabled' => true], $registry->get('sos_sms_alert')?->defaultValue('wonlex-json'));
    }

    public function testTheWonlexWhitelistNamesItsContactSources(): void
    {
        self::assertSame(
            ['phonebook', 'sos_contacts'],
            (new CapabilityRegistry())->get('whitelist_enabled')?->meta('wonlex-json')['allowedContactSources'] ?? null,
        );
    }
}
