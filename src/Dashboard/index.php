<?php

declare(strict_types=1);

use Hub\Command\DeviceConfigurationCatalog;

require_once __DIR__ . '/components/helpers.php';
require_once __DIR__ . '/components/pagination.php';
require_once __DIR__ . '/components/modal.php';

?>
<!doctype html>
<html lang="pt-PT">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Hitecosystem Hub de Dispositivos</title>
    <link rel="apple-touch-icon" sizes="180x180" href="/assets/logo.svg">
    <link rel="icon" type="image/svg+xml" sizes="32x32" href="/assets/logo.svg">
    <link rel="icon" type="image/svg+xml" sizes="16x16" href="/assets/logo.svg">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css" rel="stylesheet">
    <style>
        .showcase-preview {
            min-height: 280px;
        }

        .showcase-preview img {
            max-width: 100%;
            max-height: 260px;
        }

        .device-modal-shell {
            padding: 1rem;
        }

        .selected-device-preview {
            width: 72px;
            height: 72px;
            display: flex;
            align-items: center;
            justify-content: center;
            border-radius: 1rem;
            background: var(--bs-tertiary-bg);
            flex-shrink: 0;
        }

        .selected-device-preview img {
            max-width: 56px;
            max-height: 56px;
        }

        .selected-device-facts dt {
            color: var(--bs-secondary-color);
            font-size: .8rem;
            font-weight: 600;
            margin-bottom: .25rem;
            text-transform: uppercase;
            letter-spacing: .04em;
        }

        .selected-device-facts dd {
            margin-bottom: 0;
            font-size: .95rem;
        }

        #deviceModal .modal-dialog.modal-fullscreen {
            margin: 1rem;
            width: calc(100vw - 2rem);
            max-width: none;
            height: calc(100% - 2rem);
        }

        #deviceModal .modal-dialog.modal-fullscreen .modal-content {
            height: 100%;
        }

        @media (max-width: 575.98px) {
            #deviceModal .modal-dialog.modal-fullscreen {
                margin: 0;
                width: 100vw;
                height: 100%;
            }

            .device-modal-shell {
                padding: 0;
            }

            .device-modal-shell .nav-pills {
                width: 100%;
            }
        }
    </style>
</head>

<body class="bg-body-tertiary">
    <nav class="navbar navbar-expand-lg bg-dark navbar-dark">
        <div class="container-fluid">
            <span class="navbar-brand"><?= icon('fa-satellite-dish', 'me-2') ?>hitHub</span>
            <div class="d-flex align-items-center gap-2">
                <button id="manageSuppliersBtn" class="btn btn-sm btn-outline-light"><?= icon('fa-building', 'me-1') ?>Fornecedores</button>
                <button id="manageModelsBtn" class="btn btn-sm btn-outline-light"><?= icon('fa-cubes', 'me-1') ?>Modelos</button>
            </div>
        </div>
    </nav>
    <main class="container-fluid py-3">
        <div class="row g-3">
            <aside id="deviceColumn" class="col-12 col-lg-4">
                <div class="card shadow-sm h-100">
                    <div class="card-header">
                        <div class="d-flex justify-content-between align-items-center gap-2 flex-wrap">
                            <span><?= icon('fa-microchip', 'me-2') ?>Dispositivo selecionado</span>
                            <div class="d-flex align-items-center gap-2">
                                <button id="openDeviceSelectorBtn" class="btn btn-sm btn-primary" type="button"><?= icon('fa-list', 'me-1') ?>Escolher</button>
                                <button id="addDeviceBtn" class="btn btn-sm btn-outline-primary"><?= icon('fa-plus', 'me-1') ?>Adicionar</button>
                            </div>
                        </div>
                    </div>
                    <div class="card-body">
                        <div id="deviceSelectionEmptyState" class="text-center text-secondary py-5">
                            <?= icon('fa-tablet-screen-button', 'fs-1 opacity-25') ?>
                            <h1 class="h5 mt-3">Selecione um dispositivo</h1>
                            <p class="small mb-3">Escolha um dispositivo para ver o resumo operacional, pedir dados e analisar a atividade recente.</p>
                            <button id="emptyStateSelectDeviceBtn" class="btn btn-primary" type="button"><?= icon('fa-list', 'me-1') ?>Escolher dispositivo</button>
                        </div>
                        <div id="selectedDevicePanel" class="d-none">
                            <div class="d-flex align-items-start gap-3 mb-4">
                                <div id="selectedDevicePreview" class="selected-device-preview"></div>
                                <div class="min-width-0 flex-grow-1">
                                    <div class="d-flex align-items-center gap-2 flex-wrap mb-2">
                                        <h1 class="h4 mb-0 text-break" id="selectedDeviceTitle"></h1>
                                        <span id="selectedDeviceBadge" class="badge"></span>
                                        <button id="selectedDeviceEditBtn" class="btn btn-sm btn-outline-secondary" type="button"><?= icon('fa-pen', 'me-1') ?>Editar</button>
                                    </div>
                                    <div id="selectedDeviceMeta" class="text-secondary small"></div>
                                </div>
                            </div>
                            <dl id="selectedDeviceFacts" class="selected-device-facts row g-3 mb-0"></dl>
                            <div class="border-top pt-4 mt-4">
                                <div class="d-flex justify-content-between align-items-center mb-3">
                                    <span class="fw-semibold"><?= icon('fa-paper-plane', 'me-2') ?>Pedir dados ao dispositivo</span>
                                    <span id="requestCardCount" class="small text-secondary"></span>
                                </div>
                                <div class="row g-3" id="requestGrid"></div>
                            </div>
                        </div>
                    </div>
                </div>
            </aside>
            <section id="detailColumn" class="col-12 col-lg-8">
                <div class="card shadow-sm">
                    <div class="card-body">
                        <div id="detailEmptyState" class="text-center text-secondary py-5">
                            <?= icon('fa-tablet-screen-button', 'fs-1 opacity-25') ?>
                            <h1 class="h5 mt-3">Selecione um dispositivo</h1>
                        </div>
                        <div id="deviceDetail" class="d-none">
                            <div class="d-flex justify-content-between align-items-start mb-3">
                                <div>
                                    <h1 class="h4 mb-1" id="detailTitle"></h1>
                                    <div class="text-secondary" id="detailMeta"></div>
                                </div>
                                <div class="d-flex align-items-center gap-2 flex-shrink-0">
                                    <button id="toggleDetailFiltersBtn" class="btn btn-sm btn-outline-secondary" type="button" title="Filtrar"><?= icon('fa-filter') ?></button>
                                    <span id="detailBadge" class="badge"></span>
                                </div>
                            </div>
                            <div id="detailFiltersPanel" class="border rounded bg-body-tertiary p-2 mb-3 d-none">
                                <div class="row g-2 align-items-end">
                                    <div class="col-auto">
                                        <label for="detailFilterFrom" class="form-label form-label-sm small text-secondary mb-1">De</label>
                                        <input type="datetime-local" id="detailFilterFrom" class="form-control form-control-sm">
                                    </div>
                                    <div class="col-auto">
                                        <label for="detailFilterTo" class="form-label form-label-sm small text-secondary mb-1">Até</label>
                                        <input type="datetime-local" id="detailFilterTo" class="form-control form-control-sm">
                                    </div>
                                    <div class="col-auto">
                                        <label for="detailFilterType" class="form-label form-label-sm small text-secondary mb-1">Tipo</label>
                                        <select id="detailFilterType" class="form-select form-select-sm">
                                            <option value="all">Todos</option>
                                        </select>
                                    </div>
                                    <div class="col-auto d-flex gap-1">
                                        <button id="applyDetailFiltersBtn" class="btn btn-sm btn-primary"><?= icon('fa-check') ?></button>
                                        <button id="clearDetailFiltersBtn" class="btn btn-sm btn-outline-secondary"><?= icon('fa-xmark') ?></button>
                                    </div>
                                </div>
                            </div>
                            <div class="vstack gap-4">
                                <div class="row g-3">
                                    <div class="col-12 col-lg-6 d-flex flex-column">
                                        <section class="flex-grow-1 d-flex flex-column">
                                            <?= section_header('Eventos recebidos', 'telemetryCount') ?>
                                            <div id="telemetryList" class="overflow-auto" style="max-height:50vh;"></div>
                                            <?= pagination_component('telemetry') ?>
                                        </section>
                                    </div>
                                    <div class="col-12 col-lg-6 d-flex flex-column">
                                        <section class="flex-grow-1 d-flex flex-column">
                                            <?= section_header('Pedidos ao dispositivo') ?>
                                            <div id="downlinkRequests" class="overflow-auto" style="max-height:50vh;"></div>
                                        </section>
                                    </div>
                                </div>
                                <section>
                                    <?= section_header('Ligações ao servidor') ?>
                                    <div id="connectionTimeline" style="height:180px;width:100%;"></div>
                                    <div id="connectionStats" class="small text-secondary mt-1"></div>
                                </section>
                            </div>
                        </div>
                    </div>
                </div>
            </section>
        </div>
    </main>

    <?php require __DIR__ . '/components/modals/device.php'; ?>
    <?php require __DIR__ . '/components/modals/supplier.php'; ?>
    <?php require __DIR__ . '/components/modals/model.php'; ?>
    <div class="modal fade" id="deviceSelectorModal" tabindex="-1" aria-labelledby="deviceSelectorModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-xl modal-dialog-centered modal-dialog-scrollable">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="deviceSelectorModalLabel">Selecionar dispositivo</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Fechar"></button>
                </div>
                <div class="modal-body">
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
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-primary" id="openAddDeviceFromSelectorBtn"><?= icon('fa-plus', 'me-1') ?>Adicionar dispositivo</button>
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Fechar</button>
                </div>
            </div>
        </div>
    </div>

    <script>
        window.dashboardConfigurationCatalog = <?= json_encode([
                                                    'wonlex-json' => DeviceConfigurationCatalog::configsForProtocol('wonlex-json'),
                                                    'vivistar-iw' => DeviceConfigurationCatalog::configsForProtocol('vivistar-iw'),
                                                    'four-p-touch' => DeviceConfigurationCatalog::configsForProtocol('four-p-touch'),
                                                ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>;
    </script>
    <script src="https://cdn.amcharts.com/lib/5/index.js"></script>
    <script src="https://cdn.amcharts.com/lib/5/xy.js"></script>
    <script src="https://cdn.amcharts.com/lib/5/themes/Animated.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    <script type="module" src="main.js"></script>
</body>

</html>
