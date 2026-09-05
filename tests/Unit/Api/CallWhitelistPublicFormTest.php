<?php

declare(strict_types=1);

namespace Tests\Unit\Api;

use Hub\Api\Services\PublicConfigurationValue;
use PHPUnit\Framework\TestCase;

/**
 * A forma pública do `call_whitelist` era construída por duas funções copiadas — uma para o
 * `configurations`, outra para o `configurationSync`, e podiam derivar. Agora sai de um só
 * `PublicConfigurationValue`; isto fixa a forma por protocolo, que depende do aparelho.
 */
final class CallWhitelistPublicFormTest extends TestCase
{
    public function testVivistarKeepsNamedContactsAndOthersFlattenToNumbers(): void
    {
        $form = new PublicConfigurationValue();

        // O Vivistar leva contactos com nome.
        self::assertSame(
            [['name' => 'Ana', 'phone' => '911'], ['name' => '', 'phone' => '922']],
            $form->forGenericKey('vivistar-iw', 'call_whitelist', ['contacts' => [['name' => 'Ana', 'phone' => '911'], ['name' => '', 'phone' => '922']]]),
        );

        // O 4P Touch, só números — mesmo quando a entrada traz nomes, cai para o telefone.
        self::assertSame(
            ['911', '922'],
            $form->forGenericKey('four-p-touch', 'call_whitelist', ['numbers' => ['911', '922']]),
        );
        self::assertSame(
            ['911'],
            $form->forGenericKey('four-p-touch', 'call_whitelist', ['contacts' => [['name' => 'X', 'phone' => '911']]]),
        );
    }
}
