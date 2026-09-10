<?php

/* O ponto é o mesmo do `state-badge`, na cor do mesmo estado: o filtro e as pastilhas das
 * linhas passam a falar a mesma língua. A forma continua a ser a de um botão -- dar-lhes
 * aspecto de pastilha convidava a clicar nas pastilhas das linhas, que não se clicam. */
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
    <aside id="deviceFilterPanel" class="col-12 col-lg-4 p-3 device-filter-column collapse d-lg-block bg-body-tertiary">
        <div class="d-flex align-items-center justify-content-between gap-2">
            <div class="d-flex align-items-center gap-2">
                <span class="section-label">Filtros</span>
                <span id="deviceFilterCount" class="count-chip count-chip-strong fw-semibold px-2 rounded-pill tabular-nums d-none"></span>
            </div>
            <button id="clearDeviceFiltersBtn" class="btn btn-sm btn-outline-secondary d-none" type="button">
                <?= icon('fa-filter-circle-xmark', 'me-1') ?>Limpar
            </button>
        </div>

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

        <?php /* Diz "Modelo" e os `id` dizem `supplier`: o fornecedor é o agrupador e a chave
               * do filtro, o modelo é o que se escolhe. */ ?>
        <?= filter_group('Modelo', 'deviceSupplierFilterCount', 'deviceSupplierFilter', 'filter-list d-flex flex-column') ?>
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
<button type="button" class="btn btn-primary" id="openAddDeviceFromSelectorBtn"><?= icon('fa-plus', 'me-1') ?>Adicionar dispositivo</button>
<button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Fechar</button>
<?php
$footer = (string) ob_get_clean();

ob_start();
?>
<div class="flex-grow-1 min-w-0">
    <h2 class="modal-title h5 mb-0">Escolher dispositivo</h2>
    <div id="deviceSelectorSummary" class="small text-secondary"></div>
</div>
<?php
$headerHtml = (string) ob_get_clean();

render_modal(
    id: 'deviceSelectorModal',
    title: 'Escolher dispositivo',
    body: $body,
    footer: $footer,
    size: 'xl',
    fullscreenBelow: 'lg',
    headerHtml: $headerHtml,
    // Sem padding no corpo: são as colunas que o trazem, e assim chegam às bordas dele.
    bodyClass: 'p-0',
);
