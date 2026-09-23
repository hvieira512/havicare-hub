<?php

declare(strict_types=1);

namespace Tests\Unit\Api\Services;

use GuzzleHttp\Psr7\UploadedFile;
use GuzzleHttp\Psr7\Utils;
use Hub\Api\Services\ModelImageStore;
use PHPUnit\Framework\TestCase;

/**
 * O tamanho comprimido de uma imagem não diz nada sobre o que ela custa a abrir.
 *
 * Um PNG de poucos quilobytes pode declarar dezenas de milhares de píxeis por lado, e o GD
 * aloca `largura × altura × 4` bytes **antes** de alguém poder verificar seja o que for. O hub
 * é um processo só, e um `fatal` de memória aqui derruba as ligações de todos os dispositivos:
 * as dimensões declaradas lêem-se do cabeçalho e recusam-se antes da descodificação.
 */
final class ModelImageStoreTest extends TestCase
{
    public function testAnImageDeclaringMorePixelsThanTheBudgetIsRefusedBeforeDecoding(): void
    {
        $store = new ModelImageStore();

        // 30 000 × 30 000 são 900 megapíxeis: 3,6 GB no GD, a partir de um ficheiro de 70
        // bytes. Basta o cabeçalho, porque o objectivo é nunca chegar à descodificação.
        $result = $store->store($this->upload(self::pngHeader(30000, 30000)));

        self::assertIsArray($result);
        self::assertSame(
            'image_dimensions_too_large',
            $result['error']['code'] ?? '',
            'uma imagem acima do orçamento de píxeis tem de ser recusada pelas dimensões, e não pelo GD a ficar sem memória',
        );
    }

    /** Uma imagem normal continua a passar: o travão não pode fechar a porta a toda a gente. */
    public function testAnOrdinaryImageStillPasses(): void
    {
        if (!function_exists('imagecreatetruecolor') || !function_exists('imagejpeg')) {
            self::markTestSkipped('o GD com JPEG é preciso para gravar a imagem reduzida');
        }

        $canvas = \imagecreatetruecolor(800, 600);
        ob_start();
        \imagepng($canvas);
        $png = (string)ob_get_clean();

        $result = (new ModelImageStore())->store($this->upload($png));

        self::assertIsString($result, 'uma imagem de 800x600 está muito abaixo do orçamento');
        // Só o nome do ficheiro: a rota por onde ele se serve é constante e vive no código.
        self::assertMatchesRegularExpression('#^[a-f0-9]{32}\.jpg$#', $result);

        $stored = __DIR__ . '/../../../../var/dashboard/model-images/' . basename($result);
        if (is_file($stored)) {
            unlink($stored);
        }
    }

    /** Bytes que não são imagem nenhuma continuam a dar `invalid_image`, e não o erro novo. */
    public function testGarbageIsStillReportedAsAnInvalidImage(): void
    {
        $result = (new ModelImageStore())->store($this->upload('isto não é uma imagem'));

        self::assertIsArray($result);
        self::assertSame('invalid_image', $result['error']['code'] ?? '');
    }

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
        $route = (new ModelImageStore())->store($upload);
        self::assertIsString($route);
        self::assertMatchesRegularExpression('#^[a-f0-9]{32}\.jpg$#', $route);

        $path = __DIR__ . '/../../../../var/dashboard/model-images/' . basename($route);
        try {
            self::assertFileExists($path);
            $imageInfo = getimagesize($path);
            self::assertIsArray($imageInfo);
            self::assertSame(IMAGETYPE_JPEG, $imageInfo[2] ?? null);
            [$width, $height] = $imageInfo;
            self::assertSame(640, $width);
            self::assertSame(213, $height);
        } finally {
            if (is_file($path)) {
                unlink($path);
            }
        }
    }

    public function testModelImageUploadStripsPngColorProfileChunksBeforeDecode(): void
    {
        $source = imagecreatetruecolor(20, 20);
        self::assertNotFalse($source);
        $color = imagecolorallocate($source, 200, 40, 40);
        imagefill($source, 0, 0, $color);
        ob_start();
        imagepng($source);
        $bytes = $this->insertPngChunk((string)ob_get_clean(), 'iCCP', "profile\0\0invalid-profile");

        $upload = new UploadedFile(Utils::streamFor($bytes), strlen($bytes), UPLOAD_ERR_OK, 'watch.png', 'image/png');
        $route = (new ModelImageStore())->store($upload);
        self::assertIsString($route);
        self::assertMatchesRegularExpression('#^[a-f0-9]{32}\.jpg$#', $route);

        $path = __DIR__ . '/../../../../var/dashboard/model-images/' . basename($route);
        try {
            self::assertFileExists($path);
            $imageInfo = getimagesize($path);
            self::assertIsArray($imageInfo);
            self::assertSame(IMAGETYPE_JPEG, $imageInfo[2] ?? null);
        } finally {
            if (is_file($path)) {
                unlink($path);
            }
        }
    }

    private function insertPngChunk(string $png, string $type, string $data): string
    {
        $signatureLength = 8;
        $chunk = pack('N', strlen($data)) . $type . $data . pack('N', crc32($type . $data));

        return substr($png, 0, $signatureLength) . $chunk . substr($png, $signatureLength);
    }

    private function upload(string $bytes): UploadedFile
    {
        return new UploadedFile(Utils::streamFor($bytes), strlen($bytes), UPLOAD_ERR_OK, 'model.png', 'image/png');
    }

    /**
     * A assinatura e o IHDR de um PNG, que é tudo o que é preciso para as dimensões serem
     * legíveis. Sem dados de imagem de propósito: se o travão funcionar, ninguém os procura.
     */
    private static function pngHeader(int $width, int $height): string
    {
        $ihdr = 'IHDR' . pack('NN', $width, $height) . pack('CCCCC', 8, 2, 0, 0, 0);

        return "\x89PNG\r\n\x1a\n"
            . pack('N', 13) . $ihdr . pack('N', crc32($ihdr));
    }
}
