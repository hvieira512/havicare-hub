<?php

$loginFields = [
    [
        'id' => 'dashboardLoginUsername',
        'name' => 'username',
        'label' => 'Utilizador',
        'type' => 'text',
        'icon' => 'fa-circle-user',
        'autocomplete' => 'username',
        'autofocus' => true,
    ],
    [
        'id' => 'dashboardLoginPassword',
        'name' => 'password',
        'label' => 'Palavra-passe',
        'type' => 'password',
        'icon' => 'fa-lock',
        'autocomplete' => 'current-password',
        'autofocus' => false,
    ],
];

/* A coluna da esquerda: o que o hub faz, por ordem de percurso do dado. */
$loginHighlights = [
    ['icon' => 'fa-tower-broadcast', 'title' => 'Ingestão', 'text' => 'Relógios, radares e sensores ligam-se por TCP ou por MQTT.'],
    ['icon' => 'fa-layer-group', 'title' => 'Normalização', 'text' => 'Cada fabricante traduzido para os mesmos nomes e as mesmas unidades.'],
    ['icon' => 'fa-share-nodes', 'title' => 'Publicação', 'text' => 'Uma capacidade por tópico MQTT, para quem integra do outro lado.'],
    ['icon' => 'fa-wave-square', 'title' => 'Operação', 'text' => 'Estado, últimas leituras e comandos, dispositivo a dispositivo.'],
];
?>
<section id="dashboardLogin" class="dashboard-login row g-0 min-vh-100 d-none" hidden>
    <div class="dashboard-login-atmosphere col-lg-7 d-none d-lg-flex flex-column justify-content-between min-vh-100 position-relative overflow-hidden p-5">
        <span class="dashboard-login-badge position-relative"><img class="d-block w-auto opacity-75" src="/assets/logo-dark.png" alt="havi hub"></span>
        <div class="dashboard-login-story position-relative">
            <span class="dashboard-login-rule d-block rounded-pill mb-3"></span>
            <h1 class="dashboard-login-headline fw-semibold mb-3">Muitos aparelhos.<br>Um só contrato.</h1>
            <p class="dashboard-login-pitch lh-base mb-5">Ingestão, normalização e publicação de telemetria de dispositivos de saúde, sempre no mesmo formato.</p>
            <ul class="list-unstyled d-grid gap-3 m-0">
                <?php foreach ($loginHighlights as $highlight) : ?>
                <li class="d-flex align-items-start gap-3">
                    <span class="dashboard-login-highlight-icon d-flex align-items-center justify-content-center flex-shrink-0 rounded-3"><?= icon($highlight['icon']) ?></span>
                    <span class="d-block">
                        <span class="dashboard-login-highlight-title d-block fw-semibold small"><?= h($highlight['title']) ?></span>
                        <span class="dashboard-login-highlight-text d-block small lh-base"><?= h($highlight['text']) ?></span>
                    </span>
                </li>
                <?php endforeach; ?>
            </ul>
        </div>
        <span class="dashboard-login-signature d-block fw-semibold text-uppercase position-relative">Hub / Operação</span>
    </div>
    <div class="dashboard-login-panel col-12 col-lg-5 min-vh-100 d-flex flex-column justify-content-center align-items-center position-relative z-1 px-4 py-5">
        <button data-theme-toggle class="dashboard-login-theme btn border bg-body text-secondary position-absolute top-0 end-0 m-4 p-0 rounded-circle d-flex align-items-center justify-content-center" type="button" aria-pressed="false" aria-label="Mudar para o tema escuro" title="Mudar para o tema escuro">
            <?= icon('fa-moon', 'fa-fw') ?>
        </button>
        <div class="dashboard-login-card w-100 bg-body border rounded-4 shadow-lg p-4 p-sm-5">
            <div class="dashboard-login-brand mb-4">
                <img class="d-block mx-auto" src="/assets/logo.png" alt="havi hub">
            </div>
            <form id="dashboardLoginForm" class="d-grid gap-3" novalidate>
                <?php foreach ($loginFields as $field) : ?>
                <div>
                    <label for="<?= $field['id'] ?>" class="section-label d-block mb-1"><?= h($field['label']) ?></label>
                    <div class="dashboard-login-field position-relative d-flex align-items-center">
                        <span class="position-absolute start-0 ms-3 d-flex align-items-center text-body-tertiary pe-none"><?= icon($field['icon']) ?></span>
                        <input id="<?= $field['id'] ?>" name="<?= $field['name'] ?>" class="form-control" type="<?= $field['type'] ?>" autocomplete="<?= $field['autocomplete'] ?>" required<?= $field['autofocus'] ? ' autofocus' : '' ?>>
                        <?php if ($field['type'] === 'password') : ?>
                        <button data-password-toggle="<?= $field['id'] ?>" class="dashboard-login-reveal btn border-0 text-body-tertiary position-absolute end-0 me-1 p-0 d-flex align-items-center justify-content-center" type="button" aria-pressed="false" aria-label="Mostrar a palavra-passe" title="Mostrar a palavra-passe">
                            <?= icon('fa-eye', 'fa-fw') ?>
                        </button>
                        <?php endif; ?>
                    </div>
                </div>
                <?php endforeach; ?>
                <button id="dashboardLoginSubmit" class="btn btn-primary fw-semibold w-100 py-2 mt-2" type="submit">
                    <span class="dashboard-login-submit-label d-flex align-items-center justify-content-center gap-2">
                        <span>Entrar</span>
                        <?= icon('fa-arrow-right') ?>
                    </span>
                    <span class="dashboard-login-submit-loading d-flex d-none align-items-center justify-content-center gap-2">
                        <span class="spinner-border spinner-border-sm" aria-hidden="true"></span>
                        <span>A entrar…</span>
                    </span>
                </button>
            </form>
            <p class="text-secondary text-center small mt-4 mb-0">Sem conta? Peça a quem administra o hub.</p>
        </div>
    </div>
</section>
