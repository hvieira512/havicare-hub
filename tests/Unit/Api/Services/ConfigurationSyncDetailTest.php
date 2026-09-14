<?php

namespace Tests\Unit\Api\Services;

use Hub\Api\Services\ConfigurationSyncStatus;
use PHPUnit\Framework\TestCase;

final class ConfigurationSyncDetailTest extends TestCase
{
    public function testACapabilityDeliveredInSeveralCommandsReportsHowFarItGot(): void
    {
        $detail = ConfigurationSyncStatus::operationDetail([
            ['deliveryStatus' => 'acked'],
            ['deliveryStatus' => 'acked'],
            ['deliveryStatus' => 'acked'],
            ['deliveryStatus' => 'waiting'],
            ['deliveryStatus' => 'failed'],
        ]);

        self::assertSame(['confirmed' => 3, 'total' => 5], $detail);
    }

    public function testACapabilityThatTravelsInASingleCommandHasNothingToDetail(): void
    {
        self::assertNull(
            ConfigurationSyncStatus::operationDetail([['deliveryStatus' => 'acked']]),
            'com um só comando o estado de topo já diz tudo'
        );
        self::assertNull(ConfigurationSyncStatus::operationDetail([]));
    }
}
