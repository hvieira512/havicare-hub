<?php

$onlineFilters = [
    ['id' => 'deviceOnlineAll', 'value' => 'all', 'label' => 'Todos', 'dot' => ''],
    ['id' => 'deviceOnlineOn', 'value' => 'online', 'label' => 'Ligados', 'dot' => 'text-success'],
    ['id' => 'deviceOnlineOff', 'value' => 'offline', 'label' => 'Desligados', 'dot' => 'text-body-secondary'],
];

ob_start();
?>
<div class="row g-0 device-selector-body">
    <div class="col-12 d-lg-none p-3 pb-0">
        <?= filter_toggle_button('deviceFilterPanel', 'deviceFilterCountMobile') ?>
    </div>
    <aside id="deviceFilterPanel" class="col-12 col-lg-4 device-filter-column collapse d-lg-block bg-body-tertiary">
        <div class="device-filter-head d-flex align-items-center gap-2 px-3 py-2 border-bottom">
            <span class="section-label">Filtrar</span>
            <span id="deviceFilterCount" class="count-chip count-chip-strong"></span>
            <button id="clearDeviceFiltersBtn" class="btn btn-sm btn-link ms-auto p-0 text-decoration-none d-none" type="button">Limpar</button>
        </div>

        <div class="device-filter-scroll d-flex flex-column gap-4 p-3">
            <div class="d-flex flex-column">
                <span class="section-label d-block mb-2">Estado</span>
                <div class="btn-group w-100" role="group" aria-label="Estado de ligação">
                    <?php foreach ($onlineFilters as $index => $filter) : ?>
                    <input type="radio" class="btn-check" name="deviceOnlineFilter" id="<?= $filter['id'] ?>" value="<?= $filter['value'] ?>" autocomplete="off"<?= $index === 0 ? ' checked' : '' ?>>
                    <label class="btn btn-sm btn-outline-secondary d-inline-flex align-items-center justify-content-center gap-1" for="<?= $filter['id'] ?>"><?= $filter['dot'] === '' ? '' : '<span class="state-badge-dot rounded-circle d-inline-block ' . $filter['dot'] . '" aria-hidden="true"></span>' ?><?= h($filter['label']) ?></label>
                    <?php endforeach; ?>
                </div>
            </div>

            <?= filter_group('Tipo', 'deviceTypeFilterCount', 'deviceTypeFilter', 'device-type-grid d-grid gap-2') ?>

            <?= filter_group('Licença', 'deviceLicenseFilterCount', 'deviceLicenseFilter', 'filter-list d-flex flex-column') ?>

            <?= filter_group('Modelo', 'deviceSupplierFilterCount', 'deviceSupplierFilter', 'filter-list d-flex flex-column') ?>
        </div>

        <div class="device-filter-foot d-lg-none p-3 border-top">
            <button id="applyDeviceFiltersBtn" class="btn btn-primary w-100" type="button" data-bs-toggle="collapse" data-bs-target="#deviceFilterPanel"></button>
        </div>
    </aside>

    <section class="col-12 col-lg-8 p-3 device-list-column">
        <?= search_input('deviceListSearch', 'Procurar IMEI, fornecedor ou modelo', 'mb-3') ?>
        <div id="deviceActiveFilters" class="d-flex flex-wrap gap-2 mb-3 d-none"></div>
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
<button type="button" class="btn btn-primary" id="openAddDeviceFromSelectorBtn"><?= icon('fa-plus', 'me-1') ?>Adicionar dispositivo</button>
<button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Fechar</button>
<?php
$footer = (string) ob_get_clean();

ob_start();
?>
<div class="flex-grow-1 min-w-0">
    <h2 class="modal-title h5 mb-0" id="deviceSelectorModalLabel">Escolher dispositivo</h2>
    <div id="deviceSelectorSummary" class="small text-secondary"></div>
</div>
<?php
$headerHtml = (string) ob_get_clean();

render_modal(
    id: 'deviceSelectorModal',
    body: $body,
    footer: $footer,
    size: 'xl',
    fullscreenBelow: 'lg',
    headerHtml: $headerHtml,
    bodyClass: 'p-0',
);
