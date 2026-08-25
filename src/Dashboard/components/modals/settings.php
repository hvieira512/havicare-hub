<?php

declare(strict_types=1);

ob_start();
?>
<div class="settings-modal-shell d-flex flex-column h-100">
    <div class="row g-4 h-100 align-items-lg-center">
        <div class="col-12 col-lg-2 d-flex align-items-lg-center h-100">
            <div class="nav nav-pills settings-modal-nav flex-row flex-lg-column flex-nowrap justify-content-lg-start gap-2 w-100" id="settingsModalNav" role="tablist">
                <button class="nav-link active text-start d-flex align-items-center justify-content-between gap-2" id="settingsSuppliersTabBtn" data-bs-toggle="pill" data-bs-target="#settingsSuppliersPane" type="button" role="tab" aria-controls="settingsSuppliersPane" aria-selected="true">Fornecedores<span class="settings-nav-count d-none" id="settingsSuppliersCount"></span></button>
                <button class="nav-link text-start d-flex align-items-center justify-content-between gap-2" id="settingsModelsTabBtn" data-bs-toggle="pill" data-bs-target="#settingsModelsPane" type="button" role="tab" aria-controls="settingsModelsPane" aria-selected="false">Modelos<span class="settings-nav-count d-none" id="settingsModelsCount"></span></button>
                <button class="nav-link text-start" id="settingsCapabilitiesTabBtn" data-bs-toggle="pill" data-bs-target="#settingsCapabilitiesPane" type="button" role="tab" aria-controls="settingsCapabilitiesPane" aria-selected="false">Capacidades</button>
                <button class="nav-link text-start d-flex align-items-center justify-content-between gap-2" id="settingsCompanyTabBtn" data-bs-toggle="pill" data-bs-target="#settingsCompanyPane" type="button" role="tab" aria-controls="settingsCompanyPane" aria-selected="false">Empresas<span class="settings-nav-count d-none" id="settingsCompanyCount"></span></button>
                <button class="nav-link text-start d-flex align-items-center justify-content-between gap-2" id="settingsApiUsersTabBtn" data-bs-toggle="pill" data-bs-target="#settingsApiUsersPane" type="button" role="tab" aria-controls="settingsApiUsersPane" aria-selected="false">Utilizadores API<span class="settings-nav-count d-none" id="settingsApiUsersCount"></span></button>
            </div>
        </div>
        <div class="col-12 col-lg-10 d-flex flex-column h-100">
            <div class="tab-content flex-grow-1">
                <div class="tab-pane fade show active h-100" id="settingsSuppliersPane" role="tabpanel" aria-labelledby="settingsSuppliersTabBtn">
                    <div class="d-flex align-items-start justify-content-between gap-3 flex-wrap mb-3">
                        <div>
                            <div class="fw-semibold">Fornecedores</div>
                            <div class="small text-secondary" style="max-width:52ch">Definidos em código. Não se acrescentam nem removem pelo painel — aparecem quando um modelo novo os traz.</div>
                        </div>
                        <span class="config-state config-state-secondary flex-shrink-0"><span class="config-state-dot"></span>Só leitura</span>
                    </div>
                    <div class="table-responsive">
                        <table class="table table-sm align-middle">
                            <thead>
                                <tr>
                                    <th>Nome</th>
                                    <th>Modelos</th>
                                    <th>Estado</th>
                                    <th></th>
                                </tr>
                            </thead>
                            <tbody id="supplierListBody"></tbody>
                        </table>
                    </div>
                    <?= pagination_component('settingsSuppliers') ?>
                </div>
                <div class="tab-pane fade h-100" id="settingsModelsPane" role="tabpanel" aria-labelledby="settingsModelsTabBtn">
                    <nav aria-label="breadcrumb">
                        <ol class="breadcrumb mb-3">
                            <li class="breadcrumb-item" id="modelsBreadcrumbModels">Modelos</li>
                            <li class="breadcrumb-item d-none" id="modelsBreadcrumbNew">Novo modelo</li>
                            <li class="breadcrumb-item d-none" id="modelsBreadcrumbCurrent"></li>
                        </ol>
                    </nav>
                    <div id="modelsCarousel" class="carousel slide" data-bs-touch="false">
                        <div class="carousel-inner">
                            <div class="carousel-item active">
                                <div class="d-flex align-items-center justify-content-between gap-3 flex-wrap mb-3">
                                    <div>
                                        <div class="fw-semibold">Modelos</div>
                                        <div class="small text-secondary" id="modelsTabSummary"></div>
                                    </div>
                                    <button type="button" class="btn btn-primary btn-sm" id="modelsNewModelBtn"><?= icon('fa-plus', 'me-1') ?>Novo modelo</button>
                                </div>
                                <div class="d-flex align-items-center gap-2">
                                    <div class="input-group input-group-sm flex-grow-1">
                                        <span class="input-group-text"><?= icon('fa-magnifying-glass') ?></span>
                                        <input id="modelsListSearch" type="search" class="form-control" placeholder="Procurar modelo">
                                    </div>
                                    <button class="btn btn-sm btn-outline-secondary d-inline-flex align-items-center gap-2 flex-shrink-0" type="button"
                                        data-bs-toggle="collapse" data-bs-target="#modelsFiltersCollapse" aria-expanded="false" aria-controls="modelsFiltersCollapse">
                                        <?= icon('fa-sliders') ?>Filtros
                                        <span id="modelsFilterCount" class="badge rounded-pill text-bg-primary d-none"></span>
                                    </button>
                                </div>
                                <div class="d-flex flex-wrap align-items-center gap-2 mt-2">
                                    <div id="modelsActiveFilters" class="d-flex flex-wrap gap-2"></div>
                                    <button type="button" class="btn btn-link btn-sm p-0 text-decoration-none text-secondary small d-none" id="clearModelsFiltersBtn">Limpar</button>
                                </div>
                                <div class="collapse" id="modelsFiltersCollapse">
                                    <div class="row g-3 pt-3">
                                        <div class="col-12 col-md-6">
                                            <div class="section-label mb-1">Tipo de dispositivo</div>
                                            <div id="modelsDeviceTypeButtons" class="device-type-grid is-wide w-100" role="group"></div>
                                        </div>
                                        <div class="col-12 col-md-6">
                                            <div class="section-label mb-1">Fornecedor</div>
                                            <div id="modelsSupplierButtons" class="btn-group flex-wrap w-100" role="group"></div>
                                        </div>
                                    </div>
                                </div>
                                <div class="table-responsive mt-3">
                                    <table class="table table-sm align-middle table-hover">
                                        <thead>
                                            <tr>
                                                <th></th>
                                                <th>Modelo</th>
                                                <th>Fornecedor</th>
                                                <th>Tipo</th>
                                                <th></th>
                                            </tr>
                                        </thead>
                                        <tbody id="modelListBody"></tbody>
                                    </table>
                                </div>
                                <div class="d-flex justify-content-end align-items-center gap-2 mt-3">
                                    <label for="modelsListLimit" class="section-label mb-0">Por página</label>
                                    <select id="modelsListLimit" class="form-select form-select-sm w-auto">
                                        <option value="5">5</option>
                                        <option value="10">10</option>
                                        <option value="15">15</option>
                                        <option value="20">20</option>
                                        <option value="30">30</option>
                                        <option value="50">50</option>
                                    </select>
                                </div>
                                <?= pagination_component('settingsModels') ?>
                            </div>
                            <div class="carousel-item">
                                <form id="modelForm" class="row g-4 align-items-stretch mb-4">
                                    <div class="col-lg-5">
                                        <div id="modelPreview" class="showcase-preview border rounded bg-body-tertiary d-flex align-items-center justify-content-center p-4 h-100 position-relative" role="button" tabindex="0" title="Clique ou arraste para alterar a imagem">
                                            <input type="file" id="modelImage" accept="image/*" class="position-absolute top-0 start-0 w-100 h-100 opacity-0 cursor-pointer">
                                            <div id="modelPreviewContent" class="text-center text-secondary w-100">
                                                <i class="fa-solid fa-microchip fs-1 opacity-50"></i>
                                                <div class="small mt-2">Novo modelo</div>
                                            </div>
                                        </div>
                                    </div>
                                    <div class="col-lg-7">
                                        <div class="vstack gap-3 h-100">
                                            <div>
                                                <div class="form-label">Tipo de dispositivo</div>
                                                <div id="modelDeviceTypeButtons" class="device-type-grid is-wide" role="group"></div>
                                            </div>
                                            <div>
                                                <div class="form-label">Fornecedor</div>
                                                <div id="modelSupplierButtons" class="btn-group flex-wrap" role="group"></div>
                                            </div>
                                            <div class="row g-3">
                                                <div class="col-md-6">
                                                    <label for="modelInternalModel" class="form-label">Modelo interno</label>
                                                    <input type="text" class="form-control" id="modelInternalModel" placeholder="Identificador interno" required>
                                                </div>
                                                <div class="col-md-6">
                                                    <label for="modelCommercialName" class="form-label">Nome comercial</label>
                                                    <input type="text" class="form-control" id="modelCommercialName" placeholder="Nome visível" required>
                                                </div>
                                            </div>
                                            <div id="modelTemplateSummary" class="small text-secondary">A carregar template de capacidades do fornecedor.</div>
                                            <div class="d-flex justify-content-end gap-2 mt-auto">
                                                <button id="resetModelBtn" type="button" class="btn btn-outline-secondary">Cancelar</button>
                                                <button id="saveModelBtn" type="button" class="btn btn-primary"><?= icon('fa-floppy-disk', 'me-1') ?>Guardar</button>
                                            </div>
                                        </div>
                                    </div>
                                </form>
                            </div>
                            <div class="carousel-item">
                                <div class="d-flex align-items-center gap-2 mb-3">
                                    <button type="button" class="btn btn-sm btn-outline-secondary" data-action="backToModelList"><?= icon('fa-arrow-left', 'me-1') ?>Voltar</button>
                                </div>
                                <div class="row g-4 mb-4">
                                    <div class="col-lg-8" id="modelDetailFields">
                                        <div class="mb-3">
                                            <label for="modelDetailCommercialName" class="section-label d-block mb-1">Nome comercial</label>
                                            <input type="text" class="form-control fw-semibold" id="modelDetailCommercialName">
                                        </div>
                                        <div class="row g-3">
                                            <div class="col-md-4">
                                                <label for="modelDetailSupplierSelect" class="section-label d-block mb-1">Fornecedor</label>
                                                <select class="form-select" id="modelDetailSupplierSelect"></select>
                                            </div>
                                            <div class="col-md-4">
                                                <label for="modelDetailDeviceType" class="section-label d-block mb-1">Tipo</label>
                                                <select class="form-select" id="modelDetailDeviceType"></select>
                                            </div>
                                            <div class="col-md-4">
                                                <label for="modelDetailInternalModel" class="section-label d-block mb-1">Modelo interno</label>
                                                <input type="text" class="form-control" id="modelDetailInternalModel">
                                            </div>
                                        </div>
                                        <div class="d-flex align-items-center gap-2 mt-3">
                                            <span class="config-state config-state-secondary" id="modelDetailDirtyState"><span class="config-state-dot"></span>Sem alterações</span>
                                            <button type="button" class="btn btn-primary btn-sm d-none" id="modelDetailSaveBtn"><?= icon('fa-floppy-disk', 'me-1') ?>Guardar</button>
                                            <button type="button" class="btn btn-link btn-sm p-0 text-decoration-none d-none" id="modelDetailResetBtn">Descartar</button>
                                        </div>
                                    </div>
                                    <div class="col-lg-4">
                                        <div id="modelDetailImage" class="showcase-preview border rounded bg-body-tertiary d-flex align-items-center justify-content-center p-3 h-100">
                                            <div class="text-center text-secondary w-100">
                                                <i class="fa-solid fa-microchip fs-1 opacity-50"></i>
                                                <div class="small mt-2" id="modelDetailName">Modelo</div>
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
                                <div id="capabilitySectionNav" class="d-flex flex-wrap gap-1 mb-3" role="group" aria-label="Secções de capacidade"></div>
                                <div id="capabilityGroups"></div>
                                <div class="border-top mt-4 pt-3 d-flex align-items-center justify-content-between gap-3 flex-wrap">
                                    <div>
                                        <div class="fw-semibold">Apagar este modelo</div>
                                        <div class="small text-secondary" id="modelDetailDeleteHint">Os dispositivos que o usam ficam sem template de capacidades.</div>
                                    </div>
                                    <button type="button" class="btn btn-outline-secondary btn-sm row-action row-action-danger flex-shrink-0" id="modelDetailDeleteBtn"><?= icon('fa-trash', 'me-1') ?>Apagar modelo</button>
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
                    <div class="d-flex align-items-center gap-2">
                        <div class="input-group input-group-sm flex-grow-1">
                            <span class="input-group-text"><?= icon('fa-magnifying-glass') ?></span>
                            <input id="capabilityCatalogSearch" type="search" class="form-control" placeholder="Procurar capacidade ou chave">
                        </div>
                        <button class="btn btn-sm btn-outline-secondary d-inline-flex align-items-center gap-2 flex-shrink-0" type="button"
                            data-bs-toggle="collapse" data-bs-target="#capabilityFiltersCollapse" aria-controls="capabilityFiltersCollapse">
                            <?= icon('fa-sliders') ?>Filtros
                            <span id="capabilityFilterCount" class="badge rounded-pill text-bg-primary d-none"></span>
                        </button>
                    </div>
                    <div class="d-flex flex-wrap align-items-center gap-2 mt-2">
                        <div id="capabilityActiveFilters" class="d-flex flex-wrap gap-2"></div>
                        <button type="button" class="btn btn-link btn-sm p-0 text-decoration-none text-secondary small d-none" id="capabilitySupplierClear" data-action="clearCapabilitySupplier">Limpar</button>
                    </div>
                    <div class="collapse" id="capabilityFiltersCollapse">
                        <div class="row g-3 pt-3">
                            <div class="col-md-6">
                                <div class="section-label mb-1">Tipo de dispositivo</div>
                                <div id="capabilityDeviceTypeButtons" class="device-type-grid is-wide" role="group"></div>
                            </div>
                            <div class="col-md-6">
                                <div class="section-label mb-1">Fornecedor</div>
                                <div id="capabilitySupplierButtons" class="btn-group flex-wrap" role="group"></div>
                            </div>
                        </div>
                    </div>
                    <div class="border border-secondary-subtle rounded-3 p-3 my-3">
                        <div class="d-flex justify-content-between align-items-start gap-3 flex-wrap">
                            <div>
                                <div class="fw-semibold">Descoberta guiada</div>
                                <div id="discoveryModelSummary" class="small text-secondary" style="max-width:56ch">Selecione um modelo para gerar uma proposta a partir de um dispositivo online.</div>
                            </div>
                            <button type="button" class="btn btn-outline-secondary btn-sm row-action flex-shrink-0" id="discoveryRefreshDevicesBtn">Atualizar dispositivos</button>
                        </div>
                        <div class="d-flex align-items-end gap-2 flex-wrap mt-3">
                            <div style="min-width:240px;flex:1">
                                <label for="discoveryDeviceSelect" class="section-label d-block mb-1">Dispositivo online</label>
                                <select id="discoveryDeviceSelect" class="form-select form-select-sm">
                                    <option value="">Sem dispositivos disponíveis</option>
                                </select>
                            </div>
                            <button type="button" class="btn btn-primary btn-sm" id="discoveryGenerateBtn">Gerar proposta</button>
                            <button type="button" class="btn btn-outline-secondary btn-sm row-action" id="discoveryApplyBtn" disabled>Aplicar</button>
                        </div>
                        <div id="discoveryStatus" class="small text-secondary mt-3"></div>
                        <div id="discoveryEvidence" class="vstack gap-2 mt-3"></div>
                    </div>
                    <div id="capabilityCatalogEmpty" class="text-secondary p-4 text-center d-none">
                        <?= icon('fa-sliders', 'fs-1 opacity-25') ?>
                        <div class="mt-2">Sem capacidades generalizadas definidas para este tipo de dispositivo.</div>
                    </div>
                    <div id="capabilityCatalogSectionNav" class="capability-section-nav" role="group" aria-label="Secções do catálogo"></div>
                    <div id="capabilityCatalogViewer" class="vstack gap-3"></div>
                </div>
                <div class="tab-pane fade h-100" id="settingsCompanyPane" role="tabpanel" aria-labelledby="settingsCompanyTabBtn">
                    <div class="d-flex align-items-start justify-content-between gap-3 flex-wrap mb-3">
                        <div>
                            <div class="fw-semibold">Empresas</div>
                            <div class="small text-secondary" id="companiesTabSummary"></div>
                        </div>
                        <button type="button" class="btn btn-primary btn-sm flex-shrink-0" id="newCompanyBtn"
                            data-bs-toggle="collapse" data-bs-target="#companyFormCollapse" aria-controls="companyFormCollapse"><?= icon('fa-plus', 'me-1') ?>Nova empresa</button>
                    </div>
                    <div class="collapse mb-3" id="companyFormCollapse">
                    <form id="companyForm" class="row g-2 p-3 border rounded-3">
                        <input type="hidden" id="companyId">
                        <div class="col-md-6">
                            <input type="text" class="form-control form-control-sm" id="companyName" placeholder="Nome da empresa" required>
                        </div>
                        <div class="col-md-6 d-flex gap-2">
                            <button id="resetCompanyBtn" type="button" class="btn btn-outline-secondary btn-sm">Cancelar</button>
                            <button id="saveCompanyBtn" type="button" class="btn btn-primary btn-sm"><?= icon('fa-floppy-disk', 'me-1') ?>Guardar</button>
                        </div>
                    </form>
                    </div>
                    <div id="companyListBody" class="mb-4"></div>
                    <?= pagination_component('settingsCompany') ?>
                    <div class="collapse mb-3" id="licenseFormCollapse">
                    <form id="licenseForm" class="row g-2 p-3 border rounded-3">
                        <input type="hidden" id="licenseId">
                        <div class="col-md-4">
                            <select class="form-select form-select-sm" id="licenseCompanySelect">
                                <option value="">Selecionar empresa</option>
                            </select>
                        </div>
                        <div class="col-md-3">
                            <input type="text" class="form-control form-control-sm" id="licenseLicenseId" placeholder="ID da licença (ex: 1001)" required>
                        </div>
                        <div class="col-md-3">
                            <input type="text" class="form-control form-control-sm" id="licenseName" placeholder="Nome (ex: gucc.dev)">
                        </div>
                        <div class="col-md-2 d-flex gap-2">
                            <button id="resetLicenseBtn" type="button" class="btn btn-outline-secondary btn-sm">Cancelar</button>
                            <button id="saveLicenseBtn" type="button" class="btn btn-primary btn-sm"><?= icon('fa-floppy-disk', 'me-1') ?>Guardar</button>
                        </div>
                    </form>
                    </div>
                </div>
                <div class="tab-pane fade h-100" id="settingsApiUsersPane" role="tabpanel" aria-labelledby="settingsApiUsersTabBtn">
                    <div class="d-flex align-items-start justify-content-between gap-3 flex-wrap mb-3">
                        <div>
                            <div class="fw-semibold">Utilizadores API</div>
                            <div class="small text-secondary" id="apiUsersTabSummary"></div>
                        </div>
                        <button type="button" class="btn btn-primary btn-sm flex-shrink-0" id="newApiUserBtn"
                            data-bs-toggle="collapse" data-bs-target="#apiUserFormCollapse" aria-controls="apiUserFormCollapse"><?= icon('fa-plus', 'me-1') ?>Novo utilizador</button>
                    </div>
                    <div class="collapse mb-3" id="apiUserFormCollapse">
                    <form id="apiUserForm" class="row g-3 p-3 border rounded-3">
                        <input type="hidden" id="apiUserId">
                        <div class="col-md-4">
                            <label for="apiUsername" class="form-label">Utilizador</label>
                            <input type="text" class="form-control form-control-sm" id="apiUsername" autocomplete="off" required>
                        </div>
                        <div class="col-md-4">
                            <label for="apiUserPassword" class="form-label">Password</label>
                            <input type="password" class="form-control form-control-sm" id="apiUserPassword" autocomplete="new-password">
                        </div>
                        <div class="col-md-4">
                            <label for="apiUserRole" class="form-label">Perfil</label>
                            <select class="form-select form-select-sm" id="apiUserRole">
                                <option value="license_client">Cliente por licença</option>
                                <option value="hub_admin">Admin Hub</option>
                            </select>
                        </div>
                        <div class="col-md-5">
                            <label for="apiUserLicenseRefId" class="form-label">Empresa / licença</label>
                            <select class="form-select form-select-sm" id="apiUserLicenseRefId">
                                <option value="">Selecionar licença</option>
                            </select>
                        </div>
                        <div class="col-md-3 d-flex align-items-end">
                            <div class="form-check form-switch mb-2">
                                <input class="form-check-input" type="checkbox" role="switch" id="apiUserEnabled" checked>
                                <label class="form-check-label" for="apiUserEnabled">Ativo</label>
                            </div>
                        </div>
                        <div class="col-md-4 d-flex align-items-end gap-2">
                            <button id="resetApiUserBtn" type="button" class="btn btn-outline-secondary btn-sm flex-fill">Cancelar</button>
                            <button id="saveApiUserBtn" type="button" class="btn btn-primary btn-sm flex-fill"><?= icon('fa-floppy-disk', 'me-1') ?>Guardar</button>
                        </div>
                    </form>
                    </div>
                    <div class="table-responsive">
                        <table class="table table-sm align-middle">
                            <thead>
                                <tr>
                                    <th>Utilizador</th>
                                    <th>Perfil</th>
                                    <th>Âmbito</th>
                                    <th>Estado</th>
                                    <th></th>
                                </tr>
                            </thead>
                            <tbody id="apiUserListBody"></tbody>
                        </table>
                    </div>
                    <?= pagination_component('settingsApiUsers') ?>
                </div>
            </div>
        </div>
    </div>
</div>
<?php
$body = (string) ob_get_clean();

$footer = '<button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Fechar</button>';

render_modal('settingsModal', 'Definições', $body, $footer, 'modal-fullscreen');
