<?php

declare(strict_types=1);

namespace Tests\Integration\Api\Services;

use Hub\Api\Repository\ApiDataAccess;
use Hub\Api\Services\ConfigurationLifecyclePresenter;
use Hub\Api\Services\ConfigurationSyncStatus;
use Hub\Api\Services\DeviceCapabilityPresenter;
use Hub\Domain\Capability\CapabilityRegistry;
use Tests\Support\MysqlDashboardTestCase;

/**
 * Tirar uma capacidade do catálogo deixa para trás as mudanças por confirmar dela: nada as
 * suplanta, porque a suplantação só acontece quando alguém volta a escrever a mesma chave.
 * O `rotate_to_cell` do M228 ficou assim três dias a manter o aparelho a vermelho.
 */
final class OrphanConfigurationChangeTest extends MysqlDashboardTestCase
{
    private const IMEI = '869243062262262';

    public function testAChangeForAKeyThatIsNoLongerACapabilityIsNotPresented(): void
    {
        $db = ApiDataAccess::fromDatabase($this->createDashboardDatabase());
        $this->stageFailed($db, 'rotate_to_cell', 'rotateToCell', ['cell' => 1]);

        $presented = $this->present($db);

        self::assertSame([], $presented['configurationSync']['entries'], 'a chave já não é capacidade do protocolo');
        self::assertSame('confirmed', $presented['configurationSync']['status']);
        self::assertSame(0, $presented['configurationSync']['failedCount']);
    }

    public function testAChangeForACapabilityTheProtocolStillHasIsPresented(): void
    {
        $db = ApiDataAccess::fromDatabase($this->createDashboardDatabase());
        $this->stageFailed($db, 'child_lock', 'childLock', ['enabled' => true]);

        $presented = $this->present($db);

        self::assertArrayHasKey('child_lock', $presented['configurationSync']['entries']['health'] ?? []);
        self::assertSame('failed', $presented['configurationSync']['status']);
    }

    /** @param array<string,mixed> $payload */
    private function stageFailed(ApiDataAccess $db, string $key, string $nativeKey, array $payload): void
    {
        $operationId = 'op-' . $key;
        $db->configurationLifecycle->stage(
            self::IMEI,
            $key,
            $payload,
            [[
                'nativeKey' => $nativeKey,
                'protocol' => 'zayata-m228',
                'supplier' => 'Zayata',
                'model' => 'M228',
                'command' => $nativeKey,
                'payload' => $payload,
                'confirmationMode' => 'execution_ack',
                'operationId' => $operationId,
            ]],
            [[
                'operationId' => $operationId,
                'nativeKey' => $nativeKey,
                'nativeType' => $nativeKey,
                'protocol' => 'zayata-m228',
                'bytes' => 'x',
                'expectedReplyTypes' => ['control_ack'],
                'confirmationMode' => 'execution_ack',
                'label' => $key,
            ]],
        );
        $db->configurationLifecycle->updateOperation($operationId, 'failed', 'retry_exhausted');
    }

    /** @return array{effectiveConfigurations:array<string,mixed>,configurationSync:array<string,mixed>} */
    private function present(ApiDataAccess $db): array
    {
        $presenter = new ConfigurationLifecyclePresenter(
            $db->configurationLifecycle,
            new DeviceCapabilityPresenter(new CapabilityRegistry(), $db),
            new ConfigurationSyncStatus(),
        );

        return $presenter->present(self::IMEI, null, 'zayata-m228', [], []);
    }
}
