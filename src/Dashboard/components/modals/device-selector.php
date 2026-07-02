<?php

declare(strict_types=1);

ob_start();
?>
<div class="border rounded bg-body-tertiary p-3 mb-3">
    <div class="row g-2">
        <div class="col-12 col-md-3">
            <label for="deviceTypeFilter" class="form-label form-label-sm mb-1 small text-secondary">Tipo</label>
            <select id="deviceTypeFilter" class="form-select form-select-sm"></select>
        </div>
        <div class="col-12 col-md-3">
            <label for="deviceLicenseFilter" class="form-label form-label-sm mb-1 small text-secondary">Licença</label>
            <select id="deviceLicenseFilter" class="form-select form-select-sm"></select>
        </div>
        <div class="col-12 col-md-3">
            <label for="deviceCompanyFilter" class="form-label form-label-sm mb-1 small text-secondary">Empresa</label>
            <select id="deviceCompanyFilter" class="form-select form-select-sm"></select>
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
    <div class="d-flex justify-content-between align-items-start gap-2 flex-wrap mt-3">
        <div id="deviceActiveFilters" class="d-inline-flex flex-wrap gap-2"></div>
        <button id="clearDeviceFiltersBtn" class="btn btn-sm btn-outline-secondary" type="button">Limpar filtros</button>
    </div>
</div>
<div class="d-flex justify-content-between align-items-center gap-3 flex-wrap mb-3">
    <select id="deviceListLimit" class="form-select form-select-sm w-auto">
        <option value="5">5</option>
        <option value="10">10</option>
        <option value="15">15</option>
        <option value="20">20</option>
        <option value="30">30</option>
        <option value="50">50</option>
    </select>
    <div class="flex-grow-1" style="min-width: 220px;">
        <div class="input-group input-group-sm">
            <span class="input-group-text"><?= icon('fa-magnifying-glass') ?></span>
            <input id="deviceListSearch" type="search" class="form-control" placeholder="Pesquisar IMEI, fornecedor ou modelo">
        </div>
    </div>
</div>
<div id="deviceList"></div>
<?= pagination_component('deviceListPagination') ?>
<?php
$body = (string) ob_get_clean();

ob_start();
?>
<button type="button" class="btn btn-outline-primary" id="openAddDeviceFromSelectorBtn"><?= icon('fa-plus', 'me-1') ?>Adicionar dispositivo</button>
<button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Fechar</button>
<?php
$footer = (string) ob_get_clean();

render_modal('deviceSelectorModal', 'Selecionar dispositivo', $body, $footer, 'modal-xl modal-dialog-scrollable');
