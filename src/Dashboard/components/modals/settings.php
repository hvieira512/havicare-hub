<?php

declare(strict_types=1);

ob_start();
?>
<?php /* O padding e a goteira andam a par: a `row` do Bootstrap tem margens negativas de
       * metade da goteira e conta com o padding do pai para as absorver. `p-2` com `g-3`
       * da 8px contra 8px, `p-lg-3` com `g-lg-4` da 16 contra 12. Ter padding zero em
       * telefone, como estava no CSS, deixava a linha 24px mais larga que o modal. */ ?>
<div class="settings-modal-shell d-flex flex-column h-100 p-2 p-lg-3">
    <div class="row g-3 g-lg-4 h-100 align-items-lg-center">
        <div class="col-12 col-lg-2 d-flex align-items-lg-center h-100">
            <div class="nav nav-pills settings-modal-nav flex-row flex-lg-column flex-nowrap justify-content-lg-start gap-2 w-100" id="settingsModalNav" role="tablist">
                <?php /* Os identificadores mantem o nome `Models`: a seccao e a dos modelos, e as
                       * chaves atravessam o `dom.js`, o `bootstrap.js` e o estado. */ ?>
                <button class="nav-link active text-start d-flex align-items-center justify-content-between gap-2" id="settingsModelsTabBtn" data-bs-toggle="pill" data-bs-target="#settingsModelsPane" type="button" role="tab" aria-controls="settingsModelsPane" aria-selected="true">Catálogo<span class="settings-nav-count d-none" id="settingsModelsCount"></span></button>
                <button class="nav-link text-start" id="settingsCapabilitiesTabBtn" data-bs-toggle="pill" data-bs-target="#settingsCapabilitiesPane" type="button" role="tab" aria-controls="settingsCapabilitiesPane" aria-selected="false">Capacidades</button>
                <button class="nav-link text-start d-flex align-items-center justify-content-between gap-2" id="settingsCompanyTabBtn" data-bs-toggle="pill" data-bs-target="#settingsCompanyPane" type="button" role="tab" aria-controls="settingsCompanyPane" aria-selected="false">Licenças<span class="settings-nav-count d-none" id="settingsCompanyCount"></span></button>
                <button class="nav-link text-start d-flex align-items-center justify-content-between gap-2" id="settingsApiUsersTabBtn" data-bs-toggle="pill" data-bs-target="#settingsApiUsersPane" type="button" role="tab" aria-controls="settingsApiUsersPane" aria-selected="false">Utilizadores API<span class="settings-nav-count d-none" id="settingsApiUsersCount"></span></button>
            </div>
        </div>
        <div class="col-12 col-lg-10 d-flex flex-column h-100">
            <div class="tab-content flex-grow-1">
                <div class="tab-pane fade show active h-100" id="settingsModelsPane" role="tabpanel" aria-labelledby="settingsModelsTabBtn">
                    <?php /* So aparece dentro do formulario e da ficha. No "Novo modelo" e a unica
                           * saida: o botao ao lado e um "Cancelar" que limpa sem navegar. */ ?>
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
                                <?php /* O tipo e o fornecedor SÃO a árvore, por isso não há filtros por cima
                                       * dela; e a árvore vem inteira numa chamada, por isso não há paginação
                                       * -- um grupo cortado entre páginas é a pior das duas leituras. */ ?>
                                <div id="modelCatalog" class="mt-3"></div>
                            </div>
                            <div class="carousel-item">
                                <form id="modelForm" class="row g-4 align-items-stretch mb-4">
                                    <div class="col-lg-5">
                                        <div id="modelPreview" class="showcase-preview border rounded d-flex align-items-center justify-content-center p-4 h-100 position-relative" role="button" tabindex="0" title="Clique ou arraste para alterar a imagem">
                                            <input type="file" id="modelImage" accept="image/*" class="position-absolute top-0 start-0 w-100 h-100 opacity-0 cursor-pointer">
                                            <div id="modelPreviewContent" class="text-center text-secondary w-100">
                                                <?= icon('fa-microchip', 'fs-1 opacity-50') ?>
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
                                            <?php /* As mesmas classes que o `components/state-badge.js` monta: esta pastilha
                                                   * nunca é redesenhada, só se esconde, e por isso vive aqui à mão. */ ?>
                                            <span class="state-badge badge rounded-pill d-inline-flex align-items-center gap-1 text-uppercase fw-semibold lh-sm px-2 bg-secondary-subtle text-body-secondary" id="modelDetailDirtyState"><span class="state-badge-dot rounded-circle d-inline-block"></span>Sem alterações</span>
                                            <button type="button" class="btn btn-primary btn-sm d-none" id="modelDetailSaveBtn"><?= icon('fa-floppy-disk', 'me-1') ?>Guardar</button>
                                            <button type="button" class="btn btn-link btn-sm p-0 text-decoration-none d-none" id="modelDetailResetBtn">Descartar</button>
                                        </div>
                                    </div>
                                    <div class="col-lg-4">
                                        <div id="modelDetailImage" class="showcase-preview border rounded d-flex align-items-center justify-content-center p-3 h-100">
                                            <div class="text-center text-secondary w-100">
                                                <?= icon('fa-microchip', 'fs-1 opacity-50') ?>
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
                    <div class="row g-3 py-3">
                        <div class="col-md-8">
                            <div class="section-label mb-1">Tipo de dispositivo</div>
                            <div id="capabilityDeviceTypeButtons" class="device-type-grid is-wide" role="group"></div>
                        </div>
                        <div class="col-md-4">
                            <div class="section-label mb-1">Fornecedor</div>
                            <div id="capabilitySupplierButtons" class="btn-group flex-wrap" role="group"></div>
                        </div>
                    </div>
                    <div id="capabilityCatalogEmpty" class="text-secondary p-4 text-center d-none">
                        <?= icon('fa-sliders', 'fs-1 opacity-25') ?>
                        <div class="mt-2">Sem capacidades generalizadas definidas para este tipo de dispositivo.</div>
                    </div>
                    <div id="capabilityCatalogSectionNav" class="capability-section-nav" role="group" aria-label="Secções do catálogo"></div>
                    <div id="capabilityCatalogViewer" class="vstack gap-3"></div>
                </div>
                <div class="tab-pane fade h-100" id="settingsCompanyPane" role="tabpanel" aria-labelledby="settingsCompanyTabBtn">
                    <?php /* Sem formulários à parte: uma empresa ou uma licença abre-se na própria
                           * linha, e uma licença nova nasce dentro da empresa em que se carregou
                           * no `+`. Ver `settings/companies.js`. */ ?>
                    <?= tab_pane_header(
                        'Licenças',
                        'companiesTabSummary',
                        '<button type="button" class="btn btn-primary btn-sm flex-shrink-0" id="newCompanyBtn"'
                        . ' data-action="newCompany">'
                        . icon('fa-plus', 'me-1') . 'Nova empresa</button>'
                    ) ?>
                    <div id="companyListBody" class="mb-4"></div>
                    <?= pagination_component('settingsCompanyPagination') ?>
                </div>
                <div class="tab-pane fade h-100" id="settingsApiUsersPane" role="tabpanel" aria-labelledby="settingsApiUsersTabBtn">
                    <?php /* Sem formulário à parte: criar abre um rascunho no topo da lista e editar
                           * transforma a linha que se tocou. Ver `settings/api-users.js`. */ ?>
                    <?= tab_pane_header(
                        'Utilizadores API',
                        'apiUsersTabSummary',
                        '<button type="button" class="btn btn-primary btn-sm flex-shrink-0" id="newApiUserBtn"'
                        . ' data-action="newApiUser">'
                        . icon('fa-plus', 'me-1') . 'Novo utilizador</button>'
                    ) ?>
                    <?php /* O invólucro apanha os cliques dos dois: o formulário de criar e as
                           * acções que a grelha desenha em cada linha. */ ?>
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

$footer = '<button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Fechar</button>';

render_modal('settingsModal', 'Definições', $body, $footer, 'modal-fullscreen');
