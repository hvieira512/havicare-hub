<?php

declare(strict_types=1);

namespace Tests\Integration\Api;

use Hub\Api\Services\CapabilityDiscoveryService;
use Hub\Api\Services\DashboardNotificationService;
use Hub\Api\Services\DenylistService;
use Hub\Infrastructure\Persistence\Repository\ApiDataAccess;
use Hub\Infrastructure\Persistence\Repository\CapabilityDiscoveryRepository;
use Tests\Support\MysqlDashboardTestCase;

/**
 * Um corpo recusado diz que campo é que está mal.
 *
 * Havia dois padrões: sete serviços validavam por DTO e devolviam `error.fields`, e outros
 * validavam à mão e devolviam só uma frase. Quem integra tinha de descobrir o campo a partir
 * do texto da mensagem, que não é contrato.
 *
 * Quatro serviços ficam de fora de propósito, e não por esquecimento:
 *
 * - o `DeviceConfigurationUpdateService` e o `DeviceFeatureRequestService` recebem um corpo
 *   cuja forma é declarada pela capacidade em tempo de execução, e um DTO fixo não a exprime;
 * - o `RadarLayoutService` não leva corpo nenhum;
 * - o `AuthService::login` despacha entre três formas de corpo -- par de credenciais, token
 *   de renovação e sessão por cookie --, e um `NotBlank` no par recusaria uma renovação.
 */
final class ValidationIsUniformTest extends MysqlDashboardTestCase
{
    public function testARefusedBodySaysWhichFieldIsWrong(): void
    {
        $db = ApiDataAccess::fromDatabase($this->createDashboardDatabase());

        $refusals = [
            'denylist' => (new DenylistService($db))->block([], 'admin'),
            'notifications' => (new DashboardNotificationService($db))->markRead([]),
            'capability-discovery' => (new CapabilityDiscoveryService(
                $db,
                $this->createStub(\Hub\Api\Services\DeviceService::class),
                new CapabilityDiscoveryRepository(sys_get_temp_dir() . '/capability-discovery-test'),
            ))->preview([]),
        ];

        $without = [];
        foreach ($refusals as $name => $result) {
            if (($result['error']['code'] ?? null) !== 'invalid_request') {
                $without[] = "{$name}: não recusou um corpo vazio";
                continue;
            }
            if (!is_array($result['error']['fields'] ?? null) || $result['error']['fields'] === []) {
                $without[] = "{$name}: recusou sem dizer que campo";
            }
        }

        self::assertSame([], $without);
    }
}
