<?php

/* `d-flex` e `d-none` juntas nas Configurações: o `d-none` do Bootstrap vem depois na folha e
 * ganha enquanto lá estiver; o JS que a tira deixa o `d-flex` a valer, e sem ele o botão
 * voltava a `inline-block` e o ícone descolava do texto. */
$deviceTabs = [
    ['key' => 'General', 'label' => 'Geral', 'icon' => 'fa-address-card', 'extra' => ''],
    ['key' => 'Config', 'label' => 'Configurações', 'icon' => 'fa-sliders', 'extra' => ' d-none'],
];

ob_start();
?>
<div class="device-modal-shell h-100">
    <div class="row g-4 h-100">
        <div class="col-12 col-lg-2 d-flex align-items-lg-center">
            <div class="nav nav-pills flex-row flex-lg-column flex-nowrap gap-2 w-100" id="deviceModalNav" role="tablist">
                <?php foreach ($deviceTabs as $index => $tab) : ?>
                    <?php $pane = 'device' . $tab['key'] . 'Pane'; ?>
                <button class="nav-link<?= $index === 0 ? ' active' : '' ?> text-start d-flex<?= $tab['extra'] ?> align-items-center gap-2" id="device<?= $tab['key'] ?>TabBtn" data-bs-toggle="pill" data-bs-target="#<?= $pane ?>" type="button" role="tab" aria-controls="<?= $pane ?>" aria-selected="<?= $index === 0 ? 'true' : 'false' ?>"><?= icon($tab['icon'], 'fa-fw') ?><?= h($tab['label']) ?></button>
                <?php endforeach; ?>
            </div>
        </div>
        <div class="col-12 col-lg-10 h-100 overflow-auto">
            <div class="tab-content">
                <div class="tab-pane fade show active" id="deviceGeneralPane" role="tabpanel" aria-labelledby="deviceGeneralTabBtn">
                    <form id="deviceForm" class="row g-4">
                        <div class="col-lg-8 order-lg-1">
                            <div class="d-flex flex-column gap-4">
                                <div class="wizard-trail d-flex align-items-center justify-content-center flex-wrap gap-2 border-bottom" id="deviceTrail" role="progressbar" aria-valuemin="1" aria-valuemax="2" aria-valuenow="2"></div>

                                <div class="wizard-ask" id="deviceStep1">
                                    <div data-device-question="type">
                                        <label class="form-label-sm">Tipo de dispositivo</label>
                                        <div id="deviceTypeButtons" role="group"></div>
                                    </div>
                                    <div data-device-question="model">
                                        <label class="form-label-sm">Fornecedor</label>
                                        <div id="deviceSupplierButtons" role="group"></div>
                                        <label class="form-label-sm mt-3">Modelo</label>
                                        <div id="deviceModelButtons" role="group"></div>
                                    </div>
                                    <div data-device-question="owner">
                                        <label class="form-label-sm">Licença</label>
                                        <div id="deviceLicensePicker"></div>
                                    </div>
                                    <p data-device-question="none" class="text-secondary small mb-0">Toque numa etiqueta acima para alterar uma resposta.</p>
                                </div>

                                <?php /* Duas colunas na base de dados, uma só escolha no ecrã. */ ?>
                                <input type="hidden" id="deviceCompany" value="">
                                <input type="hidden" id="deviceLicenseId" value="0">

                                <div class="wizard-ask" id="deviceStep2">
                                    <div id="deviceDeviceIdRow" class="d-none">
                                        <label for="deviceDeviceId" class="form-label-sm" id="deviceDeviceIdLabel">ID do dispositivo</label>
                                        <input type="text" class="form-control" id="deviceDeviceId" placeholder="ID do dispositivo no protocolo">
                                        <div class="form-text" id="deviceDeviceIdHelp">Identificador do dispositivo no protocolo (IMEI, MAC, etc.).</div>
                                    </div>
                                    <div id="deviceImeiRow">
                                        <label for="deviceImei" class="form-label-sm">IMEI</label>
                                        <input type="text" class="form-control" id="deviceImei" required>
                                    </div>
                                    <div id="deviceSimRow">
                                        <label class="form-label-sm">Número do SIM</label>
                                        <div id="deviceSimNumberRoot"></div>
                                    </div>
                                    <div id="deviceGatewayLinksRow" class="d-none">
                                        <div class="d-flex justify-content-between align-items-center gap-2">
                                            <span class="form-label-sm mb-0">Gateways autorizados</span>
                                            <span class="badge text-bg-primary rounded-pill" id="deviceGatewayLinksCount">0</span>
                                        </div>
                                        <div class="gateway-picker d-grid gap-2 overflow-y-auto mt-1" id="deviceGatewayLinksList" role="group" aria-label="Gateways autorizados" aria-describedby="deviceGatewayLinksHelp"></div>
                                        <div class="d-flex gap-2 mt-2">
                                            <button type="button" class="btn btn-sm btn-outline-primary" id="deviceGatewayLinksSelectAllBtn">Selecionar todos</button>
                                            <button type="button" class="btn btn-sm btn-outline-secondary" id="deviceGatewayLinksClearBtn">Limpar</button>
                                        </div>
                                        <div class="form-text" id="deviceGatewayLinksHelp">Selecione os gateways autorizados a reportar dados deste sensor.</div>
                                    </div>
                                </div>

                                <div id="deviceFormError" class="small text-danger d-none"></div>
                                <?php /* Guardar e Eliminar vivem aqui e não no rodapé: gravam o
                                        que está neste separador, e nas Configurações cada bloco
                                        tem o seu «Enviar». O `me-auto` afasta o destrutivo. */ ?>
                                <div class="d-flex align-items-center gap-2">
                                    <button type="button" class="btn btn-outline-danger d-none me-auto" id="deleteDeviceBtn"><?= icon('fa-trash', 'me-1') ?>Eliminar</button>
                                    <button type="button" class="btn btn-outline-secondary d-none ms-auto" id="deviceNextBtn"><?= icon('fa-arrow-left', 'me-2') ?>Manter o que estava</button>
                                    <button id="saveDeviceBtn" type="button" class="btn btn-primary">Guardar dispositivo</button>
                                </div>
                            </div>
                        </div>
                        <div class="col-lg-4 order-lg-2">
                            <?= showcase_preview('devicePreview') ?>
                        </div>
                    </form>
                </div>
                <div class="tab-pane fade" id="deviceConfigPane" role="tabpanel" aria-labelledby="deviceConfigTabBtn">
                    <div id="deviceConfigRoot"></div>
                </div>
            </div>
        </div>
    </div>
</div>
<?php
$body = (string) ob_get_clean();

$footer = '<button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Fechar</button>';

$header = '<div class="modal-device-identity d-flex align-items-center min-w-0 flex-fill" id="deviceModalIdentity">'
    . '<h5 class="modal-title mb-0" id="deviceModalLabel">Editar dispositivo</h5>'
    . '</div>';

render_modal(
    id: 'deviceModal',
    title: 'Editar dispositivo',
    body: $body,
    footer: $footer,
    size: 'xl',
    fullscreenBelow: 'md',
    scrollable: true,
    headerHtml: $header,
    // O rolamento passa do corpo para a coluna do conteúdo, e o `h-100` faz a caixa medir
    // sempre a altura toda em vez da altura do separador aberto.
    bodyClass: 'overflow-hidden',
    contentClass: 'h-100',
);
