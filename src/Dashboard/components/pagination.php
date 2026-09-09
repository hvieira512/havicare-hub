<?php

require_once __DIR__ . '/helpers.php';

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
