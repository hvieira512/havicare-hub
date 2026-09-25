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

/* A constelação de fundo: onde cai o cartão de cada tipo, com que inclinação e com que
   compasso. Os tipos vêm do catálogo -- um tipo novo entra aqui sozinho -- e a posição vem
   desta tabela, pela chave. Sem posição declarada, o tipo encosta-se à margem esquerda.

   As medidas são do painel e não do ecrã: em pixéis fixos, um painel de 1869px deixava os
   cartões todos amontoados à esquerda. Corta-se pelo lado e nunca por cima nem por baixo,
   porque o ícone começa a 15px do topo do cartão e o nome acaba nos últimos.

   O `dx`/`dy` é o passo até ao centro do painel e de volta, e por isso aponta ao contrário
   da aresta em que o cartão está: o de cima desce, o da direita anda para a esquerda. */
$loginConstellation = [
    'watch' => ['x' => '-38px', 'y' => '18%', 'angle' => -13, 'seconds' => 8.5, 'delay' => -0.4, 'dx' => 16, 'dy' => 10],
    'ncs' => ['x' => '28%', 'y' => '3%', 'angle' => 6, 'seconds' => 10.5, 'delay' => -3.2, 'dx' => 7, 'dy' => 14],
    'radar' => ['x' => '52%', 'y' => '12%', 'angle' => -4, 'seconds' => 9.2, 'delay' => -6.1, 'dx' => -4, 'dy' => 15],
    'gateway' => ['x' => 'calc(100% - 86px)', 'y' => '4%', 'angle' => 11, 'seconds' => 11, 'delay' => -1.8, 'dx' => -14, 'dy' => 11],
    'bracelet' => ['x' => 'calc(100% - 82px)', 'y' => '48%', 'angle' => -9, 'seconds' => 8.8, 'delay' => -5, 'dx' => -17, 'dy' => 3],
    'pill_dispenser' => ['x' => 'calc(100% - 140px)', 'y' => '70%', 'angle' => -6, 'seconds' => 9.6, 'delay' => -7.4, 'dx' => -13, 'dy' => -10],
    'diaper_sensor' => ['x' => '-6px', 'y' => '80%', 'angle' => 8, 'seconds' => 10, 'delay' => -2.6, 'dx' => 15, 'dy' => -9],
];

$loginDeviceSpotFallback = ['x' => '-40px', 'y' => '34%', 'angle' => 0, 'seconds' => 9, 'delay' => 0, 'dx' => 14, 'dy' => 0];
$loginDevices = [];
foreach (\Hub\Domain\DeviceTypeCatalog::all() as $deviceType => $descriptor) {
    $loginDevices[] = ($loginConstellation[$deviceType] ?? $loginDeviceSpotFallback)
        + ['icon' => (string)$descriptor['icon'], 'label' => (string)$descriptor['label']];
}

/* A coluna da esquerda: o que o hub faz, por ordem de percurso do dado. */
$loginHighlights = [
    ['icon' => 'fa-tower-broadcast', 'title' => 'Ingestão', 'text' => 'Relógios, radares e sensores ligam-se por TCP ou por MQTT.'],
    ['icon' => 'fa-layer-group', 'title' => 'Normalização', 'text' => 'Cada fabricante traduzido para os mesmos nomes e as mesmas unidades.'],
    ['icon' => 'fa-share-nodes', 'title' => 'Publicação', 'text' => 'Uma capacidade por tópico MQTT, para quem integra do outro lado.'],
    ['icon' => 'fa-wave-square', 'title' => 'Operação', 'text' => 'Estado, últimas leituras e comandos, dispositivo a dispositivo.'],
];
?>
<section id="dashboardLogin" class="dashboard-login row g-0 min-vh-100 d-none" hidden>
    <div class="dashboard-login-atmosphere col-12 col-lg d-none d-lg-flex flex-column justify-content-between min-vh-100 position-relative overflow-hidden p-5">
        <div class="dashboard-login-constellation position-absolute" aria-hidden="true">
            <?php foreach ($loginDevices as $device) : ?>
            <span class="dashboard-login-device position-absolute d-flex flex-column align-items-center" style="--x: <?= h((string)$device['x']) ?>; --y: <?= h((string)$device['y']) ?>; --angle: <?= (float)$device['angle'] ?>deg; --seconds: <?= (float)$device['seconds'] ?>s; --delay: <?= (float)$device['delay'] ?>s; --dx: <?= (int)$device['dx'] ?>px; --dy: <?= (int)$device['dy'] ?>px">
                <?= icon($device['icon']) ?>
                <span class="dashboard-login-device-label d-block fw-semibold text-center"><?= h($device['label']) ?></span>
            </span>
            <?php endforeach; ?>
        </div>
        <span class="dashboard-login-badge dashboard-login-column position-relative w-100 mx-auto"><img class="d-block w-auto opacity-75" src="/assets/logo-dark.png" alt="havi hub"></span>
        <div class="dashboard-login-story dashboard-login-column position-relative w-100 mx-auto">
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
        <div class="dashboard-login-column d-flex align-items-end justify-content-between gap-4 position-relative w-100 mx-auto">
            <span class="dashboard-login-signature d-block fw-semibold text-uppercase">Hub / Operação</span>
            <span class="dashboard-login-aside d-block text-end">cada aparelho fala<br>a sua língua</span>
        </div>
    </div>
    <div class="dashboard-login-panel col-12 col-lg min-vh-100 d-flex flex-column justify-content-center align-items-center position-relative z-1 px-4 py-5">
        <button data-theme-toggle class="dashboard-login-theme btn border bg-body text-secondary position-absolute top-0 end-0 m-4 p-0 rounded-circle d-flex align-items-center justify-content-center" type="button" aria-pressed="false" aria-label="Mudar para o tema escuro" title="Mudar para o tema escuro">
            <?= icon('fa-moon', 'fa-fw') ?>
        </button>
        <div class="dashboard-login-card w-100 p-0 p-sm-5">
            <div class="dashboard-login-brand mb-4">
                <img class="d-block mx-auto" src="/assets/logo.png" alt="havi hub">
            </div>
            <form id="dashboardLoginForm" class="d-grid gap-3" novalidate>
                <?php foreach ($loginFields as $field) : ?>
                <div>
                    <label for="<?= $field['id'] ?>" class="section-label d-block mb-1"><?= h($field['label']) ?></label>
                    <div class="input-group">
                        <span class="input-group-text bg-body text-body-tertiary"><?= icon($field['icon'], 'fa-fw') ?></span>
                        <input id="<?= $field['id'] ?>" name="<?= $field['name'] ?>" class="form-control" type="<?= $field['type'] ?>" autocomplete="<?= $field['autocomplete'] ?>" required<?= $field['autofocus'] ? ' autofocus' : '' ?>>
                        <?php if ($field['type'] === 'password') : ?>
                        <button data-password-toggle="<?= $field['id'] ?>" class="input-group-text bg-body text-body-tertiary" type="button" aria-pressed="false" aria-label="Mostrar a palavra-passe" title="Mostrar a palavra-passe">
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
