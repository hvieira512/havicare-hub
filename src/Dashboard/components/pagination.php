<?php

declare(strict_types=1);

require_once __DIR__ . '/helpers.php';

/**
 * O paginador partilhado por todas as listagens.
 *
 * `$spacing` porque o componente aparece com folgas opostas conforme esteja acima ou abaixo
 * da lista. `$withSummary` porque o "1–12 de 100" só diz alguma coisa onde mais nada o diga --
 * nos painéis do dispositivo o total já está numa pastilha ao lado do título.
 *
 * Sem resumo o paginador centra-se, por ser a única coisa na linha.
 */
function pagination_component(
    string $idPrefix,
    string $spacing = 'mt-3',
    bool $withSummary = true
): string {
    $rootId = h($idPrefix);
    $summaryId = h($idPrefix . 'Summary');
    $controlsId = h($idPrefix . 'Controls');
    $spacing = h($spacing);

    $summary = $withSummary
        ? "<span id=\"{$summaryId}\" class=\"small text-secondary\"></span>"
        : '';
    $row = $withSummary ? 'justify-content-between' : 'justify-content-center';

    return <<<HTML
<div id="{$rootId}" class="d-none {$spacing}">
    <div class="d-flex {$row} align-items-center gap-2 flex-wrap">
        {$summary}
        <nav aria-label="Paginação">
            <ul id="{$controlsId}" class="pagination pagination-sm mb-0 gap-1 flex-wrap justify-content-center"></ul>
        </nav>
    </div>
</div>
HTML;
}
