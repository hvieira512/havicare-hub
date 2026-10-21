<?php

declare(strict_types=1);

namespace Tests\Unit\Command;

use Hub\Command\Configuration\Payload\LefuTorreScaleUsers;
use Hub\Command\DeviceConfigurationCatalog;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** A lista é o estado desejado: o hub dá identificador a quem não tem e escreve só o que mudou. */
final class LefuTorreScaleUsersTest extends TestCase
{
    private const HUGO = ['name' => 'Hugo', 'birthDate' => '2003-05-10', 'sex' => 'male', 'heightCm' => 181, 'weightKg' => 96];

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
            ['id' => 'u-00000002', 'name' => 'Maria', 'birthDate' => '1965-02-01', 'sex' => 'female', 'heightCm' => 158, 'athlete' => false, 'weightKg' => 64],
        ];
        $current = [
            ['id' => 'u-00000001', 'athlete' => false] + ['weightKg' => 95] + self::HUGO,
            ['id' => 'u-00000003', 'name' => 'João', 'birthDate' => '1952-03-01', 'sex' => 'male', 'heightCm' => 170, 'athlete' => false, 'weightKg' => 82],
        ];

        $commands = DeviceConfigurationCatalog::commandPayloads('lefu-torre', 'scale_users', ['items' => $current], ['previousUsers' => $previous]);

        self::assertSame(
            [
                ['command' => 'registerScaleUser', 'payload' => ['id' => 'u-00000001', 'ageYears' => self::age('2003-05-10'), 'sex' => 'male', 'heightCm' => 181, 'athlete' => false, 'weightKg' => 95.0], 'expectedReplyTypes' => ['torre_user_02']],
                ['command' => 'registerScaleUser', 'payload' => ['id' => 'u-00000003', 'ageYears' => self::age('1952-03-01'), 'sex' => 'male', 'heightCm' => 170, 'athlete' => false, 'weightKg' => 82.0], 'expectedReplyTypes' => ['torre_user_02']],
                ['command' => 'deleteScaleUser', 'payload' => ['id' => 'u-00000002'], 'expectedReplyTypes' => ['torre_user_04']],
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
            [['command' => 'selectScaleUser', 'payload' => ['id' => 'u-00000001'], 'expectedReplyTypes' => ['torre_user_07']]],
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

    /** A idade vem da data de nascimento, e só conta o aniversário quando ele chega. */
    public function testTheAgeIsCountedFromTheBirthDate(): void
    {
        self::assertSame(22, LefuTorreScaleUsers::ageOn(['birthDate' => '2003-05-10'], '2026-05-09'));
        self::assertSame(23, LefuTorreScaleUsers::ageOn(['birthDate' => '2003-05-10'], '2026-05-10'));
    }

    /** Uma pessoa guardada antes de haver data de nascimento continua com a idade que tinha. */
    public function testAUserSavedWithAnAgeKeepsIt(): void
    {
        self::assertSame(23, LefuTorreScaleUsers::ageOn(['ageYears' => 23], '2026-10-19'));
    }

    /** @return iterable<string, array{mixed}> */
    public static function badBirthDates(): iterable
    {
        yield 'without a date' => [null];
        yield 'age instead of the date' => [23];
        yield 'date that does not exist' => ['2003-02-30'];
        yield 'in the future' => ['2999-01-01'];
    }

    #[DataProvider('badBirthDates')]
    public function testTheBirthDateMustBeARealDateInThePast(mixed $birthDate): void
    {
        $this->expectException(\InvalidArgumentException::class);

        LefuTorreScaleUsers::withIds(['items' => [['birthDate' => $birthDate] + self::HUGO]]);
    }

    /** Mudar a data de nascimento regista a pessoa outra vez, com a idade nova. */
    public function testChangingTheBirthDateRegistersAgain(): void
    {
        $before = ['id' => 'u-00000001', 'athlete' => false] + self::HUGO;
        $after = ['birthDate' => '1990-01-01'] + $before;

        $commands = DeviceConfigurationCatalog::commandPayloads('lefu-torre', 'scale_users', ['items' => [$after]], ['previousUsers' => [$before]]);

        self::assertSame(self::age('1990-01-01'), $commands[0]['payload']['ageYears']);
    }

    private static function age(string $birthDate): int
    {
        return LefuTorreScaleUsers::ageOn(['birthDate' => $birthDate], gmdate('Y-m-d'));
    }

    public function testOnlyARegisteredUserCanBeOnline(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        LefuTorreScaleUsers::withIds(['items' => [self::HUGO], 'onlineUserId' => 'u-99999999']);
    }
}
