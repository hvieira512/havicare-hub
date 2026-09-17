<?php

/* A planta da divisão de um radar e os sinais vitais ao lado dela, como no hitCare.
 *
 * Dois cartões: a monitorização de trajetos com o «Sincronizar» no cabeçalho dela -- é uma
 * acção sobre a planta e não sobre o modal --, e os sinais vitais à direita.
 *
 * `fullscreenBelow: 'md'` porque num telemóvel uma planta espremida a 300 px não se lê: abaixo
 * dessa largura ocupa o ecrã todo, e os dois cartões empilham-se. */


/** Um sinal vital: o cabeçalho com o valor de agora, o gráfico, e o resumo da janela dele. */

$vitalCard = static function (string $prefix, string $icon, string $label, string $unit, string $tone): void { ?>
    <?php
    /* O cabeçalho é o do mosaico de telemetria que o hub desenha em todos os outros ecrãs --
     * o azulejo do ícone, a etiqueta em versaletes, o valor no tom da categoria. */
    /* Só o ícone e o número: três pastilhas com «Mín», «Média» e «Máx» escritos gastavam
     * metade da linha do cabeçalho a repetir o que a seta já diz. O nome fica na tooltip,
     * que é também o que dá o significado a quem não decifre a seta. */
    $stat = static function (string $id, string $icon, string $label): void { ?>
        <span class="badge rounded-pill bg-body-tertiary text-body-secondary fw-normal d-inline-flex align-items-center gap-1" data-bs-toggle="tooltip" title="<?= h($label) ?>">
            <?= icon($icon, 'opacity-75') ?><span id="<?= h($id) ?>" class="tabular-nums">--</span>
        </span>
    <?php };
    ?>
    <?php /* O cartão cresce com a coluna e é o gráfico que absorve o que sobra: assim os dois
             acompanham a altura da planta ao lado em vez de deixarem um vazio por baixo. */ ?>
    <?php /* A moldura no tom da categoria, na versão subtil: o cartão inteiro passa a ser da
             cor do sinal, sem que um vermelho cheio à volta dele leia como alarme. */ ?>
    <div class="border border-<?= h($tone) ?>-subtle rounded-3 overflow-hidden p-3 flex-grow-1 d-flex flex-column">
        <?php /* O resumo da janela fica no canto oposto ao ícone, e não por baixo do gráfico:
                é informação do cabeçalho -- do mesmo nível da leitura de agora -- e ali não
                empurra o gráfico para cima nem fica pendurada no fundo do cartão. */ ?>
        <div class="d-flex align-items-start justify-content-between flex-wrap gap-2">
            <div class="d-flex align-items-center gap-3 min-w-0">
                <span class="radar-vital-icon d-inline-flex align-items-center justify-content-center rounded-3 flex-shrink-0 bg-<?= h($tone) ?>-subtle text-<?= h($tone) ?>"><?= icon($icon) ?></span>
                <div class="min-w-0">
                    <div class="telemetry-card-title text-uppercase fw-normal lh-sm text-<?= h($tone) ?>"><?= h($label) ?></div>
                    <div class="h4 mb-0 fw-semibold lh-sm tabular-nums text-<?= h($tone) ?>"><span id="<?= h($prefix) ?>Value">--</span> <?= h($unit) ?></div>
                </div>
            </div>
            <?php /* Com o ícone a dizer o que cada uma é: três números iguais em linha obrigam
                    a ler as etiquetas para os distinguir. */ ?>
            <div class="d-flex flex-wrap gap-2">
                <?php $stat($prefix . 'Min', 'fa-arrow-down', 'Mínimo da janela'); ?>
                <?php $stat($prefix . 'Avg', 'fa-equals', 'Média da janela'); ?>
                <?php $stat($prefix . 'Max', 'fa-arrow-up', 'Máximo da janela'); ?>
            </div>
        </div>
        <?php /* O amCharts mede o contentor ao montar: sem altura declarada o gráfico nasce
                com zero e fica invisível. O mínimo está no `.radar-vital-chart`. */ ?>
        <?php /* O `mb-n3` come o `p-3` do cartão por baixo: a área do gráfico encosta ao
                fundo, e o `overflow-hidden` do cartão é que lhe corta os cantos. */ ?>
        <div id="<?= h($prefix) ?>Chart" class="radar-vital-chart w-100 flex-grow-1 mt-3 mb-n3"></div>
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
                <?php /* O Konva mede o contentor ao montar a tela, e por isso ele tem de ter
                        altura antes de a cena existir -- sem o `min-height` do
                        `.radar-map-canvas` a planta nasce com zero. */ ?>
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
    <?php /* Sem cartão nem cabeçalho à volta: a faixa do sono e os dois gráficos já se lêem
             como três blocos, e mais uma moldura por fora deles era uma caixa dentro da
             caixa do modal. */ ?>
    <div class="col-12 col-xl-4 d-flex flex-column gap-4">
        <?php /* O estado do sono é contexto para os dois gráficos, e não a leitura principal
                do ecrã: por isso a faixa é baixa, não cresce com a coluna, e diz só o estado
                -- «Sono leve» já se explica sozinho, e «· Estado do sono» ao lado era a
                etiqueta a repetir o valor. */ ?>
        <?php /* A disposição fica aqui e o JavaScript só troca o `text-bg-*`: era ele a
                reescrever a lista de classes inteira, e qualquer ajuste de espaçamento tinha
                de ser feito nos dois sítios ao mesmo tempo. */ ?>
        <div id="radarSleepState" class="radar-sleep-state rounded-3 px-3 py-2 d-flex align-items-center justify-content-center gap-3 flex-shrink-0 text-bg-secondary">
            <?php /* O ícone muda com o estado, e por isso tem `id`: a lua não pode servir o
                    acordado nem valer ao mesmo tempo para o sono leve e o profundo. */ ?>
            <i class="fa-solid fa-circle-question fa-lg" id="radarSleepStateIcon" aria-hidden="true"></i>
            <span class="h5 mb-0 fw-semibold" id="radarSleepStateLabel">Sem leitura do sono</span>
        </div>
        <?php /* A unidade em minúsculas, como no resto da dashboard: «69 bpm» e não «69 BPM». */ ?>
        <?php $vitalCard('radarHeartRate', 'fa-heart', 'Frequência cardíaca', 'bpm', 'danger'); ?>
        <?php $vitalCard('radarBreathRate', 'fa-lungs', 'Frequência respiratória', 'rpm', 'primary'); ?>
    </div>
</div>
<?php
$body = (string) ob_get_clean();

$header = '<div class="d-flex flex-column min-w-0 flex-fill">'
    . '<h5 class="modal-title mb-0" id="radarMapTitle">Planta da divisão</h5>'
    . '<small class="text-secondary" id="radarMapSubtitle"></small>'
    . '</div>';

render_modal(
    id: 'radarMapModal',
    title: 'Planta da divisão',
    body: $body,
    footer: '<button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Fechar</button>',
    size: 'xl',
    fullscreenBelow: 'md',
    scrollable: true,
    headerHtml: $header,
);
