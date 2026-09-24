<?php

declare(strict_types=1);

namespace Tests\Unit\Hub;

use Hub\Device\DeviceEventDecoder;
use Hub\Device\DeviceSession;
use Hub\Protocol\Adapter\FourPTouchAdapter;
use PHPUnit\Framework\TestCase;

/**
 * Pedir o estado a um relógio é uma acção, e não uma leitura.
 *
 * O `TS` devolve dezasseis campos e onze deles são o hub a ler de volta o que ele próprio
 * escreveu — idioma, fuso, intervalo de upload, perfil, o endereço do nosso servidor, a
 * identidade, a bateria que já chega em cada heartbeat. Saía tudo como um único campo de
 * telemetria chamado `deviceTime`, que não é a hora do dispositivo nem é um valor só.
 *
 * O pedido continua a existir e a fechar-se; o que deixa de haver é uma mensagem publicada
 * com um nome que mente sobre o que traz.
 */
final class FourPTouchDeviceStatusTest extends TestCase
{
    /**
     * O bloco real de um D41, byte a byte como chegou do aparelho a 24/09/2026.
     *
     * Vai em base64 porque a trama leva mudanças de linha dentro do corpo, e o campo de
     * comprimento do cabeçalho conta-as: reescrevê-la à mão com espaços dava uma trama que o
     * adaptador recusa, e o teste passava a medir a minha reconstrução e não o aparelho.
     */
    private const REAL_REPLY_BASE64 = 'WzNHKjI4MDg3NzQzMDYqMDEyOCpUUyx2ZXI6QTZDX1lTQ19ENDFfRU1NQ18yNDAyOTZfNU1f'
        . 'Q09NTU9OX09WRVJTRUFfMjAyNi4wNC4xNV8yMC4wMS41MTsgCklEOjI4MDg3NzQzMDY7IAppbWVpOjg2MTcyODA4Nzc0MzA2Mjsg'
        . 'CnVybDoxNDQuNzYuMTg2LjkyOyAKcG9ydDo4MDgwOyAKdXBsb2FkOjE0NDAwOyAKbGs6MzAwOyAKYmF0bGV2ZWw6MTAwOyAKbGFu'
        . 'Z3VhZ2U6cHQ7IAp6b25lOiswMTowMDsgCnByb2ZpbGU6MTsgCkdQUzpPSygwKTsgCndpZmlPcGVuOnRydWU7IAp3aWZpQ29ubmVj'
        . 'dDpmYWxzZTsgCmdwcnNPcGVuOnRydWU7IApORVQ6T0soMTAwKV0=';

    public function testTheStatusReplyPublishesNoTelemetry(): void
    {
        $decoded = (new FourPTouchAdapter())->decodeIncoming(
            (string)base64_decode(self::REAL_REPLY_BASE64, true)
        );

        self::assertIsArray($decoded);
        self::assertSame('TS', $decoded['type'], 'a trama continua a ser reconhecida');
        self::assertSame([], (new DeviceEventDecoder())->decode($this->session(), $decoded));
    }

    /** A versão do firmware tem capacidade própria, e essa continua a publicar-se. */
    public function testTheFirmwareVersionStillPublishes(): void
    {
        $decoded = (new FourPTouchAdapter())->decodeIncoming('[3G*2808774306*000D*VERNO,A6C_D41]');

        self::assertIsArray($decoded);
        $events = (new DeviceEventDecoder())->decode($this->session(), $decoded);

        self::assertCount(1, $events);
        self::assertSame('firmware_version', $events[0]['feature']);
    }

    private function session(): DeviceSession
    {
        return new DeviceSession(
            new PillFakeConnection(),
            'tcp',
            true,
            '861728087743062',
            'four-p-touch',
            '4P Touch',
            'D41',
            '4P Touch D41',
            'watch',
        );
    }
}
