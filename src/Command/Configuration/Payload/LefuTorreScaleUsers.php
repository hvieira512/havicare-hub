<?php

declare(strict_types=1);

namespace Hub\Command\Configuration\Payload;

/**
 * Os utilizadores de uma balança Torre, como estado desejado. O identificador é do hub, que o usa
 * como `userID` e `memberID` na balança; o nome é só do hub e nunca vai ao aparelho.
 */
final class LefuTorreScaleUsers extends ConfigurationPayloadBuilder
{
    /**
     * A lista validada, com identificador para quem ainda não o tinha, e quem está em linha, que
     * tem de ser um dos já registados.
     *
     * @param array<string, mixed> $payload
     * @return array{items: list<array<string, mixed>>, onlineUserId?: string}
     */
    public static function withIds(array $payload): array
    {
        $items = $payload['items'] ?? null;
        if (!is_array($items) || !array_is_list($items)) {
            throw new \InvalidArgumentException('items must be a list');
        }

        $users = [];
        foreach ($items as $index => $item) {
            $users[] = self::user(is_array($item) ? $item : [], "items[{$index}]");
        }

        $online = $payload['onlineUserId'] ?? null;
        if ($online === null || $online === '') {
            return ['items' => $users];
        }
        if (!in_array($online, array_column($users, 'id'), true)) {
            throw new \InvalidArgumentException('onlineUserId must be a registered user');
        }

        return ['items' => $users, 'onlineUserId' => $online];
    }

    /**
     * Regista os novos e os que mudaram de perfil, apaga os que saíram, e põe em linha quem foi
     * escolhido, por esta ordem.
     *
     * @param list<array<string, mixed>> $previous
     * @param list<array<string, mixed>> $current
     * @return list<array{command: string, payload: array<string, mixed>, expectedReplyTypes: list<string>}>
     */
    public static function commands(array $previous, array $current, string $previousOnline = '', string $currentOnline = ''): array
    {
        $before = [];
        foreach ($previous as $user) {
            if (is_array($user) && is_string($user['id'] ?? null)) {
                $before[$user['id']] = self::profile($user);
            }
        }

        $commands = [];
        $kept = [];
        foreach ($current as $user) {
            $id = (string)$user['id'];
            $kept[$id] = true;
            $profile = self::profile($user);
            if (($before[$id] ?? null) !== $profile) {
                unset($profile['birthDate']);
                $payload = ['id' => $id, 'ageYears' => self::ageOn($user, gmdate('Y-m-d'))] + $profile;
                $commands[] = ['command' => 'registerScaleUser', 'payload' => $payload, 'expectedReplyTypes' => ['torre_user_02']];
            }
        }
        foreach (array_keys($before) as $id) {
            if (!isset($kept[$id])) {
                $commands[] = ['command' => 'deleteScaleUser', 'payload' => ['id' => (string)$id], 'expectedReplyTypes' => ['torre_user_04']];
            }
        }
        if ($currentOnline !== '' && $currentOnline !== $previousOnline) {
            $commands[] = ['command' => 'selectScaleUser', 'payload' => ['id' => $currentOnline], 'expectedReplyTypes' => ['torre_user_07']];
        }

        return $commands;
    }

    /**
     * A idade num dia `Y-m-d`; quem foi guardado só com a idade fica com ela.
     *
     * @param array<string, mixed> $user
     */
    public static function ageOn(array $user, string $date): int
    {
        $birth = \DateTimeImmutable::createFromFormat('!Y-m-d', (string)($user['birthDate'] ?? ''));
        if ($birth === false) {
            return (int)($user['ageYears'] ?? 0);
        }

        return $birth->diff(new \DateTimeImmutable($date))->y;
    }

    /**
     * @param array<string, mixed> $item
     * @return array<string, mixed>
     */
    private static function user(array $item, string $field): array
    {
        $id = $item['id'] ?? null;
        if ($id !== null && (!is_string($id) || preg_match('/^u-[0-9a-f]{8}$/', $id) !== 1)) {
            throw new \InvalidArgumentException("{$field}.id is not one the hub gave");
        }
        $name = is_string($item['name'] ?? null) ? trim($item['name']) : '';
        if ($name === '' || mb_strlen($name) > 64) {
            throw new \InvalidArgumentException("{$field}.name is required, up to 64 characters");
        }
        $sex = $item['sex'] ?? null;
        if (!in_array($sex, ['male', 'female'], true)) {
            throw new \InvalidArgumentException("{$field}.sex must be male or female");
        }
        $weight = $item['weightKg'] ?? null;
        if (!is_int($weight) && !is_float($weight) || $weight < 0 || $weight > 250) {
            throw new \InvalidArgumentException("{$field}.weightKg must be between 0 and 250");
        }

        return [
            'id' => $id ?? 'u-' . bin2hex(random_bytes(4)),
            'name' => $name,
            'birthDate' => self::birthDate($item['birthDate'] ?? null, "{$field}.birthDate"),
            'sex' => $sex,
            'heightCm' => self::intInRange($item['heightCm'] ?? null, "{$field}.heightCm", 50, 250),
            'athlete' => (bool)self::boolInt($item['athlete'] ?? false, "{$field}.athlete"),
            'weightKg' => round((float)$weight, 2),
        ];
    }

    private static function birthDate(mixed $value, string $field): string
    {
        $date = is_string($value) ? \DateTimeImmutable::createFromFormat('!Y-m-d', $value) : false;
        $age = $date !== false && $date->format('Y-m-d') === $value ? self::ageOn(['birthDate' => $value], gmdate('Y-m-d')) : 0;
        if ($age < 1 || $age > 120) {
            throw new \InvalidArgumentException("{$field} must be a YYYY-MM-DD date, 1 to 120 years ago");
        }

        return (string)$value;
    }

    /**
     * O que muda o registo na balança, com os tipos com que se compara; o nome não vai.
     *
     * @param array<string, mixed> $user
     * @return array<string, mixed>
     */
    private static function profile(array $user): array
    {
        return [
            'birthDate' => (string)($user['birthDate'] ?? ''),
            'sex' => (string)($user['sex'] ?? ''),
            'heightCm' => (int)($user['heightCm'] ?? 0),
            'athlete' => ($user['athlete'] ?? false) === true,
            'weightKg' => round((float)($user['weightKg'] ?? 0), 2),
        ];
    }
}
