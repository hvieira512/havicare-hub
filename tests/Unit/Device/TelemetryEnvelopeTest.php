<?php

declare(strict_types=1);

namespace Tests\Unit\Device;

use Hub\Device\TelemetryEnvelope;
use PHPUnit\Framework\TestCase;

/** O descritor do dispositivo tem a mesma forma em todo o contrato: o que não se sabe omite-se. */
final class TelemetryEnvelopeTest extends TestCase
{
    public function testTheDeviceCarriesTheCommercialName(): void
    {
        $envelope = TelemetryEnvelope::for(
            'battery',
            '9f69c4866e6c',
            ['supplier' => 'Wonlex', 'model' => 'MF91', 'commercialName' => 'Havicare MF91'],
            'veepoo-ble',
            'battery',
            ['percent' => 64],
        );

        self::assertSame([
            'id' => '9f69c4866e6c',
            'supplier' => 'Wonlex',
            'model' => 'MF91',
            'commercialName' => 'Havicare MF91',
        ], $envelope['device']);
    }

    public function testWhatIsNotKnownIsOmittedInsteadOfBeingSentEmpty(): void
    {
        $envelope = TelemetryEnvelope::for('battery', 'abc', [], 'veepoo-ble', 'battery', []);

        self::assertSame(['id' => 'abc'], $envelope['device']);
    }
}
