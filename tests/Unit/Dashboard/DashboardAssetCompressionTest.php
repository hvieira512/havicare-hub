<?php

declare(strict_types=1);

namespace Tests\Unit\Dashboard;

use GuzzleHttp\Psr7\ServerRequest;
use Hub\Dashboard\DashboardHttpServer;
use PHPUnit\Framework\TestCase;

/**
 * A dashboard são 92 módulos ES servidos em cru, e o browser puxa-os todos: 876 KB de texto
 * por cada arranque. Comprimidos passam a cerca de um quinto disso, e o `Accept-Encoding` do
 * pedido é que decide -- um cliente que não anuncie `gzip` continua a receber os bytes tal e
 * qual.
 *
 * O corpo comprimido é outro corpo, e por isso leva ETag próprio: sem isso uma cache pelo
 * meio guardava um dos dois sob a mesma etiqueta e entregava-o ao cliente que pediu o outro.
 */
final class DashboardAssetCompressionTest extends TestCase
{
    private const SOURCE = "const answer = 42;\n// texto repetido para haver o que comprimir\n";

    public function testTextIsCompressedForAClientThatAcceptsGzip(): void
    {
        $path = $this->writeAsset('.js', str_repeat(self::SOURCE, 40));

        try {
            $response = $this->serve($path, ['Accept-Encoding' => 'gzip, deflate, br']);

            self::assertSame('gzip', $response->getHeaderLine('Content-Encoding'));
            self::assertSame('Accept-Encoding', $response->getHeaderLine('Vary'));
            self::assertSame(
                str_repeat(self::SOURCE, 40),
                (string)gzdecode((string)$response->getBody()),
                'o corpo comprimido tem de descomprimir para o ficheiro original',
            );
            self::assertLessThan(
                strlen(str_repeat(self::SOURCE, 40)),
                strlen((string)$response->getBody()),
                'comprimido tem de ser mais pequeno do que o original',
            );
        } finally {
            @unlink($path);
        }
    }

    public function testTextIsServedRawForAClientThatDoesNotAcceptGzip(): void
    {
        $path = $this->writeAsset('.js', self::SOURCE);

        try {
            $response = $this->serve($path, []);

            self::assertSame('', $response->getHeaderLine('Content-Encoding'));
            self::assertSame(self::SOURCE, (string)$response->getBody());
        } finally {
            @unlink($path);
        }
    }

    public function testTheEtagDistinguishesTheCompressedBodyFromTheRawOne(): void
    {
        $path = $this->writeAsset('.js', self::SOURCE);

        try {
            $compressed = $this->serve($path, ['Accept-Encoding' => 'gzip']);
            $raw = $this->serve($path, []);

            self::assertNotSame('', $compressed->getHeaderLine('ETag'));
            self::assertNotSame(
                $raw->getHeaderLine('ETag'),
                $compressed->getHeaderLine('ETag'),
                'dois corpos diferentes não podem partilhar a mesma etiqueta',
            );
        } finally {
            @unlink($path);
        }
    }

    /** O 304 só se dá a quem trouxer a etiqueta da variante que ia receber. */
    public function testTheRawEtagDoesNotSatisfyACompressedRequest(): void
    {
        $path = $this->writeAsset('.js', self::SOURCE);

        try {
            $rawEtag = $this->serve($path, [])->getHeaderLine('ETag');
            $response = $this->serve($path, [
                'Accept-Encoding' => 'gzip',
                'If-None-Match' => $rawEtag,
            ]);

            self::assertSame(200, $response->getStatusCode());
        } finally {
            @unlink($path);
        }
    }

    /** O que já vem comprimido não volta a passar por gzip: só gastava CPU. */
    public function testAlreadyCompressedBytesAreLeftAlone(): void
    {
        $path = $this->writeAsset('.woff2', "\x77\x4f\x46\x32binário");

        try {
            $response = $this->serve($path, ['Accept-Encoding' => 'gzip']);

            self::assertSame('', $response->getHeaderLine('Content-Encoding'));
        } finally {
            @unlink($path);
        }
    }

    /** @param array<string, string> $headers */
    private function serve(string $path, array $headers): \React\Http\Message\Response
    {
        $server = (new \ReflectionClass(DashboardHttpServer::class))->newInstanceWithoutConstructor();
        $staticFile = new \ReflectionMethod(DashboardHttpServer::class, 'staticFile');

        $request = new ServerRequest('GET', '/dashboard/asset', $headers);
        return $staticFile->invoke($server, $path, $request);
    }

    private function writeAsset(string $extension, string $contents): string
    {
        $path = sys_get_temp_dir() . '/hub-asset-gzip-' . bin2hex(random_bytes(4)) . $extension;
        file_put_contents($path, $contents);
        clearstatcache(true, $path);
        return $path;
    }
}
