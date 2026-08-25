<?php

declare(strict_types=1);

ob_start();
?>
<div class="row g-0 device-selector-body">
    <div class="col-12 d-lg-none p-3 pb-0">
        <button class="btn btn-sm btn-outline-secondary d-inline-flex align-items-center gap-2" type="button"
            data-bs-toggle="collapse" data-bs-target="#deviceFilterPanel" aria-expanded="false" aria-controls="deviceFilterPanel">
            <?= icon('fa-sliders') ?>Filtros
            <span id="deviceFilterCountMobile" class="count-chip count-chip-strong d-none"></span>
        </button>
    </div>
    <aside id="deviceFilterPanel" class="col-12 col-lg-4 p-3 device-filter-column collapse d-lg-block">
        <div class="d-flex align-items-center justify-content-between gap-2">
            <div class="d-flex align-items-center gap-2">
                <span class="section-label">Filtros</span>
                <span id="deviceFilterCount" class="count-chip count-chip-strong d-none"></span>
            </div>
            <button id="clearDeviceFiltersBtn" class="btn btn-sm btn-outline-secondary d-none" type="button">
                <?= icon('fa-filter-circle-xmark', 'me-1') ?>Limpar
            </button>
        </div>

        <div class="filter-group">
            <span class="section-label d-block mb-2">Estado</span>
            <div class="btn-group w-100" role="group" aria-label="Estado de ligação">
                <input type="radio" class="btn-check" name="deviceOnlineFilter" id="deviceOnlineAll" value="all" autocomplete="off" checked>
                <label class="btn btn-sm btn-outline-secondary" for="deviceOnlineAll">Todos</label>
                <input type="radio" class="btn-check" name="deviceOnlineFilter" id="deviceOnlineOn" value="online" autocomplete="off">
                <label class="btn btn-sm btn-outline-secondary" for="deviceOnlineOn">Ligados</label>
                <input type="radio" class="btn-check" name="deviceOnlineFilter" id="deviceOnlineOff" value="offline" autocomplete="off">
                <label class="btn btn-sm btn-outline-secondary" for="deviceOnlineOff">Desligados</label>
            </div>
        </div>

        <div class="filter-group">
            <div class="d-flex align-items-center gap-2 mb-2">
                <span class="section-label">Tipo</span>
                <span id="deviceTypeFilterCount" class="count-chip d-none"></span>
            </div>
            <div id="deviceTypeFilter" class="device-type-grid"></div>
        </div>

        <div class="filter-group">
            <div class="d-flex align-items-center gap-2 mb-2">
                <span class="section-label">Fornecedor</span>
                <span id="deviceSupplierFilterCount" class="count-chip d-none"></span>
            </div>
            <div id="deviceSupplierFilter" class="filter-list"></div>
        </div>

        <div class="filter-group">
            <div class="d-flex align-items-center gap-2 mb-2">
                <span class="section-label">Modelo</span>
                <span id="deviceModelFilterCount" class="count-chip d-none"></span>
            </div>
            <div class="input-group input-group-sm mb-2">
                <span class="input-group-text"><?= icon('fa-magnifying-glass') ?></span>
                <input id="deviceModelFilterSearch" type="search" class="form-control" placeholder="Procurar modelo">
            </div>
            <div id="deviceModelFilter" class="filter-list"></div>
        </div>

        <div class="filter-group">
            <div class="d-flex align-items-center gap-2 mb-2">
                <span class="section-label">Licença</span>
                <span id="deviceLicenseFilterCount" class="count-chip d-none"></span>
            </div>
            <div id="deviceLicenseFilter" class="filter-list"></div>
        </div>
    </aside>

    <section class="col-12 col-lg-8 p-3 device-list-column">
        <div class="input-group input-group-sm mb-3">
            <span class="input-group-text"><?= icon('fa-magnifying-glass') ?></span>
            <input id="deviceListSearch" type="search" class="form-control" placeholder="Procurar IMEI, fornecedor ou modelo">
        </div>
        <div id="deviceList" class="device-card-list"></div>
        <div class="d-flex justify-content-between align-items-center gap-2 mt-3 flex-wrap">
            <?= pagination_component('deviceListPagination') ?>
            <div class="d-flex align-items-center gap-2 ms-auto">
                <label for="deviceListLimit" class="section-label mb-0">Por página</label>
                <select id="deviceListLimit" class="form-select form-select-sm w-auto">
                    <option value="5">5</option>
                    <option value="10">10</option>
                    <option value="15">15</option>
                    <option value="20">20</option>
                    <option value="30">30</option>
                    <option value="50">50</option>
                </select>
            </div>
        </div>
    </section>
</div>
<?php
$body = (string) ob_get_clean();

ob_start();
?>
<button type="button" class="btn btn-outline-primary" id="openAddDeviceFromSelectorBtn"><?= icon('fa-plus', 'me-1') ?>Adicionar dispositivo</button>
<button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Fechar</button>
<?php
$footer = (string) ob_get_clean();

ob_start();
?>
<div class="flex-grow-1 min-width-0">
    <h2 class="modal-title h5 mb-0">Escolher dispositivo</h2>
    <div id="deviceSelectorSummary" class="small text-secondary"></div>
</div>
<?php
$headerHtml = (string) ob_get_clean();

render_modal(
    'deviceSelectorModal',
    'Escolher dispositivo',
    $body,
    $footer,
    'modal-xl modal-fullscreen-lg-down',
    $headerHtml,
    // Sem padding no corpo: são as colunas que o trazem, e assim chegam às bordas dele.
    'p-0'
);
