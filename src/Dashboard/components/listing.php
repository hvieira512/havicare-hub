<?php

declare(strict_types=1);

require_once __DIR__ . '/helpers.php';

/**
 * As peças de uma listagem: a casca da tabela, a pesquisa, e os dois selectores que
 * escolhem por que ordem e quantas linhas se vêem.
 *
 * Vivem juntas e fora do `helpers.php` porque são um assunto só, e porque a paginação --
 * a outra metade de uma listagem -- já tinha ficheiro próprio ao lado deste.
 */

/** A caixa de pesquisa, com a lupa colada à esquerda. */
function search_input(string $id, string $placeholder, string $wrapperClass = ''): string
{
    return '<div class="input-group input-group-sm' . ($wrapperClass !== '' ? ' ' . h($wrapperClass) : '') . '">'
        . '<span class="input-group-text">' . icon('fa-magnifying-glass') . '</span>'
        . '<input id="' . h($id) . '" type="search" class="form-control" placeholder="' . h($placeholder) . '">'
        . '</div>';
}

/**
 * A ordenação de uma listagem servida em páginas pelo servidor. Os valores são os que o
 * `sort` da API aceita, com o `-` a pedir descendente.
 *
 * Um `<select>` nativo e não um menu próprio: teclado, leitor de ecrã e o comportamento
 * em telefone vêm de borla.
 */
function sort_select(string $id): string
{
    $columns = [
        'imei' => 'IMEI',
        'company' => 'Empresa',
        'licenseName' => 'Licença',
        'supplier' => 'Fornecedor',
        'model' => 'Modelo',
        'deviceType' => 'Tipo',
    ];

    $options = '';
    foreach ($columns as $value => $label) {
        $options .= '<option value="' . h($value) . '">' . h($label) . ' (A→Z)</option>'
            . '<option value="-' . h($value) . '">' . h($label) . ' (Z→A)</option>';
    }

    return '<label for="' . h($id) . '" class="section-label mb-0">Ordenar</label>'
        . '<select id="' . h($id) . '" class="form-select form-select-sm w-auto">' . $options . '</select>';
}

function page_size_select(string $id): string
{
    $options = '';
    foreach ([5, 10, 15, 20, 30, 50] as $size) {
        $options .= '<option value="' . $size . '">' . $size . '</option>';
    }

    return '<label for="' . h($id) . '" class="section-label mb-0">Por página</label>'
        . '<select id="' . h($id) . '" class="form-select form-select-sm w-auto">' . $options . '</select>';
}

/**
 * A casca de uma tabela de listagem: o corpo fica vazio para o JavaScript o preencher.
 *
 * Uma coluna sem título passa como cadeia vazia -- é o caso da miniatura e da coluna de
 * acções, que não têm cabeçalho para mostrar. Uma coluna ordenável passa como
 * `[etiqueta, coluna]`, e a coluna é a chave que o JavaScript recebe no `data-sort`.
 *
 * @param list<string|array{string, string}> $headers
 */
function data_table(
    array $headers,
    string $tbodyId,
    string $tableClass = '',
    string $wrapperClass = ''
): string {
    // O `aria-sort` e a seta ficam por preencher: quem sabe por qual coluna a lista está
    // ordenada é o JavaScript, e o servidor desenha a casca sem esse estado.
    $cells = '';
    foreach ($headers as $header) {
        if (is_array($header)) {
            [$label, $column] = $header;
            $cells .= '<th scope="col" role="button" tabindex="0" data-sort="' . h($column) . '"'
                . ' aria-sort="none" class="user-select-none">' . h($label)
                . ' <span class="sort-mark" aria-hidden="true"></span></th>';
            continue;
        }

        $cells .= '<th>' . h($header) . '</th>';
    }

    // O `table-head-wide` esconde o cabeçalho no telemóvel, onde as células empilham e cada
    // uma leva a sua etiqueta. Está no `base.css` porque o Bootstrap não gera utilitário
    // para `table-header-group`.
    return '<div class="table-responsive' . ($wrapperClass !== '' ? ' ' . h($wrapperClass) : '') . '">'
        . '<table class="table table-sm align-middle' . ($tableClass !== '' ? ' ' . h($tableClass) : '') . '">'
        . '<thead class="table-head-wide"><tr>' . $cells . '</tr></thead>'
        . '<tbody id="' . h($tbodyId) . '"></tbody>'
        . '</table>'
        . '</div>';
}
