<?php

$vitalCard = static function (string $prefix, string $icon, string $label, string $unit, string $tone): void { ?>
    <?php
    $stat = static function (string $id, string $icon, string $label): void { ?>
        <span class="badge rounded-pill bg-body-tertiary text-body-secondary fw-normal d-inline-flex align-items-center gap-1" data-bs-toggle="tooltip" title="<?= h($label) ?>">
            <?= icon($icon, 'opacity-75') ?><span id="<?= h($id) ?>" class="tabular-nums">--</span>
        </span>
    <?php };
    ?>
    <div class="border border-<?= h($tone) ?>-subtle rounded-3 overflow-hidden p-3 flex-grow-1 d-flex flex-column">
        <div class="d-flex align-items-start justify-content-between flex-wrap gap-2">
            <div class="d-flex align-items-center gap-3 min-w-0">
                <span class="radar-vital-icon d-inline-flex align-items-center justify-content-center rounded-3 flex-shrink-0 bg-<?= h($tone) ?>-subtle text-<?= h($tone) ?>"><?= icon($icon) ?></span>
                <div class="min-w-0">
                    <div class="telemetry-card-title text-uppercase fw-normal lh-sm text-<?= h($tone) ?>"><?= h($label) ?></div>
                    <div class="h4 mb-0 fw-semibold lh-sm tabular-nums text-<?= h($tone) ?>"><span id="<?= h($prefix) ?>Value">--</span> <?= h($unit) ?></div>
                </div>
            </div>
            <div class="d-flex flex-wrap gap-2">
                <?php $stat($prefix . 'Min', 'fa-arrow-down', 'Mínimo da janela'); ?>
                <?php $stat($prefix . 'Avg', 'fa-equals', 'Média da janela'); ?>
                <?php $stat($prefix . 'Max', 'fa-arrow-up', 'Máximo da janela'); ?>
            </div>
        </div>
        <div id="<?= h($prefix) ?>Chart" class="radar-vital-chart w-100 flex-grow-1 mt-3"></div>
    </div>
<?php };

ob_start();
?>
<div class="row g-4">
    <div class="col-12 col-xl-8">
        <div class="card h-100">
            <div class="card-header d-flex align-items-center justify-content-between gap-2 py-3">
                <span class="fw-semibold">Monitorização de trajetos</span>
                <button type="button" class="btn btn-sm btn-outline-secondary flex-shrink-0" id="radarMapSyncBtn"><?= icon('fa-rotate', 'me-1') ?>Sincronizar</button>
            </div>
            <div class="card-body d-flex flex-column p-3 p-xl-4">
                <div id="radarMapPeopleCount" class="text-center mb-2"></div>
                <div id="radarMapCanvas" class="radar-map-canvas w-100 flex-grow-1"></div>
                <div id="radarMapLegend" class="d-flex flex-wrap justify-content-center gap-3 mt-4"></div>
                <div id="radarMapEmpty" class="text-center text-secondary py-5 d-none">
                    <?= icon('fa-map-location-dot', 'fa-2x mb-3 d-block') ?>
                    <p class="mb-1">Ainda não há planta para este radar.</p>
                    <small>Sincronize para a ir buscar à cloud do fabricante.</small>
                </div>
            </div>
        </div>
    </div>
    <div class="col-12 col-xl-4 d-flex flex-column gap-4">
        <div id="radarSleepState" class="radar-sleep-state rounded-3 px-3 py-2 d-flex align-items-center justify-content-center gap-3 flex-shrink-0 text-bg-secondary">
            <i class="fa-solid fa-circle-question fa-lg" id="radarSleepStateIcon" aria-hidden="true"></i>
            <span class="h5 mb-0 fw-semibold" id="radarSleepStateLabel">Sem leitura do sono</span>
        </div>
        <?php $vitalCard('radarHeartRate', 'fa-heart', 'Frequência cardíaca', 'bpm', 'danger'); ?>
        <?php $vitalCard('radarBreathRate', 'fa-lungs', 'Frequência respiratória', 'rpm', 'primary'); ?>
    </div>
</div>
<?php
$body = (string) ob_get_clean();

$header = '<div class="d-flex flex-column min-w-0 flex-fill">'
    . '<h5 class="modal-title mb-0" id="radarMapModalLabel">Planta da divisão</h5>'
    . '<small class="text-secondary" id="radarMapSubtitle"></small>'
    . '</div>';

render_modal(
    id: 'radarMapModal',
    body: $body,
    footer: '<button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Fechar</button>',
    size: 'xl',
    fullscreenBelow: 'lg',
    scrollable: true,
    headerHtml: $header,
    footerClass: 'd-none d-lg-flex',
);
