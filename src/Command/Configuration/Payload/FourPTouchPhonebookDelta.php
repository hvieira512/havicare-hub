<?php

namespace Hub\Command\Configuration\Payload;

/**
 * Traduz uma lista telefónica desejada nos comandos que a levam ao aparelho.
 *
 * O `PHBX2` endereça um contacto por índice, e o `DPHBX` remove um índice sem
 * renumerar os restantes. Por isso o hub é dono dos índices: guarda-os, nunca os
 * reatribui a um contacto que já lá está, e emite apenas o que mudou.
 *
 * O telefone é a identidade de um contacto. Mudar o nome mantém o índice; mudar
 * o número é outro contacto, e custa uma remoção mais uma escrita.
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
        // Uma lista escrita antes de os índices existirem tem chaves 0..n, que são posições e
        // não endereços no aparelho. Tomá-las por índices escrevia no índice 0, fora da gama
        // que o comando aceita — sem índices de confiança, reescreve-se tudo de raiz.
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
