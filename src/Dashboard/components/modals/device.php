<?php

$deviceTabs = [
    ['key' => 'General', 'label' => 'Geral', 'extra' => ''],
    ['key' => 'Config', 'label' => 'Configurações', 'extra' => ' d-none'],
];

ob_start();
?>
<div class="modal-device-identity d-flex align-items-center min-w-0" id="deviceModalIdentity">
    <h5 class="modal-title mb-0" id="deviceModalLabel">Editar dispositivo</h5>
</div>
<div class="nav device-modal-tabs d-flex flex-row flex-nowrap" role="tablist">
    <?php foreach ($deviceTabs as $index => $tab) : ?>
        <?php $pane = 'device' . $tab['key'] . 'Pane'; ?>
    <button class="nav-link<?= $index === 0 ? ' active' : '' ?> d-flex<?= $tab['extra'] ?> align-items-center gap-2" id="device<?= $tab['key'] ?>TabBtn" data-bs-toggle="pill" data-bs-target="#<?= $pane ?>" type="button" role="tab" aria-controls="<?= $pane ?>" aria-selected="<?= $index === 0 ? 'true' : 'false' ?>"><?= h($tab['label']) ?><?= $tab['key'] === 'Config' ? '<span class="device-modal-tab-count d-none flex-shrink-0 fw-semibold tabular-nums" id="deviceConfigCount"></span>' : '' ?></button>
    <?php endforeach; ?>
</div>
<?php
$header = (string) ob_get_clean();

ob_start();
?>
<div class="device-modal-shell h-100">
    <div class="row g-0 h-100">
        <div class="col-12 device-modal-content">
            <div class="tab-content h-100">
                <div class="tab-pane fade show active h-100 p-3 p-lg-0" id="deviceGeneralPane" role="tabpanel" aria-labelledby="deviceGeneralTabBtn">
                    <form id="deviceForm" class="row g-4">
                        <div class="col-lg-8 order-lg-1">
                            <div class="d-flex flex-column gap-4">
                                <div class="vstack" id="deviceTrail"></div>

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

                                <input type="hidden" id="deviceCompany" value="">
                                <input type="hidden" id="deviceLicenseId" value="0">

                                <div class="wizard-ask" id="deviceStep2">
                                    <div class="row g-3">
                                        <div id="deviceDeviceIdRow" class="col-md-6 d-none">
                                            <label for="deviceDeviceId" class="form-label-sm" id="deviceDeviceIdLabel">ID do dispositivo</label>
                                            <input type="text" class="form-control" id="deviceDeviceId">
                                            <div class="form-text" id="deviceDeviceIdHelp">Identificador do dispositivo no protocolo (IMEI, MAC, etc.).</div>
                                        </div>
                                        <div id="deviceImeiRow" class="col-md-6">
                                            <label for="deviceImei" class="form-label-sm">IMEI</label>
                                            <input type="text" class="form-control" id="deviceImei" required>
                                        </div>
                                        <div id="deviceSimRow" class="col-md-6">
                                            <label class="form-label-sm">Número do SIM</label>
                                            <div id="deviceSimNumberRoot"></div>
                                        </div>
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
                                <div class="d-flex align-items-center justify-content-end gap-2">
                                    <button type="button" class="btn btn-outline-secondary d-none" id="deviceNextBtn"><?= icon('fa-arrow-left', 'me-2') ?>Manter o que estava</button>
                                    <button id="saveDeviceBtn" type="button" class="btn btn-primary"><?= icon('fa-floppy-disk', 'me-1') ?>Guardar dispositivo</button>
                                </div>

                                <div class="border border-danger-subtle rounded-3 p-3" id="deviceDangerZone">
                                    <div class="d-flex flex-column flex-sm-row align-items-sm-center gap-3">
                                        <div class="flex-grow-1 min-w-0">
                                            <div class="fw-semibold text-danger-emphasis">Zona perigosa</div>
                                            <p class="small text-secondary mb-0">Eliminar apaga o dispositivo, as configurações e o histórico dele. Não se desfaz.</p>
                                        </div>
                                        <button type="button" class="btn btn-outline-danger d-none flex-shrink-0" id="deleteDeviceBtn"><?= icon('fa-trash', 'me-1') ?>Eliminar dispositivo</button>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <div class="col-lg-4 order-lg-2">
                            <?= showcase_preview('devicePreview') ?>
                        </div>
                    </form>
                </div>
                <div class="tab-pane fade h-100" id="deviceConfigPane" role="tabpanel" aria-labelledby="deviceConfigTabBtn">
                    <div class="h-100" id="deviceConfigRoot"></div>
                </div>
            </div>
        </div>
    </div>
</div>
<?php
$body = (string) ob_get_clean();

$footer = '<button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Fechar</button>';

render_modal(
    id: 'deviceModal',
    body: $body,
    footer: $footer,
    size: 'xl',
    fullscreenBelow: 'lg',
    scrollable: true,
    headerHtml: $header,
    bodyClass: 'overflow-hidden p-0 p-lg-3',
    contentClass: 'h-100',
    footerClass: 'd-none d-lg-flex',
);
