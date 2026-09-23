<?php

declare(strict_types=1);

namespace Tests\Unit\Command;

use Hub\Command\DeviceConfigurationCatalog;
use Hub\Command\DeviceCommandCatalog;
use Hub\Domain\Capability\CapabilityCatalog;
use PHPUnit\Framework\TestCase;

/**
 * Uma capacidade marcada como pedível tem de ter por onde ser pedida.
 *
 * Há dois caminhos e os dois valem: um comando `kind: request`, que dá mosaico no ecrã
 * principal, ou uma acção transitória no catálogo de configuração, que dá botão no modal. O
 * que não pode existir é a bandeira sem nenhum dos dois — era metadado a prometer uma coisa
 * que não estava ligada a lado nenhum.
 */
final class PillDispenserRequestableIsReachableTest extends TestCase
{
    public function testEveryRequestableCapabilityHasAWayToBeAsked(): void
    {
        $reachable = [];
        foreach (DeviceCommandCatalog::commandsForProtocol('zayata-m228') as $entry) {
            if (($entry['kind'] ?? '') === 'request') {
                $reachable[(string)$entry['feature']] = 'mosaico';
            }
        }
        foreach (DeviceConfigurationCatalog::configsForProtocol('zayata-m228') as $entry) {
            if (($entry['transient'] ?? false) === true) {
                $reachable[(string)($entry['capabilityKey'] ?? $entry['key'])] ??= 'modal';
            }
        }

        $orphans = [];
        foreach (CapabilityCatalog::definitionsForDeviceType('pill_dispenser') as $definition) {
            $key = (string)$definition['key'];
            if (!empty($definition['isRequestable']) && !isset($reachable[$key])) {
                $orphans[] = $key;
            }
        }

        self::assertSame([], $orphans);
    }

    /** E o contrário: uma acção do modal que o catálogo não dê como pedível. */
    public function testEveryActionIsDeclaredRequestable(): void
    {
        $requestable = [];
        foreach (CapabilityCatalog::definitionsForDeviceType('pill_dispenser') as $definition) {
            if (!empty($definition['isRequestable'])) {
                $requestable[(string)$definition['key']] = true;
            }
        }

        $undeclared = [];
        foreach (DeviceConfigurationCatalog::configsForProtocol('zayata-m228') as $entry) {
            $key = (string)($entry['capabilityKey'] ?? $entry['key']);
            if (($entry['transient'] ?? false) === true && !isset($requestable[$key])) {
                $undeclared[] = $key;
            }
        }

        self::assertSame([], $undeclared);
    }
}
