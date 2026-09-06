<?php

declare(strict_types=1);

namespace Tests\Unit\Dashboard;

use GuzzleHttp\Psr7\ServerRequest;
use Hub\Dashboard\DashboardHttpServer;
use PHPUnit\Framework\TestCase;

/**
 * O corpo de um recurso estático é cacheado, mas a cache tem de acompanhar o ETag. Um ficheiro
 * que muda debaixo do processo passa a ter ETag novo -- ele deriva do `mtime` e do tamanho --
 * e o corpo servido tem de ser o novo, não os bytes velhos presos na cache. Sem isso o browser
 * recebia 200 com ETag novo e corpo velho, e a partir daí 304 para sempre.
 */
final class DashboardAssetCacheTest extends TestCase
{
    public function testAssetBodyTracksTheEtagWhenTheFileChanges(): void
    {
        $server = (new \ReflectionClass(DashboardHttpServer::class))->newInstanceWithoutConstructor();
        $staticFile = new \ReflectionMethod(DashboardHttpServer::class, 'staticFile');

        $path = sys_get_temp_dir() . '/hub-asset-cache-' . bin2hex(random_bytes(4)) . '.js';
        file_put_contents($path, 'const a = 1;');
        touch($path, time() - 10);
        clearstatcache(true, $path);

        try {
            $first = $staticFile->invoke($server, $path, new ServerRequest('GET', '/dashboard/x.js'));
            $firstEtag = $first->getHeaderLine('ETag');
            self::assertSame('const a = 1;', (string)$first->getBody());

            file_put_contents($path, 'const a = 22;');
            touch($path, time());
            clearstatcache(true, $path);

            $second = $staticFile->invoke($server, $path, new ServerRequest('GET', '/dashboard/x.js'));
            self::assertNotSame($firstEtag, $second->getHeaderLine('ETag'), 'o ETag muda com o ficheiro');
            self::assertSame(
                'const a = 22;',
                (string)$second->getBody(),
                'o corpo acompanha o ETag, e não fica preso ao antigo',
            );
        } finally {
            @unlink($path);
        }
    }
}
