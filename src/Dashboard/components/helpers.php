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

function section_header(
    string $title,
    ?string $counterId = null,
    string $spacing = 'mb-2'
): string {
    if ($counterId !== null) {
        return '<div class="' . h(trim('d-flex align-items-center gap-2 ' . $spacing)) . '">'
            . '<span class="section-label">' . h($title) . '</span>'
            . '<span class="count-chip" id="' . h($counterId) . '"></span>'
            . '</div>';
    }

    return '<div class="' . h(trim('d-flex justify-content-between align-items-center gap-2 ' . $spacing)) . '">'
        . '<span class="section-label">' . h($title) . '</span>'
        . '</div>';
}

function showcase_preview(string $id): string
{
    return '<div id="' . h($id) . '" class="showcase-preview border rounded d-flex align-items-center justify-content-center p-4 h-100"></div>';
}

function filter_group(string $title, string $counterId, string $contentId, string $contentClass): string
{
    return '<div class="d-flex flex-column">'
        . section_header($title, $counterId)
        . '<div id="' . h($contentId) . '" class="' . h($contentClass) . '"></div>'
        . '</div>';
}

function filter_toggle_button(string $targetId, string $countId, string $extraClass = ''): string
{
    return '<button class="btn btn-sm btn-outline-secondary d-inline-flex align-items-center gap-2'
        . ($extraClass !== '' ? ' ' . h($extraClass) : '') . '" type="button"'
        . ' data-bs-toggle="collapse" data-bs-target="#' . h($targetId) . '"'
        . ' aria-expanded="false" aria-controls="' . h($targetId) . '">'
        . icon('fa-sliders') . 'Filtros'
        . '<span id="' . h($countId) . '" class="count-chip count-chip-strong"></span>'
        . '</button>';
}

function tab_pane_header(
    string $title,
    string $summaryId,
    string $trailingHtml = '',
    string $titleId = '',
): string {
    // O `titleId` é para os separadores cujo título muda com o que a lista trouxe.
    $id = $titleId === '' ? '' : ' id="' . h($titleId) . '"';

    return '<div class="d-flex justify-content-between align-items-start gap-3 flex-wrap mb-3">'
        . '<div>'
        . '<div class="fw-semibold"' . $id . '>' . h($title) . '</div>'
        . '<div class="small text-secondary" id="' . h($summaryId) . '"></div>'
        . '</div>'
        . $trailingHtml
        . '</div>';
}
