<?php

$settingsTabs = [
    ['key' => 'Models', 'label' => 'Catálogo', 'icon' => 'fa-microchip', 'count' => true],
    ['key' => 'Capabilities', 'label' => 'Capacidades', 'icon' => 'fa-list-check', 'count' => false],
    ['key' => 'Company', 'label' => 'Licenças', 'icon' => 'fa-building', 'count' => true],
    ['key' => 'Denylist', 'label' => 'Bloqueados', 'icon' => 'fa-ban', 'count' => true],
    ['key' => 'ApiUsers', 'label' => 'Utilizadores API', 'icon' => 'fa-key', 'count' => true],
];

ob_start();
?>
<div class="settings-modal-shell d-flex flex-column w-100 p-2 p-lg-3">
    <div class="row g-3 g-lg-4 h-100 min-h-0">
        <div class="col-12 col-lg-3 d-flex align-items-lg-start h-100">
            <div class="nav nav-pills modal-side-nav flex-row flex-lg-column flex-wrap flex-lg-nowrap gap-2 w-100" role="tablist">
                <?php foreach ($settingsTabs as $index => $tab) : ?>
                    <?php $pane = 'settings' . $tab['key'] . 'Pane'; ?>
                <button class="nav-link<?= $index === 0 ? ' active' : '' ?> text-start d-flex align-items-center gap-2" id="settings<?= $tab['key'] ?>TabBtn" data-bs-toggle="pill" data-bs-target="#<?= $pane ?>" type="button" role="tab" aria-controls="<?= $pane ?>" aria-selected="<?= $index === 0 ? 'true' : 'false' ?>"><?= icon($tab['icon'], 'fa-fw') ?><?= h($tab['label']) ?><?= $tab['count'] ? '<span class="settings-nav-count d-none ms-auto flex-shrink-0 px-1 rounded-pill fw-semibold text-center tabular-nums" id="settings' . $tab['key'] . 'Count"></span>' : '' ?></button>
                <?php endforeach; ?>
            </div>
        </div>
        <div class="col-12 col-lg-9 d-flex flex-column min-h-0 h-100">
            <div class="tab-content flex-grow-1">
                <div class="tab-pane fade show active h-100" id="settingsModelsPane" role="tabpanel" aria-labelledby="settingsModelsTabBtn">
                    <nav aria-label="breadcrumb" id="modelsBreadcrumb" class="d-none">
                        <ol class="breadcrumb mb-3">
                            <li class="breadcrumb-item" id="modelsBreadcrumbModels">Catálogo</li>
                            <li class="breadcrumb-item d-none" id="modelsBreadcrumbNew">Novo modelo</li>
                            <li class="breadcrumb-item d-none" id="modelsBreadcrumbCurrent"></li>
                        </ol>
                    </nav>
                    <div id="modelsCarousel" class="carousel slide" data-bs-touch="false">
                        <div class="carousel-inner">
                            <div class="carousel-item active">
                                <?= tab_pane_header(
                                    'Catálogo',
                                    'modelsTabSummary',
                                    '<button type="button" class="btn btn-primary btn-sm" id="modelsNewModelBtn">' . icon('fa-plus', 'me-1') . 'Novo modelo</button>'
                                ) ?>
                                <?= search_input('modelsListSearch', 'Procurar modelo, fornecedor ou tipo', 'mt-3') ?>
                                <div id="modelCatalog" class="mt-3"></div>
                            </div>
                            <div class="carousel-item">
                                <div class="d-flex flex-column gap-4">
                                    <div class="wizard-trail d-flex align-items-center justify-content-center flex-wrap gap-2 border-bottom" id="modelWizardTrail" role="progressbar" aria-valuemin="1" aria-valuemax="3" aria-valuenow="1"></div>
                                    <div class="wizard-ask" id="modelWizardAsk"></div>
                                    <div class="small text-secondary d-none" id="modelWizardTemplateSummary"></div>
                                    <div class="d-flex align-items-center gap-2 border-top pt-3">
                                        <button type="button" class="btn btn-outline-secondary d-none" id="modelWizardBackBtn"></button>
                                        <button type="button" class="btn btn-primary ms-auto" id="modelWizardSaveBtn"></button>
                                    </div>
                                </div>
                            </div>
                            <div class="carousel-item">
                                <div class="d-flex align-items-center gap-2 mb-3">
                                    <button type="button" class="btn btn-sm btn-outline-secondary" data-action="backToModelList"><?= icon('fa-arrow-left', 'me-1') ?>Voltar</button>
                                </div>
                                <div class="row g-4 mb-4">
                                    <div class="col-lg-8" id="modelDetailFields">
                                        <div class="mb-3">
                                            <label for="modelDetailCommercialName" class="section-label">Nome comercial</label>
                                            <input type="text" class="form-control fw-semibold" id="modelDetailCommercialName">
                                        </div>
                                        <div class="row g-3">
                                            <div class="col-md-4">
                                                <label for="modelDetailSupplierSelect" class="section-label">Fornecedor</label>
                                                <select class="form-select" id="modelDetailSupplierSelect"></select>
                                            </div>
                                            <div class="col-md-4">
                                                <label for="modelDetailDeviceType" class="section-label">Tipo</label>
                                                <select class="form-select" id="modelDetailDeviceType"></select>
                                            </div>
                                            <div class="col-md-4">
                                                <label for="modelDetailInternalModel" class="section-label">Modelo interno</label>
                                                <input type="text" class="form-control" id="modelDetailInternalModel">
                                            </div>
                                        </div>
                                        <div class="d-flex align-items-center gap-2 mt-3">
                                            <span class="state-badge badge rounded-pill d-inline-flex align-items-center gap-1 text-uppercase fw-semibold lh-sm px-2 bg-secondary-subtle text-body-secondary" id="modelDetailDirtyState"><span class="state-badge-dot rounded-circle d-inline-block"></span>Sem alterações</span>
                                            <button type="button" class="btn btn-primary btn-sm d-none" id="modelDetailSaveBtn"><?= icon('fa-floppy-disk', 'me-1') ?>Guardar</button>
                                            <button type="button" class="btn btn-link btn-sm p-0 text-decoration-none d-none" id="modelDetailResetBtn">Descartar</button>
                                        </div>
                                    </div>
                                    <div class="col-lg-4">
                                        <div class="showcase-preview border rounded d-flex align-items-center justify-content-center p-4 h-100 position-relative" role="button" tabindex="0" title="Clique ou arraste para alterar a imagem">
                                            <input type="file" id="modelDetailImageInput" accept="image/*" class="position-absolute top-0 start-0 w-100 h-100 opacity-0 cursor-pointer">
                                            <div id="modelDetailImage" class="text-center w-100">
                                                <div class="text-secondary">
                                                    <?= icon('fa-microchip', 'fs-1 opacity-50') ?>
                                                    <div class="small mt-2" id="modelDetailName">Modelo</div>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                                <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3 border-top pt-4">
                                    <div>
                                        <div class="section-label mb-1" id="capabilityTitle">Capacidades</div>
                                        <div class="small text-secondary" id="capabilitySubtitle"></div>
                                    </div>
                                    <div class="d-flex align-items-center gap-2">
                                        <span id="capabilitySummary" class="small text-secondary"></span>
                                        <button id="saveCapabilitiesBtn" type="button" class="btn btn-primary btn-sm"><?= icon('fa-floppy-disk', 'me-1') ?>Guardar capacidades</button>
                                    </div>
                                </div>
                                <div id="capabilitySectionNav" class="capability-section-nav d-flex py-1 mb-3" role="group" aria-label="Secções de capacidade"></div>
                                <div id="capabilityGroups"></div>
                                <div class="border-top mt-4 pt-3 d-flex align-items-center justify-content-between gap-3 flex-wrap">
                                    <div>
                                        <div class="fw-semibold">Apagar este modelo</div>
                                        <div class="small text-secondary" id="modelDetailDeleteHint">Os dispositivos que o usam ficam sem template de capacidades.</div>
                                    </div>
                                    <button type="button" class="btn btn-outline-danger btn-sm flex-shrink-0" id="modelDetailDeleteBtn"><?= icon('fa-trash', 'me-1') ?>Apagar modelo</button>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="tab-pane fade h-100" id="settingsCapabilitiesPane" role="tabpanel" aria-labelledby="settingsCapabilitiesTabBtn">
                    <div class="mb-3">
                        <div class="fw-semibold">Capacidades</div>
                        <div class="small text-secondary" id="capabilitySupplierSummary"></div>
                    </div>
                    <?= search_input('capabilityCatalogSearch', 'Procurar capacidade ou chave') ?>
                    <div class="capability-filter-row d-grid gap-3 py-3">
                        <div>
                            <div class="section-label mb-1">Tipo de dispositivo</div>
                            <div id="capabilityDeviceTypeButtons" class="device-type-grid is-wide d-grid gap-2" role="group"></div>
                        </div>
                        <div>
                            <div class="section-label mb-1">Fornecedor</div>
                            <div id="capabilitySupplierButtons" class="btn-group flex-wrap" role="group"></div>
                        </div>
                    </div>
                    <div id="capabilityCatalogEmpty" class="text-secondary p-4 text-center d-none">
                        <?= icon('fa-sliders', 'fs-1 opacity-25') ?>
                        <div class="mt-2">Sem capacidades generalizadas definidas para este tipo de dispositivo.</div>
                    </div>
                    <div id="capabilityCatalogSectionNav" class="capability-section-nav position-sticky top-0 z-2 d-flex py-2 mb-1 bg-body" role="group" aria-label="Secções do catálogo"></div>
                    <div id="capabilityCatalogViewer" class="vstack gap-3"></div>
                </div>
                <div class="tab-pane fade h-100" id="settingsCompanyPane" role="tabpanel" aria-labelledby="settingsCompanyTabBtn">
                    <?= tab_pane_header(
                        'Licenças',
                        'companiesTabSummary',
                        '<button type="button" class="btn btn-primary btn-sm flex-shrink-0" id="newCompanyBtn">'
                        . icon('fa-plus', 'me-1') . 'Nova empresa</button>'
                    ) ?>
                    <div id="companyListBody" class="mb-4"></div>
                    <?= pagination_component('settingsCompanyPagination') ?>
                </div>
                <div class="tab-pane fade h-100" id="settingsDenylistPane" role="tabpanel" aria-labelledby="settingsDenylistTabBtn">
                    <?= tab_pane_header('Aparelhos bloqueados', 'denylistTabSummary', titleId: 'denylistTabTitle') ?>
                    <div id="denylistListBody" class="mb-4"></div>
                </div>
                <div class="tab-pane fade h-100" id="settingsApiUsersPane" role="tabpanel" aria-labelledby="settingsApiUsersTabBtn">
                    <?= tab_pane_header(
                        'Utilizadores API',
                        'apiUsersTabSummary',
                        '<button type="button" class="btn btn-primary btn-sm flex-shrink-0" id="newApiUserBtn">'
                        . icon('fa-plus', 'me-1') . 'Novo utilizador</button>'
                    ) ?>
                    <div id="apiUserList">
                        <div id="apiUserCreateRow"></div>
                        <div id="apiUserGrid" class="settings-grid"></div>
                    </div>
                    <?= pagination_component('settingsApiUsersPagination') ?>
                </div>
            </div>
        </div>
    </div>
</div>
<?php
$body = (string) ob_get_clean();

$footer = '<button type="button" class="btn btn-outline-secondary" id="settingsCloseBtn" data-bs-dismiss="modal">Fechar</button>';

render_modal(
    id: 'settingsModal',
    title: 'Definições',
    body: $body,
    footer: $footer,
    size: 'xl',
    fullscreenBelow: 'lg',
    bodyClass: 'd-flex flex-column overflow-auto min-h-0 p-0',
);
