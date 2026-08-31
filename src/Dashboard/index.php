<?php

declare(strict_types=1);

require_once __DIR__ . '/components/helpers.php';

// Fornecido pelo `DashboardHttpServer::page()`, que faz `require` deste ficheiro. Declarado
// aqui para o template dizer o seu próprio contrato em vez de assumir quem o chama.
$dashboardApiAuthRequired = $dashboardApiAuthRequired ?? true;
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
    <!-- Antes das folhas e antes de qualquer módulo: o tema tem de estar posto na primeira
         pintura, senão a página abre clara e escurece à frente de quem está a olhar. É a
         única razão para ter JavaScript aqui em cima, e por isso não faz mais nada. A chave
         é a mesma do `storage.js`, escrita à mão porque aqui ainda não há módulos. -->
    <script>
        (function () {
            try {
                var stored = localStorage.getItem("hub-dashboard-theme");
                var dark = stored === "dark"
                    || (stored !== "light" && window.matchMedia("(prefers-color-scheme: dark)").matches);
                document.documentElement.setAttribute("data-bs-theme", dark ? "dark" : "light");
            } catch (e) {
                document.documentElement.setAttribute("data-bs-theme", "light");
            }
        })();
    </script>
    <link href="/assets/vendor/bootstrap/bootstrap.min.css" rel="stylesheet">
    <link href="/assets/vendor/fontawesome/css/all.min.css" rel="stylesheet">
    <!-- A ordem é a da folha única de onde estes saíram: sem build, a cascata é a ordem
         destas etiquetas, e várias regras contam com vir depois das que anulam. O
         `main.css` fica no fim porque ficou com a cauda do ficheiro original. -->
    <link href="/assets/css/base.css" rel="stylesheet">
    <link href="/assets/css/shell.css" rel="stylesheet">
    <link href="/assets/css/device.css" rel="stylesheet">
    <link href="/assets/css/login.css" rel="stylesheet">
    <link href="main.css" rel="stylesheet">
</head>

<body class="bg-body-tertiary" data-dashboard-auth-required="<?= $dashboardApiAuthRequired ? 'true' : 'false' ?>">
    <section id="dashboardLogin" class="dashboard-login row g-0 min-vh-100 d-none" hidden>
        <div class="dashboard-login-atmosphere col-md-4 d-none d-md-block min-vh-100 position-relative overflow-hidden" aria-hidden="true">
            <div class="dashboard-login-orbit"></div>
            <div class="dashboard-login-signal dashboard-login-signal-one"></div>
            <div class="dashboard-login-signal dashboard-login-signal-two"></div>
            <span class="dashboard-login-badge"><img src="/assets/logo.svg" alt="hitHUB"></span>
            <div class="dashboard-login-mark">
                <span class="dashboard-login-signature">HUB / OPERATIONS</span>
                <p class="dashboard-login-pitch">Ingestão, decisão e reencaminhamento de telemetria de dispositivos de saúde.</p>
            </div>
        </div>
        <div class="dashboard-login-panel col-12 col-md-8 min-vh-100 d-flex flex-column justify-content-center position-relative px-4 px-lg-5 py-5">
            <div class="dashboard-login-form-column">
                <div class="dashboard-login-brand mb-4">
                    <img src="/assets/logo.svg" alt="hitHUB">
                </div>
                <h1 class="h4 mb-1">Entrar</h1>
                <p class="text-secondary small mb-4">Painel de operações. O acesso é por utilizador da API.</p>
                <form id="dashboardLoginForm" class="dashboard-login-form d-grid gap-3" novalidate>
                    <div>
                        <label for="dashboardLoginUsername" class="section-label d-block mb-1">Utilizador</label>
                        <input id="dashboardLoginUsername" name="username" class="form-control" type="text" autocomplete="username" required autofocus>
                    </div>
                    <div>
                        <label for="dashboardLoginPassword" class="section-label d-block mb-1">Palavra-passe</label>
                        <input id="dashboardLoginPassword" name="password" class="form-control" type="password" autocomplete="current-password" required>
                    </div>
                    <button id="dashboardLoginSubmit" class="btn btn-primary btn-lg dashboard-login-submit w-100 mt-2" type="submit">
                        <span class="dashboard-login-submit-label">Entrar</span>
                        <span class="dashboard-login-submit-loading d-none">
                            <span class="spinner-border spinner-border-sm" aria-hidden="true"></span>
                            <span>A entrar…</span>
                        </span>
                    </button>
                </form>
            </div>
        </div>
    </section>

    <div id="dashboardApp" class="<?= $dashboardApiAuthRequired ? 'd-none' : '' ?>"<?= $dashboardApiAuthRequired ? ' hidden' : '' ?>>
        <nav class="navbar dashboard-navbar">
            <div class="container-fluid">
                <span class="navbar-brand"><img src="/assets/logo.svg" alt="hitHUB"></span>
                <div class="d-flex align-items-center gap-2">
                    <button id="dashboardThemeBtn" class="btn btn-sm btn-dark" type="button" aria-pressed="false" aria-label="Mudar para o tema escuro" title="Mudar para o tema escuro">
                        <?= icon('fa-moon', 'fs-5 fa-fw') ?>
                    </button>
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
        <main class="container-fluid py-3 dashboard-main">
            <div class="row g-3">
                <aside id="deviceColumn" class="col-12 col-lg-4 d-flex flex-column gap-3">
                    <div class="card">
                        <div class="card-body">
                            <div class="d-flex justify-content-between align-items-center gap-2 flex-wrap">
                                <span class="section-label">Dispositivo</span>
                                <div class="d-flex align-items-center gap-2">
                                        <button id="openDeviceSelectorBtn" class="btn btn-sm btn-primary" type="button">
                                            <i class="fa-solid fa-list me-1"></i>
                                            Escolher
                                        </button>
                                    <button id="addDeviceBtn" class="btn btn-sm btn-outline-secondary" type="button" title="Adicionar dispositivo" aria-label="Adicionar dispositivo"><?= icon('fa-plus') ?></button>
                                </div>
                            </div>
                            <div id="deviceSelectionEmptyState" class="text-center text-secondary py-5">
                                <?= icon('fa-tablet-screen-button', 'fs-1 opacity-25') ?>
                                <h1 class="h5 mt-3">Selecione um dispositivo</h1>
                                <p class="small mb-3">Escolha um dispositivo para ver o resumo operacional, pedir dados e analisar a atividade recente.</p>
                                <button id="emptyStateSelectDeviceBtn" class="btn btn-primary" type="button"><?= icon('fa-list', 'me-1') ?>Escolher dispositivo</button>
                            </div>
                            <div id="selectedDevicePanel" class="d-none">
                                <?php /* O `card-body` ja da 16px em volta; isto so separa do
                                       * cabecalho com o "Escolher". Em telefone chega
                                       * metade, e os botoes de 44px ja afastam por si. */ ?>
                                <div class="d-flex align-items-start gap-3 pt-2 pt-sm-3">
                                    <div id="selectedDevicePreview" class="selected-device-preview"></div>
                                    <div class="min-width-0 flex-grow-1">
                                        <div class="mb-1" id="selectedDeviceBadge"></div>
                                        <h1 class="h4 mb-1 text-break tabular-nums lh-sm" id="selectedDeviceTitle"></h1>
                                        <div id="selectedDeviceMeta" class="text-secondary small"></div>
                                    </div>
                                </div>
                                <?php /* Cada separador leva 32px -- 16 de margem mais 16 de
                                       * padding -- e são dois. Em telefone valem metade. */ ?>
                                <dl id="selectedDeviceFacts" class="selected-device-facts row g-2 g-sm-3 mb-0 border-top mt-2 pt-2 mt-sm-3 pt-sm-3"></dl>
                                <div class="border-top mt-2 pt-2 mt-sm-3 pt-sm-3 d-flex justify-content-end">
                                    <button id="selectedDeviceEditBtn" class="btn btn-sm btn-outline-secondary" type="button"><?= icon('fa-pen', 'me-1') ?>Editar</button>
                                </div>
                            </div>
                        </div>
                    </div>
                    <?php /* Em telefone a moldura de fora desaparece: os mosaicos já são
                           * cartões, e cartão dentro de cartão gastava 32px de largura numa
                           * moldura que não separa nada. O padding e as goteiras saem por
                           * utilitário; a borda e o fundo precisam da regra `.card-flush-sm`
                           * no CSS, porque o Bootstrap não tem `border-sm` nem `bg-sm-*`. */ ?>
                    <div class="card card-flush-sm" id="requestCardsCard">
                        <div class="card-body p-0 p-sm-3">
                            <div class="row g-2 g-sm-3" id="requestGrid"></div>
                        </div>
                    </div>
                    <div class="card d-none" id="ncsEventSection">
                        <div class="card-body">
                            <?= section_header('Eventos NCS recentes', 'ncsEventCardCount') ?>
                            <div class="row g-3" id="ncsEventGrid"></div>
                        </div>
                    </div>
                </aside>
                <section id="detailColumn" class="col-12 col-lg-8">
                    <div class="card h-100">
                        <div class="card-body d-flex flex-column">
                            <div id="deviceDetail" class="d-none device-detail-open">
                                <div id="detailFiltersPanel">
                                    <div class="d-flex align-items-center gap-2">
                                        <?= search_input('detailSearch', 'Procurar na atividade', 'flex-grow-1') ?>
                                        <?= filter_toggle_button('detailFiltersCollapse', 'detailFilterCount', 'flex-shrink-0') ?>
                                    </div>
                                    <div id="detailActiveFiltersRow" class="d-flex flex-wrap align-items-center gap-2 mt-2 d-none">
                                        <div id="detailActiveFilters" class="d-flex flex-wrap gap-2"></div>
                                        <button id="clearDetailFiltersBtn" class="btn btn-link btn-sm p-0 text-decoration-none text-secondary small d-none" type="button">Limpar</button>
                                    </div>
                                    <div class="collapse" id="detailFiltersCollapse">
                                        <div class="row g-2 align-items-end pt-3">
                                            <div class="col-auto">
                                                <label for="detailFilterFrom" class="section-label d-block mb-1">De</label>
                                                <input type="datetime-local" id="detailFilterFrom" class="form-control form-control-sm">
                                            </div>
                                            <div class="col-auto">
                                                <label for="detailFilterTo" class="section-label d-block mb-1">Até</label>
                                                <input type="datetime-local" id="detailFilterTo" class="form-control form-control-sm">
                                            </div>
                                            <div class="col-auto">
                                                <label for="detailFilterType" class="section-label d-block mb-1">Tipo</label>
                                                <select id="detailFilterType" class="form-select form-select-sm">
                                                    <option value="all">Todos</option>
                                                </select>
                                            </div>
                                            <div class="col-auto">
                                                <button id="applyDetailFiltersBtn" class="btn btn-sm btn-primary"><?= icon('fa-check', 'me-1') ?>Aplicar</button>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                                <div class="device-detail-stack d-flex flex-column">
                                    <section id="connectionSection" class="card-section flex-shrink-0">
                                        <?= section_header('Ligações ao servidor') ?>
                                        <div id="connectionTimeline"></div>
                                    </section>
                                    <div class="card-section row g-0 flex-grow-1" style="min-height:0">
                                        <div class="col-12 col-xl-6 d-flex flex-column pe-xl-4">
                                            <?= section_header('Eventos recebidos', 'telemetryCount', true) ?>
                                            <div id="telemetryList" class="flex-grow-1 overflow-auto" style="min-height:0"></div>
                                            <?= pagination_component('telemetryPager') ?>
                                        </div>
                                        <div class="col-12 col-xl-6 d-flex flex-column border-start-xl ps-xl-4 mt-4 mt-xl-0">
                                            <?= section_header('Pedidos ao dispositivo', 'downlinkRequestCount', true) ?>
                                            <div id="downlinkRequests" class="flex-grow-1 overflow-auto" style="min-height:0"></div>
                                            <?= pagination_component('downlinkPager') ?>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </section>
            </div>
        </main>

        <?php require __DIR__ . '/components/modals/device.php'; ?>
        <?php require __DIR__ . '/components/modals/device-wizard.php'; ?>
        <?php require __DIR__ . '/components/modals/settings.php'; ?>
        <?php require __DIR__ . '/components/modals/device-selector.php'; ?>
    </div>

    <script>
        window.hubDashboardApiToken = null;
    </script>
    <script src="/assets/vendor/bootstrap/bootstrap.bundle.min.js"></script>
    <script src="/assets/vendor/sweetalert2/sweetalert2.all.min.js"></script>
    <script type="module" src="main.js"></script>
</body>

</html>
