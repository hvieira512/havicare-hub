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

function page_size_select(string $id): string
{
    $options = '';
    foreach ([5, 10, 15, 20, 30, 50] as $size) {
        $options .= '<option value="' . $size . '">' . $size . '</option>';
    }

    return '<label for="' . h($id) . '" class="section-label mb-0">Por página</label>'
        . '<select id="' . h($id) . '" class="form-select form-select-sm w-auto">' . $options . '</select>';
}
