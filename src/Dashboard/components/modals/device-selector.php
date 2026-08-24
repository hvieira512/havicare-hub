<?php

declare(strict_types=1);

ob_start();
?>
<?php
/**
 * A pesquisa e o botão de filtros na primeira linha, os filtros aplicados na segunda.
 *
 * Os cinco selects viviam abertos numa caixa cinzenta no topo, e eram sete controlos
 * antes da primeira linha de dados. Passam para trás do botão, que leva o contador de
 * quantos estão ligados — o que está aplicado lê-se nas pastilhas, sem abrir nada.
 */
?>
<div class="d-flex align-items-center gap-2">
    <div class="input-group input-group-sm flex-grow-1">
        <span class="input-group-text"><?= icon('fa-magnifying-glass') ?></span>
        <input id="deviceListSearch" type="search" class="form-control" placeholder="Procurar IMEI, fornecedor ou modelo">
    </div>
    <button class="btn btn-sm btn-outline-secondary d-inline-flex align-items-center gap-2 flex-shrink-0" type="button"
        data-bs-toggle="collapse" data-bs-target="#deviceFiltersCollapse" aria-expanded="false" aria-controls="deviceFiltersCollapse">
        <?= icon('fa-sliders') ?>Filtros
        <span id="deviceFilterCount" class="badge rounded-pill text-bg-primary d-none"></span>
    </button>
</div>
<div class="d-flex flex-wrap align-items-center gap-2 mt-2">
    <div id="deviceActiveFilters" class="d-flex flex-wrap gap-2"></div>
    <button id="clearDeviceFiltersBtn" class="btn btn-link btn-sm p-0 text-decoration-none text-secondary small d-none" type="button">Limpar</button>
</div>
<div class="collapse" id="deviceFiltersCollapse">
    <div class="row g-2 pt-3">
        <div class="col-12 col-md-2">
            <label for="deviceTypeFilter" class="form-label form-label-sm mb-1 small text-secondary">Tipo</label>
            <select id="deviceTypeFilter" class="form-select form-select-sm"></select>
        </div>
        <div class="col-12 col-md-2">
            <label for="deviceCompanyFilter" class="form-label form-label-sm mb-1 small text-secondary">Empresa</label>
            <select id="deviceCompanyFilter" class="form-select form-select-sm"></select>
        </div>
        <div class="col-12 col-md-2">
            <label for="deviceLicenseFilter" class="form-label form-label-sm mb-1 small text-secondary">Licença</label>
            <select id="deviceLicenseFilter" class="form-select form-select-sm"></select>
        </div>
        <div class="col-12 col-md-3">
            <label for="deviceSupplierFilter" class="form-label form-label-sm mb-1 small text-secondary">Fornecedor</label>
            <select id="deviceSupplierFilter" class="form-select form-select-sm"></select>
        </div>
        <div class="col-12 col-md-3">
            <label for="deviceModelFilter" class="form-label form-label-sm mb-1 small text-secondary">Modelo</label>
            <select id="deviceModelFilter" class="form-select form-select-sm"></select>
        </div>
    </div>
</div>
<div id="deviceList" class="mt-3"></div>
<div class="d-flex justify-content-end align-items-center gap-2 mt-3">
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
<?= pagination_component('deviceListPagination') ?>
<?php
$body = (string) ob_get_clean();

ob_start();
?>
<button type="button" class="btn btn-outline-primary" id="openAddDeviceFromSelectorBtn"><?= icon('fa-plus', 'me-1') ?>Adicionar dispositivo</button>
<button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Fechar</button>
<?php
$footer = (string) ob_get_clean();

render_modal('deviceSelectorModal', 'Selecionar dispositivo', $body, $footer, 'modal-xl modal-fullscreen-md-down modal-dialog-scrollable');
