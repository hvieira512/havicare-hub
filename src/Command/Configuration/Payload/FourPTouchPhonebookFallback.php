<?php

namespace Hub\Command\Configuration\Payload;

/**
 * A lista telefónica na forma antiga, para os aparelhos que não entendem o `PHBX2`.
 *
 * O `PHB` leva a lista inteira numa trama, em pares número/nome, e o `PHB2` continua-a da
 * sexta à décima posição. O que passa daí não cabe no comando e fica de fora.
 */
final class FourPTouchPhonebookFallback
{
    private const PER_COMMAND = 5;
    private const NAME_MAX_LENGTH = 10;

    /**
     * @param list<array{name: string, phone: string}> $contacts
     * @return list<array{command: string, fields: list<string>}>
     */
    public static function commands(array $contacts): array
    {
        $batches = array_chunk(
            array_slice($contacts, 0, self::PER_COMMAND * 2),
            self::PER_COMMAND
        );
        if ($batches === []) {
            $batches = [[]];
        }

        $commands = [];
        foreach ($batches as $position => $batch) {
            $fields = [];
            foreach ($batch as $contact) {
                $fields[] = (string)($contact['phone'] ?? '');
                $fields[] = self::utf16Hex(self::cutName((string)($contact['name'] ?? '')));
            }
            $commands[] = [
                'command' => $position === 0 ? 'PHB' : 'PHB2',
                'fields' => $fields,
            ];
        }

        return $commands;
    }

    /**
     * O recuo só se justifica quando o aparelho não confirmou uma única escrita. Um envio
     * perdido entre vários confirmados é isso mesmo, e repete-se como qualquer outro — recuar
     * aí reescrevia a lista inteira na forma antiga e cortava-a nos cinco primeiros.
     *
     * @param list<array<string, mixed>> $commands os comandos da lista telefónica do aparelho
     */
    public static function shouldFallBack(array $commands): bool
    {
        $indexed = array_filter(
            $commands,
            static fn(array $command): bool => in_array(
                (string)($command['nativeType'] ?? ''),
                ['PHBX2', 'DPHBX'],
                true
            )
        );
        if ($indexed === []) {
            return false;
        }

        foreach ($indexed as $command) {
            if ((string)($command['status'] ?? '') !== 'failed') {
                return false;
            }
        }

        return true;
    }

    /**
     * A lista que as escritas por confirmar transportavam, pela ordem do índice.
     *
     * @param list<array<string, mixed>> $commands
     * @return list<array{name: string, phone: string}>
     */
    public static function contactsFromCommands(array $commands): array
    {
        $byIndex = [];
        foreach ($commands as $command) {
            if ((string)($command['nativeType'] ?? '') !== 'PHBX2') {
                continue;
            }
            $fields = $command['payload']['fields'] ?? null;
            if (!is_array($fields) || count($fields) < 3) {
                continue;
            }
            $byIndex[(int)$fields[0]] = [
                'name' => self::fromUtf16Hex((string)$fields[1]),
                'phone' => (string)$fields[2],
            ];
        }
        ksort($byIndex);

        return array_values($byIndex);
    }

    private static function fromUtf16Hex(string $hex): string
    {
        $bytes = @hex2bin($hex);
        if ($bytes === false) {
            return '';
        }
        $decoded = iconv('UTF-16BE', 'UTF-8//IGNORE', $bytes);

        return $decoded === false ? '' : $decoded;
    }

    private static function cutName(string $name): string
    {
        if (mb_strlen($name, 'UTF-8') <= self::NAME_MAX_LENGTH) {
            return $name;
        }

        return mb_substr($name, 0, self::NAME_MAX_LENGTH, 'UTF-8');
    }

    private static function utf16Hex(string $value): string
    {
        $encoded = iconv('UTF-8', 'UTF-16BE//IGNORE', $value);

        return $encoded === false ? '' : strtoupper(bin2hex($encoded));
    }
}
