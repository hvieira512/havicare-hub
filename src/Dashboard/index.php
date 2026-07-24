<?php

declare(strict_types=1);


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
    <link href="main.css" rel="stylesheet">
</head>

<body class="bg-body-tertiary" data-dashboard-auth-required="<?= $dashboardApiAuthRequired ? 'true' : 'false' ?>">
    <section id="dashboardLogin" class="dashboard-login row g-0 min-vh-100<?= $dashboardApiAuthRequired ? '' : ' d-none' ?>">
        <div class="dashboard-login-atmosphere col-md-4 d-none d-md-block min-vh-100 position-relative overflow-hidden" aria-hidden="true">
            <div class="dashboard-login-orbit"></div>
            <div class="dashboard-login-signal dashboard-login-signal-one"></div>
            <div class="dashboard-login-signal dashboard-login-signal-two"></div>
            <span>HUB / OPERATIONS</span>
        </div>
        <div class="dashboard-login-panel col-12 col-md-8 min-vh-100 d-flex flex-column justify-content-center position-relative px-4 px-lg-5 py-5">
            <div class="container">
                <div class="row justify-content-center">
                    <div class="col-12 col-lg-8 col-xl-7 col-xxl-5">
                        <div class="dashboard-login-brand d-flex justify-content-center mb-5">
                            <img src="/assets/logo.svg" alt="hitHUB">
                        </div>
                        <form id="dashboardLoginForm" class="dashboard-login-form d-grid gap-3" novalidate>
                            <div>
                                <label for="dashboardLoginUsername" class="form-label">Utilizador</label>
                                <input id="dashboardLoginUsername" name="username" class="form-control" type="text" autocomplete="username" required autofocus>
                            </div>
                            <div>
                                <label for="dashboardLoginPassword" class="form-label">Palavra-passe</label>
                                <input id="dashboardLoginPassword" name="password" class="form-control" type="password" autocomplete="current-password" required>
                            </div>
                            <div class="d-flex justify-content-end">
                                <button id="dashboardLoginSubmit" class="btn btn-primary btn-lg dashboard-login-submit" type="submit">
                                    <span class="dashboard-login-submit-label">Entrar</span>
                                    <span class="dashboard-login-submit-loading d-none">
                                        <span class="spinner-border spinner-border-sm" aria-hidden="true"></span>
                                        <span>A entrar…</span>
                                    </span>
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <div id="dashboardApp" class="<?= $dashboardApiAuthRequired ? 'd-none' : '' ?>">
        <nav class="navbar navbar-expand-lg bg-dark navbar-dark">
            <div class="container-fluid">
                <span class="navbar-brand"><img src="/assets/logo.svg" alt="hitHUB"></span>
                <div class="d-flex align-items-center gap-2">
                    <div id="dashboardNotificationsDropdown" class="dropdown">
                        <button id="dashboardNotificationsBtn" class="btn btn-sm btn-dark position-relative" type="button" data-bs-toggle="dropdown" data-bs-auto-close="outside" aria-expanded="false" aria-label="Notificações" title="Notificações">
                            <?= icon('fa-bell', 'fs-5') ?>
                            <span id="dashboardNotificationsBadge" class="position-absolute top-0 start-100 translate-middle badge rounded-pill text-bg-danger d-none">0</span>
                        </button>
                        <div class="dropdown-menu dropdown-menu-end shadow dashboard-notifications-menu p-0">
                            <div class="d-flex align-items-center justify-content-between border-bottom px-3 py-2">
                                <span class="fw-semibold">Notificações</span>
                                <span id="dashboardNotificationsSummary" class="small text-secondary"></span>
                            </div>
                            <div id="dashboardNotificationsList" class="dashboard-notifications-list list-group list-group-flush overflow-auto">
                                <div class="list-group-item text-center text-secondary small p-4">A carregar...</div>
                            </div>
                        </div>
                    </div>
                    <div class="dropdown">
                        <button class="btn btn-sm btn-dark dropdown-toggle d-flex align-items-center gap-2" type="button" data-bs-toggle="dropdown" aria-expanded="false">
                            <?= icon('fa-circle-user', 'fs-5') ?>
                            <span id="dashboardAuthenticatedUsername">Administrador</span>
                        </button>
                        <ul class="dropdown-menu dropdown-menu-end shadow">
                            <li>
                                <button id="manageSettingsBtn" class="dropdown-item" type="button"><?= icon('fa-sliders', 'me-2') ?>Definições</button>
                            </li>
                            <li>
                                <hr class="dropdown-divider">
                            </li>
                            <li>
                                <button id="dashboardLogoutBtn" class="dropdown-item text-danger<?= $dashboardApiAuthRequired ? '' : ' d-none' ?>" type="button"><?= icon('fa-arrow-right-from-bracket', 'me-2') ?>Sair</button>
                            </li>
                        </ul>
                    </div>
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
                                    <div class="min-width-0 flex-grow-1 lh-1">
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
                                <div id="ncsEventSection" class="border-top pt-4 mt-4 d-none">
                                    <div class="d-flex justify-content-between align-items-center mb-3">
                                        <span class="fw-semibold"><?= icon('fa-bell', 'me-2') ?>Eventos NCS recentes</span>
                                        <span id="ncsEventCardCount" class="small text-secondary"></span>
                                    </div>
                                    <div class="row g-3" id="ncsEventGrid"></div>
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
                                <div id="detailFiltersPanel" class="border rounded bg-body-tertiary p-2 mb-3">
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
                                    </section>
                                </div>
                            </div>
                        </div>
                    </div>
                </section>
            </div>
        </main>

        <?php require __DIR__ . '/components/modals/device.php'; ?>
        <?php require __DIR__ . '/components/modals/settings.php'; ?>
        <?php require __DIR__ . '/components/modals/device-selector.php'; ?>
    </div>

    <script>
        window.hubDashboardApiToken = null;
    </script>
    <script src="https://cdn.amcharts.com/lib/5/index.js"></script>
    <script src="https://cdn.amcharts.com/lib/5/xy.js"></script>
    <script src="https://cdn.amcharts.com/lib/5/themes/Animated.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <script type="module" src="main.js"></script>
</body>

</html>
