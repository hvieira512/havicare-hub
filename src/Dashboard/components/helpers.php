<?php

declare(strict_types=1);

function h(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function icon(string $name, string $class = ''): string
{
    return '<i class="fa-solid ' . h($name) . ($class !== '' ? ' ' . h($class) : '') . '"></i>';
}

/**
 * O título de uma secção, com um contador opcional ao lado.
 *
 * `$counterHidden` existe para os grupos de filtro: a pastilha começa escondida e o
 * JavaScript mostra-a quando há filtros aplicados.
 */
function section_header(
    string $title,
    ?string $counterId = null,
    bool $chip = false,
    bool $counterHidden = false
): string {
    if ($counterId !== null && $chip) {
        return '<div class="d-flex align-items-center gap-2 mb-2">'
            . '<span class="section-label">' . h($title) . '</span>'
            . '<span class="count-chip' . ($counterHidden ? ' d-none' : '') . '" id="' . h($counterId) . '"></span>'
            . '</div>';
    }

    return '<div class="d-flex justify-content-between align-items-center gap-2 mb-2">'
        . '<span class="section-label">' . h($title) . '</span>'
        . ($counterId !== null ? '<span class="small text-secondary" id="' . h($counterId) . '"></span>' : '')
        . '</div>';
}

function showcase_preview(string $id): string
{
    return '<div id="' . h($id) . '" class="showcase-preview border rounded d-flex align-items-center justify-content-center p-4 h-100"></div>';
}

/** Um grupo de filtro: o titulo com a contagem, e o contentor que o JavaScript preenche. */
function filter_group(string $title, string $counterId, string $contentId, string $contentClass): string
{
    return '<div class="d-flex flex-column">'
        . section_header($title, $counterId, true, true)
        . '<div id="' . h($contentId) . '" class="' . h($contentClass) . '"></div>'
        . '</div>';
}

/** A caixa de pesquisa, com a lupa colada a esquerda. */
function search_input(string $id, string $placeholder, string $wrapperClass = ''): string
{
    return '<div class="input-group input-group-sm' . ($wrapperClass !== '' ? ' ' . h($wrapperClass) : '') . '">'
        . '<span class="input-group-text">' . icon('fa-magnifying-glass') . '</span>'
        . '<input id="' . h($id) . '" type="search" class="form-control" placeholder="' . h($placeholder) . '">'
        . '</div>';
}

/**
 * O botao que abre o painel de filtros, com a contagem do que esta aplicado.
 *
 * O `aria-expanded` faz parte do contrato do collapse do Bootstrap e faltava em metade
 * dos sitios que escreviam este botao a mao.
 */
function filter_toggle_button(string $targetId, string $countId, string $extraClass = ''): string
{
    return '<button class="btn btn-sm btn-outline-secondary d-inline-flex align-items-center gap-2'
        . ($extraClass !== '' ? ' ' . h($extraClass) : '') . '" type="button"'
        . ' data-bs-toggle="collapse" data-bs-target="#' . h($targetId) . '"'
        . ' aria-expanded="false" aria-controls="' . h($targetId) . '">'
        . icon('fa-sliders') . 'Filtros'
        . '<span id="' . h($countId) . '" class="count-chip count-chip-strong d-none"></span>'
        . '</button>';
}

/** O selector de quantas linhas por pagina. */
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
 * acções, que não têm cabeçalho para mostrar.
 *
 * @param list<string> $headers
 */
function data_table(
    array $headers,
    string $tbodyId,
    string $tableClass = '',
    string $wrapperClass = ''
): string {
    $cells = '';
    foreach ($headers as $header) {
        $cells .= '<th>' . h($header) . '</th>';
    }

    // Em telefone as células empilham (`d-block d-sm-table-cell` em cada linha) e cada uma
    // leva a etiqueta do campo ao lado do valor, por isso o cabeçalho nomearia colunas que
    // ali não existem.
    return '<div class="table-responsive' . ($wrapperClass !== '' ? ' ' . h($wrapperClass) : '') . '">'
        . '<table class="table table-sm align-middle' . ($tableClass !== '' ? ' ' . h($tableClass) : '') . '">'
        . '<thead class="d-none d-sm-table-header-group"><tr>' . $cells . '</tr></thead>'
        . '<tbody id="' . h($tbodyId) . '"></tbody>'
        . '</table>'
        . '</div>';
}

/**
 * O cabeçalho de uma aba: o nome, o resumo que o JavaScript escreve, e o que vai à direita.
 *
 * O que vai à direita entra como HTML já pronto e não como parâmetros escalares: os quatro
 * sítios que usam isto levam lá coisas diferentes, e uma assinatura com `$badgeText`,
 * `$buttonId`, `$buttonLabel` e `$buttonTarget` seria pior que a repetição.
 */
function tab_pane_header(string $title, string $summaryId, string $trailingHtml = ''): string
{
    // `align-items-start`: com duas linhas à esquerda e um botão à direita, o botão fica ao
    // nível do título em vez de flutuar entre as duas linhas.
    return '<div class="d-flex justify-content-between align-items-start gap-3 flex-wrap mb-3">'
        . '<div>'
        . '<div class="fw-semibold">' . h($title) . '</div>'
        . '<div class="small text-secondary" id="' . h($summaryId) . '"></div>'
        . '</div>'
        . $trailingHtml
        . '</div>';
}
