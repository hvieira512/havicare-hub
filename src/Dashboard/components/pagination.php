<?php

declare(strict_types=1);

require_once __DIR__ . '/helpers.php';

function pagination_component(string $idPrefix): string
{
    $rootId = h($idPrefix);
    $summaryId = h($idPrefix . 'Summary');
    $controlsId = h($idPrefix . 'Controls');
    $prevId = h($idPrefix . 'Prev');
    $nextId = h($idPrefix . 'Next');

    return <<<HTML
<div id="{$rootId}" class="d-none mt-3">
    <div class="d-flex justify-content-between align-items-center gap-2 flex-wrap">
        <span id="{$summaryId}" class="small text-secondary"></span>
        <div class="d-flex align-items-center gap-2">
            <button id="{$prevId}" type="button" class="btn btn-outline-secondary btn-sm" data-action="paginationPrev" aria-label="Página anterior">
                <i class="fa-solid fa-chevron-left"></i>
            </button>
            <div id="{$controlsId}" class="btn-group btn-group-sm" role="group" aria-label="Paginação"></div>
            <button id="{$nextId}" type="button" class="btn btn-outline-secondary btn-sm" data-action="paginationNext" aria-label="Página seguinte">
                <i class="fa-solid fa-chevron-right"></i>
            </button>
        </div>
    </div>
</div>
HTML;
}
