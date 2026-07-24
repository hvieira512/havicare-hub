<?php

namespace Tests\Unit\Domain;

use Hub\Domain\Capability\Contacts\FourPTouchCallWhitelistHandler;
use Hub\Domain\Capability\Contacts\FourPTouchSosContactsHandler;
use PHPUnit\Framework\TestCase;

final class FourPTouchContactHandlersTest extends TestCase
{
    public function testSosContactsHandlerKeepsTheExpectedMetaAndNormalizesNumbers(): void
    {
        $handler = new FourPTouchSosContactsHandler();

        self::assertSame(
            ['111111111', '222222222'],
            $handler->fromNative(['numbers' => ['111111111', '222222222']]),
        );

        self::assertSame(
            [
                'value' => ['111111111', '222222222'],
                '_meta' => [
                    'limit' => 3,
                    'phone' => ['maxLength' => 20, 'asciiOnly' => true],
                ],
                '_type' => 'sos_contacts',
            ],
            $handler->responseEntry('four-p-touch', 'sosNumber1', ['numbers' => ['111111111', '222222222']], []),
        );
    }

    public function testCallWhitelistHandlerKeepsTheExpectedMetaAndNormalizesNumbers(): void
    {
        $handler = new FourPTouchCallWhitelistHandler();

        self::assertSame(
            ['111111111', '222222222'],
            $handler->fromNative(['numbers' => ['111111111', '222222222']]),
        );

        self::assertSame(
            [
                'value' => ['111111111', '222222222'],
                '_meta' => ['limit' => 10],
                '_type' => 'call_whitelist',
            ],
            $handler->responseEntry('four-p-touch', 'whitelistGroup1', ['numbers' => ['111111111', '222222222']], []),
        );
    }

    public function testCallWhitelistHandlerConvertsContactObjectsToNumbersInResponses(): void
    {
        $handler = new FourPTouchCallWhitelistHandler();

        self::assertSame(
            [
                'value' => ['111111111', '222222222'],
                '_meta' => ['limit' => 10],
                '_type' => 'call_whitelist',
            ],
            $handler->responseEntry(
                'four-p-touch',
                'call_whitelist',
                ['contacts' => [
                    ['name' => 'A', 'phone' => '111111111'],
                    ['name' => 'B', 'phone' => '222222222'],
                ]],
                []
            ),
        );
    }
}
