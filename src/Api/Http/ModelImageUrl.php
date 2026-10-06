<?php

declare(strict_types=1);

namespace Hub\Api\Http;

use Hub\Api\Services\ModelImageStore;

/** O endereço público da imagem de um modelo: a base guarda o nome do ficheiro, e a rota vive no código. */
final class ModelImageUrl
{
    public function resolve(string $filename, string $baseUrl): ?string
    {
        $filename = trim($filename);
        if ($filename === '') {
            return null;
        }

        // Linhas ainda por migrar trazem a rota inteira.
        $filename = basename($filename);

        return $baseUrl . ModelImageStore::ROUTE . '/' . $filename;
    }
}
