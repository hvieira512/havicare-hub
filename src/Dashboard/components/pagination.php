<?php

declare(strict_types=1);

require_once __DIR__ . '/helpers.php';

/**
 * O paginador partilhado por todas as listagens.
 *
 * `$spacing` existe porque o mesmo componente aparece com folgas opostas: por baixo de uma
 * lista quer margem em cima, por cima dela quer margem em baixo.
 *
 * `$withSummary` porque o "1–12 de 100" só diz alguma coisa onde mais nada o diga. Nos dois
 * painéis do dispositivo e no modal de escolha o total já está numa pastilha ao lado do
 * título, e repeti-lo era escrever o mesmo número duas vezes no mesmo ecrã. Nas listas das
 * definições não há outra contagem, e por isso lá fica.
 *
 * Sem resumo o paginador centra-se, porque passa a ser a única coisa na linha; com resumo
 * mantém-se o par "texto à esquerda, controlos à direita".
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
