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
                <div class="card shadow-sm">
                    <div class="card-header">
                        <div class="d-flex justify-content-between align-items-center gap-2 flex-wrap">
                            <span><?= icon('fa-microchip', 'me-2') ?>Dispositivos</span>
                            <div class="d-flex align-items-center gap-2">
                                <button id="toggleDeviceFiltersBtn" class="btn btn-sm btn-outline-secondary" type="button"><?= icon('fa-filter', 'me-1') ?>Filtros</button>
                                <button id="addDeviceBtn" class="btn btn-sm btn-outline-primary"><?= icon('fa-plus', 'me-1') ?>Adicionar</button>
                            </div>
                        </div>
                        <div id="deviceFiltersPanel" class="border-top mt-3 pt-3 d-none">
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
                            <div class="d-flex justify-content-end gap-2 mt-3">
                                <button id="clearDeviceFiltersBtn" class="btn btn-sm btn-outline-secondary" type="button">Limpar</button>
                                <button id="applyDeviceFiltersBtn" class="btn btn-sm btn-primary" type="button">Aplicar filtros</button>
                            </div>
                        </div>
                    </div>
                    <div class="card-body">
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
                </div>
                <div id="requestColumn" class="d-none mt-3">
                    <div class="card shadow-sm">
                        <div class="card-header d-flex justify-content-between align-items-center">
                            <span><?= icon('fa-paper-plane', 'me-2') ?>Pedir dados ao dispositivo</span>
                            <span id="requestCardCount" class="small text-secondary"></span>
                        </div>
                        <div class="card-body">
                            <div class="row g-3" id="requestGrid"></div>
                        </div>
                    </div>
                </div>
            </aside>
            <section id="detailColumn" class="col-12 col-lg-8">
                <div class="card shadow-sm">
                    <div class="card-body">
                        <div id="emptyState" class="text-center text-secondary py-5">
                            <?= icon('fa-tablet-screen-button', 'fs-1 opacity-25') ?>
                            <h1 class="h5 mt-3">Selecione um dispositivo</h1>
                        </div>
                        <div id="deviceDetail" class="d-none">
                            <div class="d-flex justify-content-between align-items-start mb-3">
                                <div>
                                    <h1 class="h4 mb-1" id="detailTitle"></h1>
                                    <div class="text-secondary" id="detailMeta"></div>
                                </div>
                                <span id="detailBadge" class="badge"></span>
                            </div>
                            <div class="vstack gap-4">
                                <section>
                                    <?= section_header('Eventos recebidos', 'telemetryCount') ?>
                                    <div id="telemetryList"></div>
                                    <div id="telemetryPager" class="d-flex justify-content-between align-items-center gap-2 mt-2 d-none"></div>
                                </section>
                                <section>
                                    <?= section_header('Pedidos ao dispositivo') ?>
                                    <div id="downlinkRequests"></div>
                                </section>
                                <section>
                                    <?= section_header('Ligações ao servidor') ?>
                                    <div id="connectionLogs"></div>
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

    <script>
        window.dashboardConfigurationCatalog = <?= json_encode([
                                                    'wonlex-json' => DeviceConfigurationCatalog::configsForProtocol('wonlex-json'),
                                                    'vivistar-iw' => DeviceConfigurationCatalog::configsForProtocol('vivistar-iw'),
                                                    'four-p-touch' => DeviceConfigurationCatalog::configsForProtocol('four-p-touch'),
                                                ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>;
    </script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    <script type="module" src="main.js"></script>
</body>

</html>
