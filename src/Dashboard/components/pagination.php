<?php

declare(strict_types=1);

require_once __DIR__ . '/helpers.php';

function pagination_component(string $idPrefix): string
{
    $rootId = h($idPrefix);
    $summaryId = h($idPrefix . 'Summary');
    $controlsId = h($idPrefix . 'Controls');

    return <<<HTML
<div id="{$rootId}" class="d-none mt-3">
    <div class="d-flex justify-content-between align-items-center gap-2 flex-wrap">
        <span id="{$summaryId}" class="small text-secondary"></span>
        <div id="{$controlsId}" class="btn-group btn-group-sm" role="group" aria-label="Paginação"></div>
    </div>
</div>
HTML;
}
