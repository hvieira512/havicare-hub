<?php

declare(strict_types=1);

namespace Tests\Unit\Api\Http;

use Hub\Api\Http\DeviceResponseCompactor;
use PHPUnit\Framework\TestCase;

final class DeviceResponseCompactorTest extends TestCase
{
    public function testOversizedVoiceDataIsReplacedEverywhereWithCompactMetadata(): void
    {
        $voiceData = base64_encode(str_repeat('audio', 20000));
        $response = [
            'configurations' => ['medication_reminders' => ['voiceData' => $voiceData]],
            'capabilities' => ['alarms' => ['medication_reminders' => ['value' => ['voiceData' => $voiceData]]]],
            'pending' => ['alarms' => ['medication_reminders' => ['desired' => ['voiceData' => $voiceData]]]],
        ];

        $actual = (new DeviceResponseCompactor())->compact($response);

        foreach (
            [
            $actual['configurations']['medication_reminders'],
            $actual['capabilities']['alarms']['medication_reminders']['value'],
            $actual['pending']['alarms']['medication_reminders']['desired'],
            ] as $value
        ) {
            self::assertArrayNotHasKey('voiceData', $value);
            self::assertTrue($value['voiceDataAvailable']);
            self::assertSame(100000, $value['voiceDataBytes']);
        }
    }

    public function testSmallVoiceDataRemainsInline(): void
    {
        $response = ['configurations' => ['medication_reminders' => ['voiceData' => 'QUJDRA==']]];

        self::assertSame($response, (new DeviceResponseCompactor())->compact($response));
    }
}
