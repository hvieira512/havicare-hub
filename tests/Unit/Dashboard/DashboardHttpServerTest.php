<?php

namespace Tests\Unit\Dashboard;

use GuzzleHttp\Psr7\Utils;
use GuzzleHttp\Psr7\UploadedFile;
use Hub\Dashboard\DashboardHttpServer;
use PHPUnit\Framework\TestCase;

final class DashboardHttpServerTest extends TestCase
{
    public function testModelImageUploadIsCompressedAndStoredAsGeneratedJpeg(): void
    {
        $source = imagecreatetruecolor(900, 300);
        self::assertNotFalse($source);
        $color = imagecolorallocate($source, 24, 120, 180);
        imagefill($source, 0, 0, $color);
        ob_start();
        imagepng($source);
        $bytes = (string)ob_get_clean();

        $upload = new UploadedFile(Utils::streamFor($bytes), strlen($bytes), UPLOAD_ERR_OK, 'watch.png', 'image/png');
        $server = (new \ReflectionClass(DashboardHttpServer::class))->newInstanceWithoutConstructor();
        $method = new \ReflectionMethod(DashboardHttpServer::class, 'storeModelImage');

        $route = $method->invoke($server, $upload);
        self::assertIsString($route);
        self::assertMatchesRegularExpression('#^/model-images/[a-f0-9]{32}\.jpg$#', $route);

        $path = __DIR__ . '/../../../var/dashboard/model-images/' . basename($route);
        try {
            self::assertFileExists($path);
            self::assertSame(IMAGETYPE_JPEG, exif_imagetype($path));
            [$width, $height] = getimagesize($path);
            self::assertSame(640, $width);
            self::assertSame(213, $height);
        } finally {
            if (is_file($path)) {
                unlink($path);
            }
        }
    }
}
