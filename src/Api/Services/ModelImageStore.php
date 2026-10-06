<?php

declare(strict_types=1);

namespace Hub\Api\Services;

use Hub\Api\Http\ApiError;
use Psr\Http\Message\UploadedFileInterface;

/**
 * A imagem de um modelo: recebe o upload, reduz, grava e apaga. O `ModelService` decide
 * *quando*; o *como* vive aqui.
 */
final class ModelImageStore
{
    public const ROUTE = '/model-images';

    private const DIR = __DIR__ . '/../../../var/dashboard/model-images';
    private const MAX_BYTES = 5 * 1024 * 1024;
    private const MAX_DIMENSION = 640;

    /**
     * O tecto do que se aceita descodificar, em píxeis: o GD aloca `largura × altura × 4` bytes, e
     * 25 megapíxeis limitam-no a cerca de 100 MB.
     */
    private const MAX_PIXELS = 25_000_000;

    /**
     * O array é sempre um erro do `ApiError`.
     *
     * @return string|array{error: array<string, mixed>}|null
     */
    public function store(mixed $upload): string|array|null
    {
        if (!$upload instanceof UploadedFileInterface || $upload->getError() === UPLOAD_ERR_NO_FILE) {
            return null;
        }
        if ($upload->getError() !== UPLOAD_ERR_OK) {
            return ApiError::uploadFailed()->toArray();
        }
        if (($upload->getSize() ?? 0) > self::MAX_BYTES) {
            return ApiError::imageTooLarge()->toArray();
        }
        if (!function_exists('imagecreatefromstring')) {
            return ApiError::gdMissing()->toArray();
        }
        if (!function_exists('imagejpeg')) {
            return ApiError::gdJpegMissing()->toArray();
        }

        $stream = $upload->getStream();
        if ($stream->isSeekable()) {
            $stream->rewind();
        }
        $bytes = $stream->getContents();
        if ($bytes === '') {
            return null;
        }

        // Limpar primeiro e medir depois, sobre os bytes que o GD vai receber: um `iCCP` antes do
        // `IHDR` desloca o cabeçalho e as dimensões lidas.
        $bytes = $this->stripPngColorProfiles($bytes);

        // O `getimagesizefromstring` lê as dimensões declaradas sem alocar a imagem: é aqui que se
        // recusa uma bomba de descompressão.
        $declared = @\getimagesizefromstring($bytes);
        if (is_array($declared) && ((int)$declared[0] * (int)$declared[1]) > self::MAX_PIXELS) {
            return ApiError::imageDimensionsTooLarge()->toArray();
        }

        $source = @\imagecreatefromstring($bytes);
        if ($source === false) {
            return ApiError::invalidImage()->toArray();
        }

        $width = \imagesx($source);
        $height = \imagesy($source);
        $scale = min(1, self::MAX_DIMENSION / max($width, $height));
        $targetWidth = max(1, (int)round($width * $scale));
        $targetHeight = max(1, (int)round($height * $scale));
        $target = \imagecreatetruecolor($targetWidth, $targetHeight);
        $white = \imagecolorallocate($target, 255, 255, 255);
        \imagefill($target, 0, 0, $white);
        \imagecopyresampled($target, $source, 0, 0, 0, 0, $targetWidth, $targetHeight, $width, $height);

        if (!is_dir(self::DIR)) {
            mkdir(self::DIR, 0755, true);
        }
        $filename = bin2hex(random_bytes(16)) . '.jpg';
        $path = self::pathFor($filename);
        $saved = \imagejpeg($target, $path, 78);

        if (!$saved) {
            return ApiError::imageSaveFailed()->toArray();
        }

        // O nome e mais nada: a rota por onde o ficheiro se serve é constante e vive aqui, e
        // repeti-la em cada linha da base obrigava a um `UPDATE` a toda a tabela para a mudar.
        return $filename;
    }

    private function stripPngColorProfiles(string $bytes): string
    {
        $signature = "\x89PNG\r\n\x1a\n";
        if (!str_starts_with($bytes, $signature)) {
            return $bytes;
        }

        $offset = strlen($signature);
        $length = strlen($bytes);
        $clean = $signature;
        $removed = false;

        while ($offset + 12 <= $length) {
            $chunkLength = unpack('N', substr($bytes, $offset, 4))[1];
            $chunkEnd = $offset + 12 + $chunkLength;
            if ($chunkLength < 0 || $chunkEnd > $length) {
                return $bytes;
            }

            $chunkType = substr($bytes, $offset + 4, 4);
            if ($chunkType !== 'iCCP') {
                $clean .= substr($bytes, $offset, 12 + $chunkLength);
            } else {
                $removed = true;
            }

            $offset = $chunkEnd;
            if ($chunkType === 'IEND') {
                break;
            }
        }

        return $removed ? $clean : $bytes;
    }

    /** O caminho em disco de uma imagem guardada, para quem a serve sem passar por aqui. */
    public static function pathFor(string $filename): string
    {
        return self::DIR . '/' . $filename;
    }

    /**
     * O nome do ficheiro, que é o que a base guarda. A forma verifica-se antes de tocar no disco,
     * para um nome vindo de fora não escolher que ficheiro apagar.
     */
    public function delete(string $filename): void
    {
        if (preg_match('#^[a-f0-9]{32}\.jpg$#', trim($filename)) !== 1) {
            return;
        }
        $path = self::pathFor(trim($filename));
        if (is_file($path)) {
            unlink($path);
        }
    }
}
