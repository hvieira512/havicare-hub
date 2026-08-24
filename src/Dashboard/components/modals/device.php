<?php

declare(strict_types=1);

ob_start();
?>
<?php
/**
 * Os separadores ficam colados ao conteúdo que comandam, sob o cabeçalho.
 *
 * Eram pills verticais numa coluna de duas unidades, alinhados ao centro vertical de um
 * modal de ecrã inteiro — longe do cabeçalho e a meia altura do nada. O ecrã inteiro
 * também saiu: um formulário de 400px centrado em 900px de janela deixava metade do ecrã
 * em branco e o rodapé a 400px do último campo.
 */
?>
<div class="device-modal-shell">
    <div class="nav nav-underline mb-4" id="deviceModalNav" role="tablist">
        <button class="nav-link active" id="deviceGeneralTabBtn" data-bs-toggle="pill" data-bs-target="#deviceGeneralPane" type="button" role="tab" aria-controls="deviceGeneralPane" aria-selected="true">
            Geral
        </button>
        <button class="nav-link d-none" id="deviceConfigTabBtn" data-bs-toggle="pill" data-bs-target="#deviceConfigPane" type="button" role="tab" aria-controls="deviceConfigPane" aria-selected="false">
            Configurações
        </button>
    </div>
    <div>
        <div>
            <div class="tab-content">
                <div class="tab-pane fade show active" id="deviceGeneralPane" role="tabpanel" aria-labelledby="deviceGeneralTabBtn">
                    <form id="deviceForm" class="row g-4">
                        <div class="col-lg-8 order-lg-1">
                            <div class="vstack gap-3">
                                <div>
                                    <div class="form-label">Tipo de dispositivo</div>
                                    <div id="deviceTypeButtons" class="btn-group flex-wrap" role="group"></div>
                                </div>
                                <div>
                                    <div class="form-label">Fornecedor</div>
                                    <div id="deviceSupplierButtons" class="btn-group flex-wrap" role="group"></div>
                                </div>
                                <div>
                                    <div class="form-label">Modelo</div>
                                    <div id="deviceModelButtons" class="btn-group flex-wrap" role="group"></div>
                                </div>
                                <div id="deviceDeviceIdRow" class="d-none">
                                    <label for="deviceDeviceId" class="form-label" id="deviceDeviceIdLabel">Device ID</label>
                                    <input type="text" class="form-control" id="deviceDeviceId" placeholder="ID do dispositivo no protocolo">
                                    <div class="form-text" id="deviceDeviceIdHelp">Identificador do dispositivo no protocolo (IMEI, MAC, etc.).</div>
                                </div>
                                <div class="row g-3">
                                    <div class="col-12 col-md-6">
                                        <div>
                                            <label for="deviceCompanySelect" class="form-label">Empresa</label>
                                            <select class="form-select" id="deviceCompanySelect">
                                                <option value="">Sem empresa</option>
                                            </select>
                                        </div>
                                        <input type="hidden" id="deviceCompany" value="">
                                    </div>
                                    <div class="col-12 col-md-6">
                                        <div>
                                            <label for="deviceLicenseSelect" class="form-label">Licença</label>
                                            <select class="form-select" id="deviceLicenseSelect" disabled>
                                                <option value="0">Nenhuma</option>
                                            </select>
                                        </div>
                                        <input type="hidden" id="deviceLicenseId" value="0">
                                    </div>
                                </div>
                                <div id="deviceGatewayLinksRow" class="d-none">
                                    <div class="d-flex justify-content-between align-items-center gap-2">
                                        <span class="form-label mb-0">Gateways autorizados</span>
                                        <span class="badge text-bg-primary rounded-pill" id="deviceGatewayLinksCount">0</span>
                                    </div>
                                    <div class="gateway-picker mt-1" id="deviceGatewayLinksList" role="group" aria-label="Gateways autorizados" aria-describedby="deviceGatewayLinksHelp"></div>
                                    <div class="d-flex gap-2 mt-2">
                                        <button type="button" class="btn btn-sm btn-outline-primary" id="deviceGatewayLinksSelectAllBtn">Selecionar todos</button>
                                        <button type="button" class="btn btn-sm btn-outline-secondary" id="deviceGatewayLinksClearBtn">Limpar</button>
                                    </div>
                                    <div class="form-text" id="deviceGatewayLinksHelp">Selecione os gateways autorizados a reportar dados deste sensor.</div>
                                </div>
                                <div id="deviceImeiRow">
                                    <label for="deviceImei" class="form-label">IMEI</label>
                                    <input type="text" class="form-control" id="deviceImei" required>
                                </div>
                                <div id="deviceSimRow">
                                    <label class="form-label">Número do SIM</label>
                                    <div id="deviceSimNumberRoot"></div>
                                </div>
                                <div id="deviceFormError" class="small text-danger d-none"></div>
                                <div class="d-flex justify-content-end">
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

$footer = '<button type="button" class="btn btn-outline-danger d-none" id="deleteDeviceBtn"><i class="fa-solid fa-trash me-1"></i>Eliminar</button><button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Fechar</button>';

// A identidade do dispositivo vive no cabeçalho e é escrita pelo JavaScript, porque só
// depois de carregar é que se sabe o modelo e se está ligado.
$header = '<div class="modal-device-identity" id="deviceModalIdentity">'
    . '<h5 class="modal-title mb-0" id="deviceModalLabel">Editar dispositivo</h5>'
    . '</div>';

render_modal('deviceModal', 'Editar dispositivo', $body, $footer, 'modal-xl modal-dialog-scrollable', $header);
