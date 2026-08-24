<?php

declare(strict_types=1);


require_once __DIR__ . '/components/helpers.php';

// Supplied by DashboardHttpServer::page(), which requires this file. Declared
// here so the template states its own contract instead of assuming a caller.
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
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css" rel="stylesheet">
    <link href="main.css" rel="stylesheet">
</head>

<body class="bg-body-tertiary" data-dashboard-auth-required="<?= $dashboardApiAuthRequired ? 'true' : 'false' ?>">
    <section id="dashboardLogin" class="dashboard-login row g-0 min-vh-100 d-none" hidden>
        <div class="dashboard-login-atmosphere col-md-4 d-none d-md-block min-vh-100 position-relative overflow-hidden" aria-hidden="true">
            <div class="dashboard-login-orbit"></div>
            <div class="dashboard-login-signal dashboard-login-signal-one"></div>
            <div class="dashboard-login-signal dashboard-login-signal-two"></div>
            <?php
            /**
             * O painel escuro tinha atmosfera e assinatura, mas nenhuma frase: quem chega
             * pela primeira vez não sabia onde estava.
             */
            ?>
            <span class="dashboard-login-badge"><img src="/assets/logo.svg" alt="hitHUB"></span>
            <div class="dashboard-login-mark">
                <span class="dashboard-login-signature">HUB / OPERATIONS</span>
                <p class="dashboard-login-pitch">Ingestão, decisão e reencaminhamento de telemetria de dispositivos de saúde.</p>
            </div>
        </div>
        <div class="dashboard-login-panel col-12 col-md-8 min-vh-100 d-flex flex-column justify-content-center position-relative px-4 px-lg-5 py-5">
            <?php
            /**
             * Uma medida fixa em vez da largura que a coluna der: sem ela a mancha do
             * formulário mudava de forma a cada monitor, entre 300 e 600px.
             *
             * O botão passa a largura total. Estava alinhado à direita debaixo de dois
             * campos de largura total, e o olho descia em coluna para saltar de lado no
             * último passo.
             */
            ?>
            <div class="dashboard-login-form-column">
                <div class="dashboard-login-brand mb-4">
                    <img src="/assets/logo.svg" alt="hitHUB">
                </div>
                <h1 class="h4 mb-1">Entrar</h1>
                <p class="text-secondary small mb-4">Painel de operações. O acesso é por utilizador da API.</p>
                <form id="dashboardLoginForm" class="dashboard-login-form d-grid gap-3" novalidate>
                    <div>
                        <label for="dashboardLoginUsername" class="section-label d-block mb-1">Utilizador</label>
                        <input id="dashboardLoginUsername" name="username" class="form-control form-control-lg" type="text" autocomplete="username" required autofocus>
                    </div>
                    <div>
                        <label for="dashboardLoginPassword" class="section-label d-block mb-1">Palavra-passe</label>
                        <input id="dashboardLoginPassword" name="password" class="form-control form-control-lg" type="password" autocomplete="current-password" required>
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
        <?php
        /**
         * A barra é do navy da marca e não do preto do Bootstrap. `bg-dark` é o cinzento
         * quase preto do tema, que não é cor nenhuma do produto — e ao lado do navy dos
         * botões primários lia-se como um terceiro escuro sem razão.
         */
        ?>
        <nav class="navbar navbar-expand-lg navbar-dark dashboard-navbar">
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
                <?php
                /**
                 * Dois cartões, não um: a identidade do dispositivo e o que se lhe pede
                 * são duas coisas, e a maqueta separa-as. Num cartão só, a divisória
                 * interna tinha de fazer o trabalho que o espaço entre cartões faz melhor.
                 */
                ?>
                <aside id="deviceColumn" class="col-12 col-lg-4 d-flex flex-column gap-3">
                    <div class="card">
                        <div class="card-body">
                            <div class="d-flex justify-content-between align-items-center gap-2 flex-wrap">
                                <span class="section-label">Dispositivo</span>
                                <div class="d-flex align-items-center gap-2">
                                    <button id="openDeviceSelectorBtn" class="btn btn-sm btn-outline-secondary row-action" type="button">Escolher</button>
                                    <?php /* Só o ícone: o "+" ao lado de "Escolher" não precisa da palavra. */ ?>
                                    <button id="addDeviceBtn" class="btn btn-sm btn-outline-secondary row-action" type="button" title="Adicionar dispositivo" aria-label="Adicionar dispositivo"><?= icon('fa-plus') ?></button>
                                </div>
                            </div>
                            <div id="deviceSelectionEmptyState" class="text-center text-secondary py-5">
                                <?= icon('fa-tablet-screen-button', 'fs-1 opacity-25') ?>
                                <h1 class="h5 mt-3">Selecione um dispositivo</h1>
                                <p class="small mb-3">Escolha um dispositivo para ver o resumo operacional, pedir dados e analisar a atividade recente.</p>
                                <button id="emptyStateSelectDeviceBtn" class="btn btn-primary" type="button"><?= icon('fa-list', 'me-1') ?>Escolher dispositivo</button>
                            </div>
                            <div id="selectedDevicePanel" class="d-none">
                                <div class="d-flex align-items-start gap-3 pt-3">
                                    <div id="selectedDevicePreview" class="selected-device-preview"></div>
                                    <div class="min-width-0 flex-grow-1">
                                        <?php
                                        /**
                                         * O estado primeiro, o identificador depois: num
                                         * painel de operações a primeira pergunta sobre um
                                         * dispositivo é se está ligado.
                                         */
                                        ?>
                                        <div class="mb-1"><span id="selectedDeviceBadge" class="config-state"></span></div>
                                        <h1 class="h4 mb-1 text-break tabular-nums lh-sm" id="selectedDeviceTitle"></h1>
                                        <div id="selectedDeviceMeta" class="text-secondary small"></div>
                                    </div>
                                </div>
                                <dl id="selectedDeviceFacts" class="selected-device-facts row g-3 mb-0 border-top mt-3 pt-3"></dl>
                                <div class="border-top mt-3 pt-3 d-flex justify-content-end">
                                    <button id="selectedDeviceEditBtn" class="btn btn-sm btn-outline-secondary row-action" type="button"><?= icon('fa-pen', 'me-1') ?>Editar</button>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="card" id="requestCardsCard">
                        <div class="card-body">
                            <?= section_header('Pedir dados', 'requestCardCount') ?>
                            <div class="text-secondary small mb-3">O mosaico é o pedido.</div>
                            <div class="row g-3" id="requestGrid"></div>
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
                                        <div class="input-group input-group-sm flex-grow-1">
                                            <span class="input-group-text"><?= icon('fa-magnifying-glass') ?></span>
                                            <input id="detailSearch" type="search" class="form-control" placeholder="Procurar na atividade">
                                        </div>
                                        <button class="btn btn-sm btn-outline-secondary d-inline-flex align-items-center gap-2 flex-shrink-0" type="button"
                                            data-bs-toggle="collapse" data-bs-target="#detailFiltersCollapse" aria-expanded="false" aria-controls="detailFiltersCollapse">
                                            <?= icon('fa-sliders') ?>Filtros
                                            <span id="detailFilterCount" class="count-chip count-chip-strong d-none"></span>
                                        </button>
                                    </div>
                                    <div id="detailActiveFiltersRow" class="d-flex flex-wrap align-items-center gap-2 mt-2 d-none">
                                        <div id="detailActiveFilters" class="d-flex flex-wrap gap-2"></div>
                                        <button id="clearDetailFiltersBtn" class="btn btn-link btn-sm p-0 text-decoration-none d-none" type="button">Limpar</button>
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
                                <?php
                                /**
                                 * As ligações ao servidor vêm primeiro: são a âncora temporal de tudo o
                                 * que está em baixo, e leem-se antes de se olhar para uma linha.
                                 *
                                 * Os eventos e os pedidos ficam empilhados em largura total. A meia
                                 * largura, a coluna de estado dos pedidos não cabia e o valor de um
                                 * evento truncava — e quando não havia nada, o ecrã dizia duas vezes
                                 * que não havia nada.
                                 */
                                ?>
                                <?php
                                /**
                                 * Eventos e pedidos lado a lado, e a encher até ao fundo.
                                 *
                                 * Empilhados davam duas listas curtas com metade da altura
                                 * do painel vazia por baixo. Ao lado, cada uma usa a altura
                                 * toda — e é a mesma pergunta feita em dois sentidos: o que
                                 * o dispositivo mandou e o que lhe foi pedido.
                                 *
                                 * A secção das ligações esconde-se quando não há nenhuma:
                                 * os dispositivos que entram por gateway nunca têm, e
                                 * ficavam 180px em branco no topo.
                                 */
                                ?>
                                <div class="device-detail-stack d-flex flex-column">
                                    <section id="connectionSection" class="card-section flex-shrink-0">
                                        <?= section_header('Ligações ao servidor') ?>
                                        <div id="connectionTimeline" style="height:110px;width:100%;"></div>
                                    </section>
                                    <div class="card-section row g-0 flex-grow-1" style="min-height:0">
                                        <div class="col-12 col-xl-6 d-flex flex-column pe-xl-4">
                                            <?= section_header('Eventos recebidos', 'telemetryCount', true) ?>
                                            <div id="telemetryList" class="flex-grow-1 overflow-auto" style="min-height:0"></div>
                                            <?= pagination_component('telemetry') ?>
                                        </div>
                                        <div class="col-12 col-xl-6 d-flex flex-column border-start-xl ps-xl-4 mt-4 mt-xl-0">
                                            <?= section_header('Pedidos ao dispositivo', 'downlinkRequestCount', true) ?>
                                            <div id="downlinkRequests" class="flex-grow-1 overflow-auto" style="min-height:0"></div>
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
    <script src="https://cdn.amcharts.com/lib/5/index.js"></script>
    <script src="https://cdn.amcharts.com/lib/5/xy.js"></script>
    <script src="https://cdn.amcharts.com/lib/5/themes/Animated.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <script type="module" src="main.js"></script>
</body>

</html>
