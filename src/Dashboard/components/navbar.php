        <?php
        // A barra de topo: tema, notificações e o menu do utilizador. O `$dashboardApiAuthRequired`
        // é o contrato do partial, como no index.php: sem quem o inclua, assume acesso protegido.
        $dashboardApiAuthRequired = $dashboardApiAuthRequired ?? true;
        ?>
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
