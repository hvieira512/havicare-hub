<?php

declare(strict_types=1);

ob_start();
?>
<div class="settings-modal-shell">
    <div class="row g-4">
        <div class="col-lg-3 d-flex align-items-center justify-content-center justify-content-lg-center">
            <div class="nav nav-pills flex-row flex-lg-column justify-content-center justify-content-lg-start gap-2 w-100" id="settingsModalNav" role="tablist">
                <button class="nav-link active text-start" id="settingsSuppliersTabBtn" data-bs-toggle="pill" data-bs-target="#settingsSuppliersPane" type="button" role="tab" aria-controls="settingsSuppliersPane" aria-selected="true">Fornecedores</button>
                <button class="nav-link text-start" id="settingsModelsTabBtn" data-bs-toggle="pill" data-bs-target="#settingsModelsPane" type="button" role="tab" aria-controls="settingsModelsPane" aria-selected="false">Modelos</button>
                <button class="nav-link text-start" id="settingsCapabilitiesTabBtn" data-bs-toggle="pill" data-bs-target="#settingsCapabilitiesPane" type="button" role="tab" aria-controls="settingsCapabilitiesPane" aria-selected="false">Capacidades</button>
                <button class="nav-link text-start" id="settingsCompanyTabBtn" data-bs-toggle="pill" data-bs-target="#settingsCompanyPane" type="button" role="tab" aria-controls="settingsCompanyPane" aria-selected="false">Empresas</button>
                <button class="nav-link text-start" id="settingsApiUsersTabBtn" data-bs-toggle="pill" data-bs-target="#settingsApiUsersPane" type="button" role="tab" aria-controls="settingsApiUsersPane" aria-selected="false">Utilizadores API</button>
            </div>
        </div>
        <div class="col-lg-9">
            <div class="tab-content">
                <div class="tab-pane fade show active" id="settingsSuppliersPane" role="tabpanel" aria-labelledby="settingsSuppliersTabBtn">
                    <div class="small text-secondary mb-3 p-3 border rounded bg-body-tertiary">Os fornecedores são definidos em código. Não é possível adicionar ou remover fornecedores através do painel.</div>
                    <div class="table-responsive">
                        <table class="table table-sm align-middle">
                            <thead><tr><th>Nome</th><th>Modelos</th><th>Estado</th><th></th></tr></thead>
                            <tbody id="supplierListBody"></tbody>
                        </table>
                    </div>
                    <?= pagination_component('settingsSuppliers') ?>
                </div>
                <div class="tab-pane fade" id="settingsModelsPane" role="tabpanel" aria-labelledby="settingsModelsTabBtn">
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
                                    <div id="modelDeviceTypeButtons" class="btn-group flex-wrap" role="group"></div>
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
                                <div class="d-flex justify-content-end gap-2 mt-auto">
                                    <button id="resetModelBtn" type="button" class="btn btn-outline-secondary">Cancelar</button>
                                    <button id="saveModelBtn" type="button" class="btn btn-primary"><?= icon('fa-floppy-disk', 'me-1') ?>Guardar</button>
                                </div>
                            </div>
                        </div>
                    </form>
                    <div class="table-responsive">
                        <table class="table table-sm align-middle">
                            <thead><tr><th>Imagem</th><th>Fornecedor</th><th>Nome comercial</th><th>Modelo interno</th><th>Tipo</th><th></th></tr></thead>
                            <tbody id="modelListBody"></tbody>
                        </table>
                    </div>
                    <?= pagination_component('settingsModels') ?>
                </div>
                <div class="tab-pane fade" id="settingsCapabilitiesPane" role="tabpanel" aria-labelledby="settingsCapabilitiesTabBtn">
                    <div class="vstack gap-4">
                        <div class="border rounded bg-body-tertiary p-3">
                            <div class="mb-3">
                                <div class="form-label">Tipo de dispositivo</div>
                                <div id="capabilityDeviceTypeButtons" class="btn-group flex-wrap" role="group"></div>
                            </div>
                            <div class="mb-3">
                                <div class="form-label">Fornecedor</div>
                                <div id="capabilitySupplierButtons" class="btn-group flex-wrap" role="group"></div>
                            </div>
                            <div>
                                <div class="form-label">Modelo</div>
                                <div id="capabilityModelButtons" class="btn-group flex-wrap" role="group"></div>
                            </div>
                        </div>
                        <div id="capabilitySelectionEmpty" class="text-secondary border rounded bg-body-tertiary p-4 text-center">
                            <?= icon('fa-sliders', 'fs-1 opacity-25') ?>
                            <div class="mt-2">Selecione o tipo, fornecedor e modelo para editar as capacidades.</div>
                        </div>
                        <div id="capabilityEditor" class="d-none">
                            <div class="row g-3">
                                <div class="col-lg-4">
                                    <div id="capabilityModelPreview" class="showcase-preview border rounded bg-body-tertiary d-flex align-items-center justify-content-center p-3 h-100">
                                        <div class="text-center text-secondary w-100">
                                            <i class="fa-solid fa-microchip fs-1 opacity-50"></i>
                                            <div class="small mt-2" id="capabilityModelName">Modelo</div>
                                        </div>
                                    </div>
                                </div>
                                <div class="col-lg-8">
                                    <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3">
                                        <div>
                                            <h2 class="h5 mb-1" id="capabilityTitle">Capacidades</h2>
                                            <div class="small text-secondary" id="capabilitySubtitle"></div>
                                        </div>
                                        <div class="d-flex align-items-center gap-2">
                                            <span id="capabilitySummary" class="small text-secondary"></span>
                                            <button id="saveCapabilitiesBtn" type="button" class="btn btn-primary btn-sm"><?= icon('fa-floppy-disk', 'me-1') ?>Guardar capacidades</button>
                                        </div>
                                    </div>
                                    <div id="capabilityGroups" class="vstack gap-3"></div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="tab-pane fade" id="settingsCompanyPane" role="tabpanel" aria-labelledby="settingsCompanyTabBtn">
                    <form id="companyForm" class="row g-2 mb-3 p-3 border rounded bg-body-tertiary">
                        <input type="hidden" id="companyId">
                        <div class="col-md-6">
                            <input type="text" class="form-control form-control-sm" id="companyName" placeholder="Nome da empresa" required>
                        </div>
                        <div class="col-md-6 d-flex gap-2">
                            <button id="resetCompanyBtn" type="button" class="btn btn-outline-secondary btn-sm">Cancelar</button>
                            <button id="saveCompanyBtn" type="button" class="btn btn-primary btn-sm"><?= icon('fa-floppy-disk', 'me-1') ?>Guardar</button>
                        </div>
                    </form>
                    <div class="table-responsive mb-4">
                        <table class="table table-sm align-middle">
                            <thead><tr><th>Nome</th><th>Licenças</th><th></th></tr></thead>
                            <tbody id="companyListBody"></tbody>
                        </table>
                    </div>
                    <?= pagination_component('settingsCompany') ?>
                    <h3 class="h6 mb-3">Licenças</h3>
                    <form id="licenseForm" class="row g-2 mb-3 p-3 border rounded bg-body-tertiary">
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
                    <div class="table-responsive">
                        <table class="table table-sm align-middle">
                            <thead><tr><th>Empresa</th><th>ID</th><th>Nome</th><th></th></tr></thead>
                            <tbody id="licenseListBody"></tbody>
                        </table>
                    </div>
                    <?= pagination_component('settingsLicenses') ?>
                </div>
                <div class="tab-pane fade" id="settingsApiUsersPane" role="tabpanel" aria-labelledby="settingsApiUsersTabBtn">
                    <form id="apiUserForm" class="row g-3 mb-4 p-3 border rounded bg-body-tertiary">
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
                            <label for="apiUserLicenseId" class="form-label">Licença</label>
                            <input type="text" class="form-control form-control-sm" id="apiUserLicenseId" placeholder="1001">
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
                    <div class="table-responsive">
                        <table class="table table-sm align-middle">
                            <thead><tr><th>Utilizador</th><th>Perfil</th><th>Licença</th><th>Estado</th><th></th></tr></thead>
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
