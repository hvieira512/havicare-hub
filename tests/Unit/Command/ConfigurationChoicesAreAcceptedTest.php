<?php

declare(strict_types=1);

namespace Tests\Unit\Command;

use Hub\Command\DeviceCommandCatalog;
use Hub\Command\DeviceConfigurationCatalog;
use PHPUnit\Framework\TestCase;

/**
 * Uma opção oferecida na lista do dispensador tem de passar nos dois níveis que guardam a gama.
 *
 * O ecrã oferece o que a definição declara, e nada prendia a lista à gama: sobrava uma opção,
 * que era enviada na mesma e o aparelho recusava.
 */
final class ConfigurationChoicesAreAcceptedTest extends TestCase
{
    private const IMEI = '869243062262262';
    private const PROTOCOL = 'zayata-m228';

    /** @return iterable<string, array{string, string, string, int}> */
    public static function choices(): iterable
    {
        foreach (DeviceConfigurationCatalog::configsForProtocol(self::PROTOCOL) as $config) {
            foreach ((array)($config['options'] ?? []) as $field => $options) {
                if (!is_string($field) || !is_array($options)) {
                    continue;
                }
                foreach ($options as $option) {
                    if (!is_array($option) || !is_int($option['value'] ?? null)) {
                        continue;
                    }
                    $name = "{$config['key']} {$field}={$option['value']}";
                    yield $name => [(string)$config['key'], (string)$config['command'], $field, $option['value']];
                }
            }
        }
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('choices')]
    public function testEveryOfferedChoicePasses(string $key, string $command, string $field, int $value): void
    {
        self::assertNull(DeviceConfigurationCatalog::validate(self::PROTOCOL, $key, [$field => $value]));
        self::assertNotSame('', DeviceCommandCatalog::buildDownlink(self::PROTOCOL, self::IMEI, $command, [$field => $value]));
    }
}
