<?php

declare(strict_types=1);

namespace Tests\Unit\Command;

use Hub\Command\Configuration\Payload\FourPTouchPayloadBuilder;
use PHPUnit\Framework\TestCase;

/** Os períodos a mais recusam-se: cortados, o pedido respondia como gravado sem o estar. */
final class FourPTouchTimeRangesLimitTest extends TestCase
{
    public function testRejectsMoreDoNotDisturbRangesThanTheWatchKeeps(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        FourPTouchPayloadBuilder::build('doNotDisturb', ['ranges' => array_fill(0, 5, '08:00-09:00')]);
    }

    public function testRejectsMorePedometerRangesThanTheWatchKeeps(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        FourPTouchPayloadBuilder::build('walkTime', ['ranges' => array_fill(0, 4, '08:00-09:00')]);
    }
}
