<?php
/* O ecrã de entrada, num componente próprio como os modais, para o index.php ficar só com o
 * shell da app. Escondido por omissão; o session.js mostra-o quando o acesso pede sessão. */
?>
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
