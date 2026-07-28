<?php

namespace Tests\Unit\Domain;

use Hub\Domain\Capability\Contacts\FourPTouchCallWhitelistHandler;
use Hub\Domain\Capability\Contacts\FourPTouchSosContactsHandler;
use Hub\Domain\Capability\Contacts\SosContactsCapability;
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
            ],
            $handler->responseEntry('four-p-touch', 'sosNumber1', ['numbers' => ['111111111', '222222222']], []),
        );
    }

    public function testSosContactsCapabilityRejectsMoreThanThreeNumbers(): void
    {
        $capability = new SosContactsCapability();

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('numbers must contain at most 3 values');

        $capability->toNative('four-p-touch', [
            '111111111',
            '222222222',
            '333333333',
            '444444444',
        ]);
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
