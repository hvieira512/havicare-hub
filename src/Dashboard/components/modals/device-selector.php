<?php

declare(strict_types=1);

ob_start();
?>
<div class="row g-0 device-selector-body">
    <div class="col-12 d-lg-none p-3 pb-0">
        <?= filter_toggle_button('deviceFilterPanel', 'deviceFilterCountMobile') ?>
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

        <div class="d-flex flex-column">
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

        <?= filter_group('Tipo', 'deviceTypeFilterCount', 'deviceTypeFilter', 'device-type-grid') ?>
        <?php /* Os modelos vivem dentro do fornecedor a que pertencem, como as licenças
               * dentro da empresa. Eram dois grupos, e o de baixo precisava de procura
               * própria porque a lista de todos os modelos juntos era comprida; debaixo do
               * fornecedor são poucos e a procura deixou de fazer falta. */ ?>
        <?= filter_group('Fornecedor', 'deviceSupplierFilterCount', 'deviceSupplierFilter', 'filter-list') ?>

        <?= filter_group('Licença', 'deviceLicenseFilterCount', 'deviceLicenseFilter', 'filter-list') ?>
    </aside>

    <section class="col-12 col-lg-8 p-3 device-list-column">
        <?= search_input('deviceListSearch', 'Procurar IMEI, fornecedor ou modelo', 'mb-3') ?>
        <div id="deviceList" class="device-card-list d-flex flex-column gap-2"></div>
        <div class="d-flex justify-content-between align-items-center gap-2 mt-3 flex-wrap">
            <?= pagination_component('deviceListPagination') ?>
            <div class="d-flex align-items-center gap-2 ms-auto">
                <?= page_size_select('deviceListLimit') ?>
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
