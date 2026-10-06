<?php

declare(strict_types=1);

namespace Hub\Command\Configuration\Payload;

/**
 * Traduz a lista telefónica desejada nos comandos que a levam ao aparelho. O `DPHBX` remove
 * sem renumerar, por isso o hub é dono dos índices; o telefone é a identidade do contacto.
 */
final class FourPTouchPhonebookDelta
{
    public const MAX_CONTACTS = 100;

    /**
     * As remoções saem antes das escritas, para que um índice libertado possa ser
     * reocupado na mesma passagem.
     *
     * @param array<int, array<string, mixed>> $previous índice => contacto
     * @param list<array<string, mixed>> $desired
     * @return list<array{command: string, fields: list<string>}>
     */
    public static function commands(array $previous, array $desired): array
    {
        return self::resolve($previous, $desired)['commands'];
    }

    /**
     * A reescrita total, para quando aparelho e hub divergiram: sem comando de leitura, só varrer
     * os índices todos apaga um contacto escrito por fora.
     *
     * @param list<array<string, mixed>> $desired
     * @return list<array{command: string, fields: list<string>}>
     */
    public static function resyncCommands(array $desired): array
    {
        $writes = self::commands([], $desired);
        $usedSlots = [];
        foreach ($writes as $write) {
            $usedSlots[(int)$write['fields'][0]] = true;
        }

        $commands = [];
        for ($index = 1; $index <= self::MAX_CONTACTS; $index++) {
            if (!isset($usedSlots[$index])) {
                $commands[] = ['command' => 'DPHBX', 'fields' => [(string)$index]];
            }
        }

        return array_merge($commands, $writes);
    }

    /**
     * O estado anterior em que o delta pode assentar: só com a última entrega confirmada, e
     * nunca uma lista de chaves 0..n, que são posições e não índices.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function trustedPrevious(mixed $stored, string $lastStatus): array
    {
        $entregue = !in_array(
            $lastStatus,
            ['created', 'failed', 'retry_exhausted', 'response_timeout', 'dropped'],
            true
        );
        if (!$entregue || !is_array($stored) || array_is_list($stored)) {
            return [];
        }

        return $stored;
    }

    /**
     * A atribuição de índices que fica no aparelho depois de os comandos passarem. É o que
     * o hub guarda, e o que serve de estado anterior à alteração seguinte.
     *
     * @param array<int, array<string, mixed>> $previous
     * @param list<array<string, mixed>> $desired
     * @return array<int, array{name: string, phone: string}>
     */
    public static function indexed(array $previous, array $desired): array
    {
        return self::resolve($previous, $desired)['contacts'];
    }

    /**
     * @param array<int, array<string, mixed>> $previous
     * @param list<array<string, mixed>> $desired
     * @return array{commands: list<array{command: string, fields: list<string>}>, contacts: array<int, array{name: string, phone: string}>}
     */
    private static function resolve(array $previous, array $desired): array
    {
        // Chaves 0..n são posições e não índices do aparelho: sem índices de confiança, reescreve-se
        // tudo de raiz.
        if (array_is_list($previous)) {
            $previous = [];
        }

        $byPhone = [];
        foreach ($previous as $index => $contact) {
            $byPhone[(string)($contact['phone'] ?? '')] = (int)$index;
        }

        $contacts = [];
        $writes = [];
        $unassigned = [];

        foreach ($desired as $contact) {
            $phone = (string)($contact['phone'] ?? '');
            $name = (string)($contact['name'] ?? '');
            $index = $byPhone[$phone] ?? null;
            if ($index === null) {
                $unassigned[] = ['name' => $name, 'phone' => $phone];
                continue;
            }

            $contacts[$index] = ['name' => $name, 'phone' => $phone];
            if ((string)($previous[$index]['name'] ?? '') !== $name) {
                $writes[$index] = true;
            }
        }

        $deletes = [];
        foreach (array_keys($previous) as $index) {
            if (!isset($contacts[(int)$index])) {
                $deletes[] = (int)$index;
            }
        }
        sort($deletes);

        foreach ($unassigned as $contact) {
            $index = self::firstFreeIndex($contacts);
            $contacts[$index] = $contact;
            $writes[$index] = true;
        }

        ksort($contacts);

        $commands = [];
        foreach ($deletes as $index) {
            $commands[] = ['command' => 'DPHBX', 'fields' => [(string)$index]];
        }
        foreach ($contacts as $index => $contact) {
            if (!isset($writes[$index])) {
                continue;
            }
            $commands[] = [
                'command' => 'PHBX2',
                'fields' => [(string)$index, self::utf16Hex($contact['name']), $contact['phone']],
            ];
        }

        return ['commands' => $commands, 'contacts' => $contacts];
    }

    /**
     * @param array<int, array{name: string, phone: string}> $taken
     */
    private static function firstFreeIndex(array $taken): int
    {
        for ($index = 1; $index <= self::MAX_CONTACTS; $index++) {
            if (!isset($taken[$index])) {
                return $index;
            }
        }

        throw new \InvalidArgumentException(
            'contacts must not exceed ' . self::MAX_CONTACTS . ' items'
        );
    }

    private static function utf16Hex(string $value): string
    {
        $encoded = iconv('UTF-8', 'UTF-16BE//IGNORE', $value);

        return $encoded === false ? '' : strtoupper(bin2hex($encoded));
    }
}
