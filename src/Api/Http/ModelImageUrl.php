<?php

namespace Hub\Api\Http;

use Hub\Api\Services\ModelImageStore;

/**
 * O endereço público da imagem de um modelo.
 *
 * A base de dados guarda o nome do ficheiro e mais nada: a rota por onde ele se serve é
 * constante e vive no código. Guardá-la em cada linha obrigava a um `UPDATE` a toda a tabela
 * para a mudar, e deixava a coluna aceitar caminhos inconsistentes.
 */
final class ModelImageUrl
{
    public function resolve(string $filename, string $baseUrl): ?string
    {
        $filename = trim($filename);
        if ($filename === '') {
            return null;
        }

        // As linhas escritas antes de a rota sair da base ainda a trazem. A migração limpa-as,
        // mas entre o deploy e a migração as imagens não podem ficar todas partidas.
        $filename = basename($filename);

        return $baseUrl . ModelImageStore::ROUTE . '/' . $filename;
    }
}
