<?php

declare(strict_types=1);

namespace Tests\Component\WebSocket;

use App\WebSocket\WatchServer;
use PHPUnit\Framework\TestCase;

final class WatchServerVivistarAddressReplyTest extends TestCase
{
    public function testAp10ReplyBuildsUnicodeAddressAndMapLinkWhenRequested(): void
    {
        $server = new WatchServer();

        $session = [
            'protocol' => 'vivistar-iw',
            'imei' => '865028000000308',
        ];

        $payload = [
            'type' => 'AP10',
            'imei' => '865028000000308',
            'data' => [
                'raw' => '080524A2232.9806N11404.9355E000.1061830323.8706000908000502,460,0,9520,3671,00,en,11,HOME|74-DE-2B-44-88-8C|97',
                'fields' => [
                    '080524A2232.9806N11404.9355E000.1061830323.8706000908000502',
                    '460',
                    '0',
                    '9520',
                    '3671',
                    '00',
                    'en',
                    '11',
                    'HOME|74-DE-2B-44-88-8C|97',
                ],
            ],
        ];

        $reply = $this->invokePrivate($server, 'buildPassiveReplyData', [$session, $payload]);
        self::assertIsArray($reply);
        self::assertArrayHasKey('unicodeHex', $reply);

        $decoded = $this->decodeUnicodeHex($reply['unicodeHex']);
        self::assertStringContainsString('Lat 22.549677, Lng 114.082258', $decoded);
        self::assertStringContainsString('http://www.gps.com/map.aspx?lat=22.549677&lng=114.082258', $decoded);
    }

    public function testAp02ReplyUsesLastKnownCoordinatesWhenRequested(): void
    {
        $server = new WatchServer();
        $this->setPrivateProperty($server, 'deviceData', [
            '865028000000308' => [
                'nativePayload' => [
                    'raw' => '080524A2232.9806N11404.9355E000.1061830323.8706000908000102',
                    'fields' => ['080524A2232.9806N11404.9355E000.1061830323.8706000908000102'],
                ],
            ],
        ]);

        $session = [
            'protocol' => 'vivistar-iw',
            'imei' => '865028000000308',
        ];
        $payload = [
            'type' => 'AP02',
            'imei' => '865028000000308',
            'data' => [
                'fields' => ['en', '1'],
            ],
        ];

        $reply = $this->invokePrivate($server, 'buildPassiveReplyData', [$session, $payload]);
        self::assertIsArray($reply);
        self::assertArrayHasKey('unicodeHex', $reply);

        $decoded = $this->decodeUnicodeHex($reply['unicodeHex']);
        self::assertSame('Lat 22.549677, Lng 114.082258', $decoded);
    }

    private function setPrivateProperty(object $target, string $name, mixed $value): void
    {
        $ref = new \ReflectionClass($target);
        $prop = $ref->getProperty($name);
        $prop->setValue($target, $value);
    }

    private function invokePrivate(object $target, string $name, array $args): mixed
    {
        $ref = new \ReflectionClass($target);
        $method = $ref->getMethod($name);
        return $method->invokeArgs($target, $args);
    }

    private function decodeUnicodeHex(string $hex): string
    {
        $binary = hex2bin($hex);
        self::assertNotFalse($binary);

        if (function_exists('mb_convert_encoding')) {
            return mb_convert_encoding($binary, 'UTF-8', 'UTF-16BE');
        }

        $decoded = @iconv('UTF-16BE', 'UTF-8//IGNORE', $binary);
        return is_string($decoded) ? $decoded : '';
    }
}
