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
 * O titulo de uma seccao, com um contador opcional ao lado.
 *
 * `$counterHidden` existe para os grupos de filtro: a pastilha comeca escondida e o
 * JavaScript mostra-a quando ha filtros aplicados. Sem isto os tres grupos do selector
 * de dispositivos tinham de escrever este cabecalho a mao para poderem juntar o `d-none`.
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
    return '<div id="' . h($id) . '" class="showcase-preview border rounded bg-body-tertiary d-flex align-items-center justify-content-center p-4 h-100"></div>';
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
 * Uma coluna sem titulo passa como cadeia vazia -- e o caso da miniatura e da coluna de
 * accoes, que nao tem cabecalho para mostrar.
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

    // Em telefone as celulas empilham (`d-block d-sm-table-cell` em cada linha) e cada uma
    // leva a etiqueta do campo ao lado do valor, por isso o cabecalho passaria a nomear
    // colunas que ja nao existem.
    return '<div class="table-responsive' . ($wrapperClass !== '' ? ' ' . h($wrapperClass) : '') . '">'
        . '<table class="table table-sm align-middle' . ($tableClass !== '' ? ' ' . h($tableClass) : '') . '">'
        . '<thead class="d-none d-sm-table-header-group"><tr>' . $cells . '</tr></thead>'
        . '<tbody id="' . h($tbodyId) . '"></tbody>'
        . '</table>'
        . '</div>';
}

/**
 * O cabecalho de uma aba: o nome, o resumo que o JavaScript escreve, e o que vai a direita.
 *
 * O que vai a direita entra como HTML ja pronto, e nao como um punhado de parametros: os
 * quatro sitios que usam isto levam la coisas diferentes -- uma pastilha de so leitura, um
 * botao que abre um formulario, um botao que anda no carrossel -- e uma assinatura com
 * `$badgeText`, `$buttonId`, `$buttonLabel`, `$buttonTarget` seria pior que a repeticao.
 */
function tab_pane_header(string $title, string $summaryId, string $trailingHtml = ''): string
{
    // `align-items-start`: com duas linhas a esquerda e um botao a direita, o botao fica ao
    // nivel do titulo em vez de flutuar entre as duas linhas. Tres dos quatro cabecalhos ja
    // o faziam; o dos modelos era o que estava fora.
    return '<div class="d-flex justify-content-between align-items-start gap-3 flex-wrap mb-3">'
        . '<div>'
        . '<div class="fw-semibold">' . h($title) . '</div>'
        . '<div class="small text-secondary" id="' . h($summaryId) . '"></div>'
        . '</div>'
        . $trailingHtml
        . '</div>';
}

