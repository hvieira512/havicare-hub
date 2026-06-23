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
                <button class="nav-link text-start" id="settingsApiUsersTabBtn" data-bs-toggle="pill" data-bs-target="#settingsApiUsersPane" type="button" role="tab" aria-controls="settingsApiUsersPane" aria-selected="false">Utilizadores API</button>
            </div>
        </div>
        <div class="col-lg-9">
            <div class="tab-content">
                <div class="tab-pane fade show active" id="settingsSuppliersPane" role="tabpanel" aria-labelledby="settingsSuppliersTabBtn">
                    <form id="supplierForm" class="row g-2 mb-3 p-3 border rounded bg-body-tertiary">
                        <div class="col-md-8">
                            <input type="text" class="form-control form-control-sm" id="supplierName" placeholder="Nome do fornecedor" required>
                        </div>
                        <div class="col-md-4">
                            <button id="saveSupplierBtn" type="button" class="btn btn-primary btn-sm w-100"><?= icon('fa-plus', 'me-1') ?>Adicionar</button>
                        </div>
                    </form>
                    <div class="table-responsive">
                        <table class="table table-sm align-middle">
                            <thead><tr><th>Nome</th><th>Modelos</th><th>Estado</th><th></th></tr></thead>
                            <tbody id="supplierListBody"></tbody>
                        </table>
                    </div>
                </div>
                <div class="tab-pane fade" id="settingsModelsPane" role="tabpanel" aria-labelledby="settingsModelsTabBtn">
                    <form id="modelForm" class="row g-4 align-items-stretch mb-4">
                        <div class="col-lg-5">
                            <?= showcase_preview('modelPreview') ?>
                        </div>
                        <div class="col-lg-7">
                            <div class="vstack gap-3 h-100">
                                <div>
                                    <div class="form-label">Fornecedor</div>
                                    <div id="modelSupplierButtons" class="btn-group flex-wrap" role="group"></div>
                                </div>
                                <div>
                                    <label for="modelModel" class="form-label">Modelo</label>
                                    <input type="text" class="form-control" id="modelModel" placeholder="Modelo" required>
                                </div>
                                <div>
                                    <label for="modelImage" class="form-label">Imagem</label>
                                    <input type="file" class="form-control" id="modelImage" accept="image/*">
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
                            <thead><tr><th>Imagem</th><th>Fornecedor</th><th>Modelo</th><th></th></tr></thead>
                            <tbody id="modelListBody"></tbody>
                        </table>
                    </div>
                </div>
                <div class="tab-pane fade" id="settingsCapabilitiesPane" role="tabpanel" aria-labelledby="settingsCapabilitiesTabBtn">
                    <div class="vstack gap-4">
                        <div class="border rounded bg-body-tertiary p-3">
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
                            <div class="mt-2">Selecione um fornecedor e um modelo para editar as capacidades.</div>
                        </div>
                        <div id="capabilityEditor" class="d-none">
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
                </div>
            </div>
        </div>
    </div>
</div>
<?php
$body = (string) ob_get_clean();

$footer = '<button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Fechar</button>';

render_modal('settingsModal', 'Definições', $body, $footer, 'modal-fullscreen');
