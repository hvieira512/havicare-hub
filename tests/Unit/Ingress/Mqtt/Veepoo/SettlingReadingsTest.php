<?php

declare(strict_types=1);

namespace Tests\Unit\Ingress\Mqtt\Veepoo;

use Hub\Ingress\Mqtt\Veepoo\BraceletContext;
use Hub\Ingress\Mqtt\Veepoo\SettlingReadings;
use PHPUnit\Framework\TestCase;

/**
 * A medição a assentar, vista sem o MQTT à volta.
 *
 * É a única lógica da ingestão Veepoo com semântica de tempo, e aqui o relógio é uma variável.
 */
final class SettlingReadingsTest extends TestCase
{
    private const DEVICE = '9f69c4866e6c';
    private const OPERATION = 'measure.heartRate.start';

    private float $now = 1000.0;

    /** A última trama é a que vale: o valor anda enquanto a medição assenta. */
    public function testTheConfirmationTakesTheValueItSettledOn(): void
    {
        $readings = $this->readings();
        $readings->hold($this->context(), self::OPERATION, ['bpm' => 79]);
        $readings->hold($this->context(), self::OPERATION, ['bpm' => 91]);

        self::assertSame(['bpm' => 91], $readings->takeSettled(self::DEVICE, self::OPERATION));
        self::assertNull($readings->takeSettled(self::DEVICE, self::OPERATION));
    }

    /** Sem trama nenhuma não há o que assentar. */
    public function testAnUnknownRequestHasNothingSettled(): void
    {
        self::assertNull($this->readings()->takeSettled(self::DEVICE, self::OPERATION));
    }

    /** Um pedido encerrado larga a leitura e a confirmação que lhe chega não é para ninguém. */
    public function testTheConfirmationOfAClosedRequestIsDiscarded(): void
    {
        $readings = $this->readings();
        $readings->hold($this->context(), self::OPERATION, ['bpm' => 91]);
        $readings->close(self::DEVICE, self::OPERATION);

        self::assertTrue($readings->discardConfirmation(self::DEVICE, self::OPERATION));
        self::assertNull($readings->takeSettled(self::DEVICE, self::OPERATION));
        self::assertFalse($readings->discardConfirmation(self::DEVICE, self::OPERATION));
    }

    /** Uma leitura que chegue depois do encerramento não salva o pedido. */
    public function testAReadingThatArrivesAfterTheCloseIsDiscardedWithIt(): void
    {
        $readings = $this->readings();
        $readings->close(self::DEVICE, self::OPERATION);
        $readings->hold($this->context(), self::OPERATION, ['bpm' => 91]);

        self::assertTrue($readings->discardConfirmation(self::DEVICE, self::OPERATION));
        self::assertNull($readings->takeSettled(self::DEVICE, self::OPERATION));
    }

    /** Passado o prazo, a leitura sai sem confirmação. */
    public function testAReadingIsReleasedOnceTheDeadlinePasses(): void
    {
        $readings = $this->readings();
        $context = $this->context();
        $readings->hold($context, self::OPERATION, ['bpm' => 91]);

        $this->now += 179;
        self::assertSame([], iterator_to_array($readings->release()));

        $this->now += 2;
        self::assertSame(
            [['context' => $context, 'telemetry' => ['bpm' => 91]]],
            iterator_to_array($readings->release()),
        );
        self::assertSame([], iterator_to_array($readings->release()));
    }

    /** E a marca de encerrado também expira: o pedido seguinte falha por si. */
    public function testTheMarkOfAClosedRequestExpires(): void
    {
        $readings = $this->readings();
        $readings->close(self::DEVICE, self::OPERATION);

        $this->now += 200;
        self::assertSame([], iterator_to_array($readings->release()));
        self::assertFalse($readings->discardConfirmation(self::DEVICE, self::OPERATION));
    }

    private function readings(): SettlingReadings
    {
        return new SettlingReadings(fn(): float => $this->now);
    }

    private function context(): BraceletContext
    {
        return new BraceletContext(self::DEVICE, 'd48c49f7909c', ['imei' => self::DEVICE], 7, 'acme');
    }
}
