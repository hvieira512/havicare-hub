<?php

declare(strict_types=1);

require_once __DIR__ . '/components/helpers.php';

// Fornecido pelo `DashboardHttpServer::page()`, que faz `require` deste ficheiro. Declarado
// aqui para o template dizer o seu próprio contrato em vez de assumir quem o chama.
$dashboardApiAuthRequired = $dashboardApiAuthRequired ?? true;
$downlinkQueueTtlSeconds = $downlinkQueueTtlSeconds ?? 300;
$assetVersion = $assetVersion ?? '';

/* Os nossos ficheiros levam a impressão digital do conjunto no caminho, e os `import`
 * relativos herdam-na sem haver passo de compilação. Os de terceiros ficam de fora: já têm
 * versão própria no caminho, e arrastá-los obrigava a puxá-los outra vez a cada deploy. */
$asset = static function (string $path) use ($assetVersion): string {
    $path = '/' . ltrim($path, '/');
    return $assetVersion === '' || str_starts_with($path, '/assets/vendor/')
        ? $path
        : '/v/' . $assetVersion . $path;
};
require_once __DIR__ . '/components/listing.php';
require_once __DIR__ . '/components/pagination.php';
require_once __DIR__ . '/components/modal.php';

?>
<!doctype html>
<html lang="pt-PT">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Havicare Hub</title>
    <!-- O ícone é só o «h» da marca: o logótipo inteiro é uma faixa e num quadrado de 16px
         não se lia nada. -->
    <link rel="apple-touch-icon" sizes="180x180" href="/assets/logo-mark.png">
    <link rel="icon" type="image/png" href="/assets/logo-mark.png">
    <!-- O tema antes da primeira pintura: script clássico e sem defer, no <head> antes das
         folhas, para pôr o data-bs-theme antes de o CSS carregar. Ver assets/js/theme-init.js. -->
    <script src="<?= $asset('/assets/js/theme-init.js') ?>"></script>
    <?php
    /* A ordem é a cascata: sem build, uma folha vale pela ordem da etiqueta, e várias regras
     * contam com vir depois das que anulam. */
    $stylesheets = [
        '/assets/vendor/bootstrap/bootstrap.min.css',
        '/assets/vendor/fontawesome/css/all.min.css',
        '/assets/vendor/sweetalert2/bootstrap-5.min.css',
        '/assets/css/base.css',
        '/assets/css/shell.css',
        '/assets/css/device.css',
        '/assets/css/login.css',
        'main.css',
    ];
    ?>
    <?php foreach ($stylesheets as $stylesheet) : ?>
    <link href="<?= $asset($stylesheet) ?>" rel="stylesheet">
    <?php endforeach; ?>
</head>

<body class="bg-body-tertiary" data-dashboard-auth-required="<?= $dashboardApiAuthRequired ? 'true' : 'false' ?>" data-downlink-queue-ttl="<?= (int)$downlinkQueueTtlSeconds ?>">
    <?php require __DIR__ . '/components/login.php'; ?>

    <div id="dashboardApp" class="<?= $dashboardApiAuthRequired ? 'd-none' : '' ?>"<?= $dashboardApiAuthRequired ? ' hidden' : '' ?>>
        <?php require __DIR__ . '/components/navbar.php'; ?>
        <main class="container-fluid py-3 dashboard-main d-flex flex-column flex-fill min-h-0 w-100 mx-auto">
            <div class="row g-3 flex-fill">
                <?php require __DIR__ . '/components/device-column.php'; ?>
                <?php require __DIR__ . '/components/detail-column.php'; ?>
            </div>
        </main>

        <?php require __DIR__ . '/components/modals/device.php'; ?>
        <?php require __DIR__ . '/components/modals/device-wizard.php'; ?>
        <?php require __DIR__ . '/components/modals/settings.php'; ?>
        <?php require __DIR__ . '/components/modals/device-selector.php'; ?>
        <?php require __DIR__ . '/components/modals/radar-map.php'; ?>
    </div>

    <?php /* O descritor dos tipos vem daqui e não de um endpoint: o formulário precisa dele à
           * primeira pintura. A fonte é o `DeviceTypeCatalog`, em PHP. */ ?>
    <script type="application/json" id="hub-device-types"><?= \Hub\Domain\DeviceTypeCatalog::asJson() ?></script>
    <?php /* A licença do amCharts, para os gráficos dos sinais vitais de um radar. Vem daqui
           * porque é configuração do servidor, e fica vazia quando ninguém a definiu. */ ?>
    <script type="application/json" id="hub-amcharts-license"><?= json_encode($amchartsLicense ?? '') ?></script>
    <script src="/assets/vendor/bootstrap/bootstrap.bundle.min.js"></script>
    <script src="/assets/vendor/sweetalert2/sweetalert2.all.min.js"></script>
    <?php /* O AG Grid (2 MB) não vem aqui: o `dashboard/grid.js` carrega-o à primeira grelha,
             e a maioria das sessões nunca abre as definições. */ ?>
    <script type="module" src="<?= $asset('main.js') ?>"></script>
</body>

</html>
