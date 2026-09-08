<?php

declare(strict_types=1);

require_once __DIR__ . '/components/helpers.php';

// Fornecido pelo `DashboardHttpServer::page()`, que faz `require` deste ficheiro. Declarado
// aqui para o template dizer o seu próprio contrato em vez de assumir quem o chama.
$dashboardApiAuthRequired = $dashboardApiAuthRequired ?? true;
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
    <link href="/assets/vendor/sweetalert2/bootstrap-5.min.css" rel="stylesheet">
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
    <?php require __DIR__ . '/components/login.php'; ?>

    <div id="dashboardApp" class="<?= $dashboardApiAuthRequired ? 'd-none' : '' ?>"<?= $dashboardApiAuthRequired ? ' hidden' : '' ?>>
        <?php require __DIR__ . '/components/navbar.php'; ?>
        <main class="container-fluid py-3 dashboard-main">
            <div class="row g-3">
                <?php require __DIR__ . '/components/device-column.php'; ?>
                <?php require __DIR__ . '/components/detail-column.php'; ?>
            </div>
        </main>

        <?php require __DIR__ . '/components/modals/device.php'; ?>
        <?php require __DIR__ . '/components/modals/device-wizard.php'; ?>
        <?php require __DIR__ . '/components/modals/settings.php'; ?>
        <?php require __DIR__ . '/components/modals/device-selector.php'; ?>
    </div>

    <?php /* O descritor dos tipos vem daqui e não de um endpoint: o formulário precisa dele
           * à primeira pintura, e uma chamada assíncrona só traria uma ordem de carregamento
           * para gerir. É dado e não código -- uma ilha JSON que o `domain.js` lê. A fonte é
           * o `DeviceTypeCatalog`, em PHP. */ ?>
    <script type="application/json" id="hub-device-types"><?= \Hub\Domain\DeviceTypeCatalog::asJson() ?></script>
    <script src="/assets/vendor/bootstrap/bootstrap.bundle.min.js"></script>
    <script src="/assets/vendor/sweetalert2/sweetalert2.all.min.js"></script>
    <?php /* As grelhas do modal das definições. Não é módulo: expõe-se em `agGrid` global,
             e o `dashboard/grid.js` conta com ele já carregado. */ ?>
    <script src="/assets/vendor/ag-grid/ag-grid-community.min.js"></script>
    <script type="module" src="main.js"></script>
</body>

</html>
