<?php

declare(strict_types=1);

namespace Hub\Infrastructure\Persistence\Migration;

use PDO;

/**
 * A coluna da imagem de um modelo passa a guardar o nome do ficheiro e mais nada.
 *
 * Guardava `/model-images/<ficheiro>.jpg`, com o mesmo prefixo repetido em todas as linhas.
 * A rota por onde a imagem se serve é constante e já existe no código — repeti-la na base
 * obrigava a um `UPDATE` a toda a tabela para a mudar, deixava a coluna aceitar caminhos
 * inconsistentes, e obrigava quem a lê a desmontá-la para chegar ao ficheiro.
 *
 * O que varia por linha fica na linha; o que é igual em todas fica no código.
 */
final class ModelImageFilenameOnly implements Migration
{
    public function version(): string
    {
        return '2026_09_22_model_image_filename_only';
    }

    public function up(PDO $pdo): void
    {
        // `SUBSTRING_INDEX` pela última barra: apanha o prefixo qualquer que ele fosse, e
        // deixa quieto o que já é só um nome.
        $pdo->exec("
            UPDATE models
            SET image_path = SUBSTRING_INDEX(image_path, '/', -1)
            WHERE image_path LIKE '%/%'
        ");
    }
}
