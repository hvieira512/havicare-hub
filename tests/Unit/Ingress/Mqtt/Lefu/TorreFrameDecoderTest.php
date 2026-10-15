<?php

declare(strict_types=1);

namespace Tests\Unit\Ingress\Mqtt\Lefu;

use Hub\Ingress\Mqtt\Lefu\TorreFrameDecoder;
use PHPUnit\Framework\TestCase;

/** As tramas são de pesagens reais da CF632, de 2026-10-15. */
final class TorreFrameDecoderTest extends TestCase
{
    /** Utilizador em linha, descalço: 96,20 kg, 74 bpm, 400,0 / 363,5 Ω. */
    public function testACompleteWeighingCarriesWeightHeartRateAndImpedance(): void
    {
        $frame = (new TorreFrameDecoder())->decode('fff3', '010da580942500 4a003 2a00f64330e');

        self::assertSame('weighing', $frame['kind']);
        self::assertTrue($frame['complete']);
        self::assertSame(96.2, $frame['weightKg']);
        self::assertSame(74, $frame['heartRateBpm']);
        self::assertSame(400.0, $frame['impedance50KhzOhm']);
        self::assertSame(363.5, $frame['impedance100KhzOhm']);
        self::assertSame(
            ['weight' => 'stable', 'impedance' => 'measured', 'heartRate' => 'measured', 'displayUnit' => 'kg', 'shuttingDown' => false],
            $frame['status'],
        );
    }

    /** Calçado: o peso sem impedância nem FC. */
    public function testAWeighingWithShoesHasNoImpedanceNorHeartRate(): void
    {
        $frame = (new TorreFrameDecoder())->decode('fff3', '010d3580fd25000000320000640000');

        self::assertSame(97.25, $frame['weightKg']);
        self::assertNull($frame['heartRateBpm']);
        self::assertNull($frame['impedance50KhzOhm']);
        self::assertSame('failed', $frame['status']['impedance']);
    }

    public function testAFailedHeartRateIsNotAReading(): void
    {
        $frame = (new TorreFrameDecoder())->decode('fff3', '010de580b225000000327c0f649d0d');

        self::assertNull($frame['heartRateBpm']);
        self::assertSame('failed', $frame['status']['heartRate']);
        self::assertSame(396.4, $frame['impedance50KhzOhm']);
    }

    public function testAnIntermediateFrameIsProgressAndTheScaleSaysWhenItShutsDown(): void
    {
        $decoder = new TorreFrameDecoder();

        $settling = $decoder->decode('fff3', '010d0100992500000000000000 0000');
        self::assertFalse($settling['complete']);
        self::assertSame('settling', $settling['status']['weight']);
        self::assertSame(96.25, $settling['weightKg']);

        // Com a balança vazia acendem os dois bits do peso: ninguém em cima, e não excesso de peso.
        $empty = $decoder->decode('fff3', '010d0d40000000000000000000000000');
        self::assertTrue($empty['status']['shuttingDown']);
        self::assertSame('idle', $empty['status']['weight']);
    }

    public function testTheEncodedImpedanceIsTwoUnsignedLittleEndianWords(): void
    {
        self::assertSame(
            ['kind' => 'encoded_impedance', 'electrodes' => 'four_dual_frequency', 'encoded50Khz' => 7295760, 'encoded100Khz' => 14358271],
            (new TorreFrameDecoder())->decode('fff3', '020a1802 10536f00 ff16db00'),
        );
    }

    public function testThePerChannelImpedance(): void
    {
        self::assertSame(
            ['kind' => 'impedance_channel', 'channel' => 3, 'impedanceOhm' => 280.9],
            (new TorreFrameDecoder())->decode('fff3', '030420 03 f90a'),
        );
    }

    public function testTheScaleAnswersASettingWithItsResult(): void
    {
        $decoder = new TorreFrameDecoder();

        self::assertSame(['kind' => 'reply', 'replyType' => 'torre_06_set', 'accepted' => true], $decoder->decode('fff1', '06020100'));
        self::assertSame(['kind' => 'reply', 'replyType' => 'torre_04_set', 'accepted' => true], $decoder->decode('fff1', '040100'));
        self::assertSame(['kind' => 'reply', 'replyType' => 'torre_07_set', 'accepted' => false], $decoder->decode('fff1', '07020101'));
    }

    /** O `00` liga a FC e a impedância; a unidade 0 é o quilograma. */
    public function testAReadIsTheReportedSetting(): void
    {
        $decoder = new TorreFrameDecoder();

        self::assertSame(
            ['kind' => 'setting', 'replyType' => 'torre_06_read', 'settings' => ['heart_rate_measurement' => ['enabled' => true]]],
            $decoder->decode('fff1', '06020200'),
        );
        self::assertSame(
            ['kind' => 'setting', 'replyType' => 'torre_07_read', 'settings' => ['impedance_measurement' => ['enabled' => false]]],
            $decoder->decode('fff1', '07020201'),
        );
        self::assertSame(
            ['kind' => 'setting', 'replyType' => 'torre_05_read', 'settings' => ['weight_unit' => ['unit' => 'kg']]],
            $decoder->decode('fff1', '05020200'),
        );
    }

    public function testTheUserStepsAnswerOnFff2(): void
    {
        $decoder = new TorreFrameDecoder();

        self::assertSame(['kind' => 'reply', 'replyType' => 'torre_user_07', 'accepted' => true, 'error' => null], $decoder->decode('fff2', '05020700'));
        self::assertSame(['kind' => 'reply', 'replyType' => 'torre_user_02', 'accepted' => false, 'error' => 'memory_full'], $decoder->decode('fff2', '05020204'));
    }

    public function testTheOtherMeasurementFrames(): void
    {
        $decoder = new TorreFrameDecoder();

        self::assertSame(['kind' => 'motion'], $decoder->decode('fff3', '0400'));
        self::assertSame(['kind' => 'history_available'], $decoder->decode('fff3', '0500'));
        self::assertSame(['kind' => 'user_selection', 'stateCode' => 2], $decoder->decode('fff3', '060102'));
        self::assertSame(['kind' => 'impedance_failure', 'limb' => 'right_leg'], $decoder->decode('fff3', '1603000002'));
    }

    /** O histórico da captura do Marcus: o membro e seis registos de 20 bytes. */
    public function testTheHistoryBlockIsTheMemberAndTwentyByteRecords(): void
    {
        $block = (new TorreFrameDecoder())->decode('fff2', bin2hex(str_repeat('0', 64))
            . 'b13644692e0400025f14cf000000ae55df000000f53644692b075402b755e900310f0822fc00440e'
            . '4637446990060002000000000000000000000000ad3744691c074f0259746f00150fd1661e00290e'
            . '50384469ce044d02ee36d100700f3953cf00810e74384469ce04520210135700740fcb461600870e');

        self::assertSame('history', $block['kind']);
        self::assertSame(str_repeat('0', 64), $block['memberId']);
        self::assertCount(6, $block['records']);
        self::assertSame([
            'at' => 1766078381,
            'weightKg' => 18.2,
            'heartRateBpm' => 79,
            'electrodes' => 'four_dual_frequency',
            'encodedImpedance50Khz' => 7304281,
            'impedance50KhzOhm' => 386.1,
            'encodedImpedance100Khz' => 1992401,
            'impedance100KhzOhm' => 362.5,
        ], $block['records'][3]);
        self::assertNull($block['records'][0]['heartRateBpm']);
    }

    public function testTheHistorySteps(): void
    {
        $decoder = new TorreFrameDecoder();

        self::assertSame(['kind' => 'reply', 'replyType' => 'torre_history_07', 'accepted' => true, 'error' => null], $decoder->decode('fff2', '02020700'));
        self::assertSame(['kind' => 'reply', 'replyType' => 'torre_history_0a', 'accepted' => true, 'error' => null], $decoder->decode('fff2', '02020a01'));
    }

    /** O MTU e o que não se conhece não são leituras. */
    public function testWhatIsNotAReadingDecodesToNothing(): void
    {
        $decoder = new TorreFrameDecoder();

        self::assertNull($decoder->decode('fff1', '02023d00'));
        self::assertNull($decoder->decode('fff3', '7f00'));
        self::assertNull($decoder->decode('fff3', 'zz'));
    }
}
