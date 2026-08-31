<?php

declare(strict_types=1);

ob_start();
?>
<div class="device-modal-shell">
    <div class="row g-4">
        <div class="col-12 col-lg-2">
            <div class="nav nav-pills flex-row flex-lg-column flex-nowrap gap-2 w-100" id="deviceModalNav" role="tablist">
                <button class="nav-link active text-start" id="deviceGeneralTabBtn" data-bs-toggle="pill" data-bs-target="#deviceGeneralPane" type="button" role="tab" aria-controls="deviceGeneralPane" aria-selected="true">
                    Geral
                </button>
                <button class="nav-link text-start d-none" id="deviceConfigTabBtn" data-bs-toggle="pill" data-bs-target="#deviceConfigPane" type="button" role="tab" aria-controls="deviceConfigPane" aria-selected="false">
                    Configurações
                </button>
            </div>
        </div>
        <div class="col-12 col-lg-10">
            <div class="tab-content">
                <div class="tab-pane fade show active" id="deviceGeneralPane" role="tabpanel" aria-labelledby="deviceGeneralTabBtn">
                    <form id="deviceForm" class="row g-4">
                        <div class="col-lg-8 order-lg-1">
                            <div class="d-flex flex-column gap-4">
                                <?php /* A mesma trilha do assistente de adicionar: as respostas ja dadas
                                       * em etiquetas, e cada uma um botao para voltar aquela pergunta. */ ?>
                                <div class="wizard-trail" id="deviceTrail" role="progressbar" aria-valuemin="1" aria-valuemax="2" aria-valuenow="2"></div>

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

                                <?php /* A empresa e a licença são duas colunas na base de dados, mas uma
                                       * só escolha no ecrã: a árvore escreve as duas aqui. */ ?>
                                <input type="hidden" id="deviceCompany" value="">
                                <input type="hidden" id="deviceLicenseId" value="0">

                                <div class="wizard-ask" id="deviceStep2">
                                    <div id="deviceDeviceIdRow" class="d-none">
                                        <label for="deviceDeviceId" class="form-label-sm" id="deviceDeviceIdLabel">Device ID</label>
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
                                        <div class="gateway-picker mt-1" id="deviceGatewayLinksList" role="group" aria-label="Gateways autorizados" aria-describedby="deviceGatewayLinksHelp"></div>
                                        <div class="d-flex gap-2 mt-2">
                                            <button type="button" class="btn btn-sm btn-outline-primary" id="deviceGatewayLinksSelectAllBtn">Selecionar todos</button>
                                            <button type="button" class="btn btn-sm btn-outline-secondary" id="deviceGatewayLinksClearBtn">Limpar</button>
                                        </div>
                                        <div class="form-text" id="deviceGatewayLinksHelp">Selecione os gateways autorizados a reportar dados deste sensor.</div>
                                    </div>
                                </div>

                                <div id="deviceFormError" class="small text-danger d-none"></div>
                                <div class="d-flex justify-content-end gap-2">
                                    <button type="button" class="btn btn-outline-secondary d-none" id="deviceBackBtn"><?= icon('fa-arrow-left', 'me-2') ?>Anterior</button>
                                    <button type="button" class="btn btn-primary d-none" id="deviceNextBtn">Seguinte<?= icon('fa-arrow-right', 'ms-2') ?></button>
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

$footer = '<button type="button" class="btn btn-outline-danger d-none" id="deleteDeviceBtn">' . icon('fa-trash', 'me-1') . 'Eliminar</button><button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Fechar</button>';

$header = '<div class="modal-device-identity" id="deviceModalIdentity">'
    . '<h5 class="modal-title mb-0" id="deviceModalLabel">Editar dispositivo</h5>'
    . '</div>';

render_modal('deviceModal', 'Editar dispositivo', $body, $footer, 'modal-xl modal-fullscreen-md-down modal-dialog-scrollable', $header);
