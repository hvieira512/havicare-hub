<?php

declare(strict_types=1);

namespace Tests\Contract\Protocol;

use App\Protocol\AdapterRegistry;
use PHPUnit\Framework\TestCase;

final class AdapterRegistryContractTest extends TestCase
{
    public function testResolveForModelUsesCatalogProtocol(): void
    {
        $registry = new AdapterRegistry();

        $wonlex = $registry->resolveForModel('WONLEX-PRO');
        $vivistar = $registry->resolveForModel('VIVISTAR-CARE');

        self::assertNotNull($wonlex);
        self::assertNotNull($vivistar);
        self::assertSame('wonlex-json', $wonlex->protocol());
        self::assertSame('vivistar-iw', $vivistar->protocol());
    }

    public function testDecodeAnyDetectsCorrectProtocol(): void
    {
        $registry = new AdapterRegistry();

        $payload = $registry->decodeAny('IWAP49,72#', ['session' => ['imei' => '865028000000308']]);

        self::assertIsArray($payload);
        self::assertSame('AP49', $payload['type']);
        self::assertSame('vivistar-iw', $payload['_protocol']);
    }
}
