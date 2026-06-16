<?php

declare(strict_types=1);

use Hub\Command\DeviceConfigurationCatalog;

require_once __DIR__ . '/components.php';

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
        </style>
    </head>
    <body class="bg-body-tertiary">
        <nav class="navbar navbar-expand-lg bg-dark navbar-dark">
            <div class="container-fluid">
                <span class="navbar-brand"><?= icon('fa-satellite-dish', 'me-2') ?>hitHub</span>
                <div class="d-flex align-items-center gap-2">
                    <span id="hubCounts" class="navbar-text small"></span>
                    <button id="manageSuppliersBtn" class="btn btn-sm btn-outline-light"><?= icon('fa-building', 'me-1') ?>Fornecedores</button>
                    <button id="manageModelsBtn" class="btn btn-sm btn-outline-light"><?= icon('fa-cubes', 'me-1') ?>Modelos</button>
                </div>
            </div>
        </nav>
        <main class="container-fluid py-3">
            <div class="row g-3">
                <aside id="deviceColumn" class="col-12 col-lg-4">
                    <div class="card shadow-sm">
                        <div class="card-header d-flex justify-content-between align-items-center">
                            <span><?= icon('fa-watch-fitness', 'me-2') ?>Dispositivos</span>
                            <button id="addDeviceBtn" class="btn btn-sm btn-outline-primary"><?= icon('fa-plus', 'me-1') ?>Adicionar</button>
                        </div>
                        <div id="deviceList"></div>
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
                                        <?= section_header('Pedir dados ao dispositivo', 'requestCardCount') ?>
                                        <div class="row g-3" id="requestGrid"></div>
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

        <?= dashboard_device_modal() ?>
        <?= dashboard_supplier_modal() ?>
        <?= dashboard_model_modal() ?>

        <script>
            window.dashboardConfigurationCatalog = <?= json_encode([
                'wonlex-json' => DeviceConfigurationCatalog::configsForProtocol('wonlex-json'),
                'vivistar-iw' => DeviceConfigurationCatalog::configsForProtocol('vivistar-iw'),
            ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>;
        </script>
        <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
        <script type="module" src="main.js"></script>
    </body>
</html>
