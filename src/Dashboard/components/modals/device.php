<?php

declare(strict_types=1);

ob_start();
?>
<div class="device-modal-shell d-flex flex-column h-100">
    <div class="row g-4 h-100 align-items-lg-center">
        <div class="col-lg-2 d-flex align-items-lg-center justify-content-center justify-content-lg-center h-100">
            <div class="nav nav-pills flex-row flex-lg-column justify-content-center justify-content-lg-start gap-2 w-100" id="deviceModalNav" role="tablist">
                <button class="nav-link active text-start" id="deviceGeneralTabBtn" data-bs-toggle="pill" data-bs-target="#deviceGeneralPane" type="button" role="tab" aria-controls="deviceGeneralPane" aria-selected="true">
                    Geral
                </button>
                <button class="nav-link text-start d-none" id="deviceConfigTabBtn" data-bs-toggle="pill" data-bs-target="#deviceConfigPane" type="button" role="tab" aria-controls="deviceConfigPane" aria-selected="false">
                    Configurações
                </button>
            </div>
        </div>
        <div class="col-lg-10 d-flex flex-column h-100">
            <div class="tab-content flex-grow-1">
                <div class="tab-pane fade show active h-100" id="deviceGeneralPane" role="tabpanel" aria-labelledby="deviceGeneralTabBtn">
                    <form id="deviceForm" class="row g-4 align-items-stretch">
                        <div class="col-lg-5">
                            <?= showcase_preview('devicePreview') ?>
                        </div>
                        <div class="col-lg-7">
                            <div class="vstack gap-3 h-100">
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
                                <div id="deviceImeiRow">
                                    <label for="deviceImei" class="form-label">IMEI</label>
                                    <input type="text" class="form-control" id="deviceImei" required>
                                </div>
                                <div id="deviceSimRow">
                                    <label class="form-label">Número do SIM</label>
                                    <div id="deviceSimNumberRoot"></div>
                                </div>
                                <div id="deviceFormError" class="small text-danger d-none"></div>
                                <div class="d-flex justify-content-end mt-auto">
                                    <button id="saveDeviceBtn" type="button" class="btn btn-primary">Guardar dispositivo</button>
                                </div>
                            </div>
                        </div>
                    </form>
                </div>
                <div class="tab-pane fade h-100" id="deviceConfigPane" role="tabpanel" aria-labelledby="deviceConfigTabBtn">
                    <div id="deviceConfigRoot"></div>
                </div>
            </div>
        </div>
    </div>
</div>
<?php
$body = (string) ob_get_clean();

$footer = '<button type="button" class="btn btn-danger d-none" id="deleteDeviceBtn"><i class="fa-solid fa-trash me-1"></i>Eliminar</button><button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Fechar</button>';

render_modal('deviceModal', 'Dispositivo', $body, $footer, 'modal-fullscreen');
