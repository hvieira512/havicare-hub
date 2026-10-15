<?php

declare(strict_types=1);

namespace Tests\Unit\Command;

use Hub\Command\Configuration\Payload\LefuTorreScaleUsers;
use Hub\Command\DeviceConfigurationCatalog;
use PHPUnit\Framework\TestCase;

/** A lista é o estado desejado: o hub dá identificador a quem não tem e escreve só o que mudou. */
final class LefuTorreScaleUsersTest extends TestCase
{
    private const HUGO = ['name' => 'Hugo', 'ageYears' => 23, 'sex' => 'male', 'heightCm' => 181, 'weightKg' => 96];

    public function testANewUserGetsAnIdentifierAndTheAthleteModeOffByDefault(): void
    {
        $users = LefuTorreScaleUsers::withIds(['items' => [self::HUGO]]);

        self::assertMatchesRegularExpression('/^u-[0-9a-f]{8}$/', $users['items'][0]['id']);
        self::assertFalse($users['items'][0]['athlete']);
        self::assertSame('Hugo', $users['items'][0]['name']);
    }

    public function testAnExistingIdentifierIsKept(): void
    {
        $users = LefuTorreScaleUsers::withIds(['items' => [['id' => 'u-0a1b2c3d'] + self::HUGO]]);

        self::assertSame('u-0a1b2c3d', $users['items'][0]['id']);
    }

    /** O nome é do hub e é obrigatório; o resto tem gamas humanas. */
    public function testTheNameIsRequiredAndTheProfileIsChecked(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        LefuTorreScaleUsers::withIds(['items' => [['name' => ' '] + self::HUGO]]);
    }

    /** O registo do Hugo muda, a Maria sai e o João entra: três ordens, e o nome não vai. */
    public function testOnlyWhatChangedIsWrittenAndWhatLeftIsDeleted(): void
    {
        $previous = [
            ['id' => 'u-00000001', 'athlete' => false] + self::HUGO,
            ['id' => 'u-00000002', 'name' => 'Maria', 'ageYears' => 61, 'sex' => 'female', 'heightCm' => 158, 'athlete' => false, 'weightKg' => 64],
        ];
        $current = [
            ['id' => 'u-00000001', 'athlete' => false] + ['weightKg' => 95] + self::HUGO,
            ['id' => 'u-00000003', 'name' => 'João', 'ageYears' => 74, 'sex' => 'male', 'heightCm' => 170, 'athlete' => false, 'weightKg' => 82],
        ];

        $commands = DeviceConfigurationCatalog::commandPayloads('lefu-torre', 'scale_users', ['items' => $current], ['previousUsers' => $previous]);

        self::assertSame(
            [
                ['command' => 'registerScaleUser', 'payload' => ['id' => 'u-00000001', 'ageYears' => 23, 'sex' => 'male', 'heightCm' => 181, 'athlete' => false, 'weightKg' => 95.0]],
                ['command' => 'registerScaleUser', 'payload' => ['id' => 'u-00000003', 'ageYears' => 74, 'sex' => 'male', 'heightCm' => 170, 'athlete' => false, 'weightKg' => 82.0]],
                ['command' => 'deleteScaleUser', 'payload' => ['id' => 'u-00000002']],
            ],
            $commands,
        );
    }

    public function testRenamingDoesNotTouchTheScale(): void
    {
        $user = ['id' => 'u-00000001', 'athlete' => false] + self::HUGO;

        self::assertSame([], DeviceConfigurationCatalog::commandPayloads(
            'lefu-torre',
            'scale_users',
            ['items' => [['name' => 'Hugo V.'] + $user]],
            ['previousUsers' => [$user]],
        ));
    }

    /** A pessoa em linha é parte da lista: muda-se como o resto, e sai depois dos registos. */
    public function testChoosingWhoIsOnlineIsSentWithTheList(): void
    {
        $hugo = ['id' => 'u-00000001', 'athlete' => false] + self::HUGO;

        self::assertSame(
            [['command' => 'selectScaleUser', 'payload' => ['id' => 'u-00000001']]],
            DeviceConfigurationCatalog::commandPayloads(
                'lefu-torre',
                'scale_users',
                ['items' => [$hugo], 'onlineUserId' => 'u-00000001'],
                ['previousUsers' => [$hugo], 'previousOnlineUserId' => ''],
            ),
        );
        self::assertSame([], DeviceConfigurationCatalog::commandPayloads(
            'lefu-torre',
            'scale_users',
            ['items' => [$hugo], 'onlineUserId' => 'u-00000001'],
            ['previousUsers' => [$hugo], 'previousOnlineUserId' => 'u-00000001'],
        ));
    }

    public function testOnlyARegisteredUserCanBeOnline(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        LefuTorreScaleUsers::withIds(['items' => [self::HUGO], 'onlineUserId' => 'u-99999999']);
    }
}
