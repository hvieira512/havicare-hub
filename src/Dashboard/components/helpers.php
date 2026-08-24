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
 * O cabeçalho de uma secção dentro de um cartão.
 *
 * Era um `h2.h6`, que dava a uma secção o mesmo peso que ao título do ecrã. Passa à
 * etiqueta em maiúsculas — dentro de um cartão, é o título do cartão que manda.
 *
 * O contador tem duas formas. Como pastilha, encostado à etiqueta: é o próprio número de
 * linhas da lista que vem a seguir, e lê-se com o título. Como texto no extremo oposto,
 * quando não conta as linhas mas descreve o bloco ("8 disponíveis").
 */
function section_header(string $title, ?string $counterId = null, bool $chip = false): string
{
    if ($counterId !== null && $chip) {
        return '<div class="d-flex align-items-center gap-2 mb-2">'
            . '<span class="section-label">' . h($title) . '</span>'
            . '<span class="count-chip" id="' . h($counterId) . '"></span>'
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

