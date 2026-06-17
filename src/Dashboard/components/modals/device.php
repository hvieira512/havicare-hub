<?php

declare(strict_types=1);

ob_start();
?>
<div class="device-modal-shell">
    <div class="row g-4">
        <div class="col-lg-3 d-flex align-items-center justify-content-center justify-content-lg-center">
            <div class="nav nav-pills flex-row flex-lg-column justify-content-center justify-content-lg-start gap-2 w-100" id="deviceModalNav" role="tablist">
                <button class="nav-link active text-start" id="deviceGeneralTabBtn" data-bs-toggle="pill" data-bs-target="#deviceGeneralPane" type="button" role="tab" aria-controls="deviceGeneralPane" aria-selected="true">
                    Geral
                </button>
                <button class="nav-link text-start" id="deviceConfigTabBtn" data-bs-toggle="pill" data-bs-target="#deviceConfigPane" type="button" role="tab" aria-controls="deviceConfigPane" aria-selected="false">
                    Configurações
                </button>
            </div>
        </div>
        <div class="col-lg-9">
            <div class="tab-content">
                <div class="tab-pane fade show active" id="deviceGeneralPane" role="tabpanel" aria-labelledby="deviceGeneralTabBtn">
                    <form id="deviceForm" class="row g-4 align-items-stretch">
                        <div class="col-lg-5">
                            <?= showcase_preview('devicePreview') ?>
                        </div>
                        <div class="col-lg-7">
                            <div class="vstack gap-3 h-100">
                                <div>
                                    <label for="deviceImei" class="form-label">IMEI</label>
                                    <input type="text" class="form-control" id="deviceImei" required>
                                </div>
                                <div>
                                    <label for="deviceSimNumber" class="form-label">Número do SIM</label>
                                    <input type="text" class="form-control" id="deviceSimNumber" placeholder="3519...">
                                </div>
                                <div>
                                    <div class="form-label">Fornecedor</div>
                                    <div id="deviceSupplierButtons" class="btn-group flex-wrap" role="group"></div>
                                </div>
                                <div>
                                    <div class="form-label">Modelo</div>
                                    <div id="deviceModelButtons" class="btn-group flex-wrap" role="group"></div>
                                </div>
                                <div>
                                    <div class="form-label">Protocolo</div>
                                    <div id="deviceProtocolText" class="border rounded bg-body-tertiary px-3 py-2 text-secondary">-</div>
                                </div>
                                <div class="d-flex justify-content-end mt-auto">
                                    <button id="saveDeviceBtn" type="button" class="btn btn-primary">Guardar dispositivo</button>
                                </div>
                            </div>
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

render_modal('deviceModal', 'Dispositivo', $body, $footer, 'modal-xl modal-fullscreen-sm-down');
