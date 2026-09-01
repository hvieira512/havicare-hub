<?php

declare(strict_types=1);

ob_start();
?>
<?php /* Quem rola é a coluna do conteúdo, e não o corpo inteiro: com o corpo a rolar, a
       * coluna das abas esticava até à altura do conteúdo e o `align-self` centrava-as
       * fora do ecrã. */ ?>
<div class="device-modal-shell h-100">
    <div class="row g-4 h-100">
        <div class="col-12 col-lg-2 align-self-lg-center">
            <div class="nav nav-pills flex-row flex-lg-column flex-nowrap gap-2 w-100" id="deviceModalNav" role="tablist">
                <button class="nav-link active text-start d-flex align-items-center gap-2" id="deviceGeneralTabBtn" data-bs-toggle="pill" data-bs-target="#deviceGeneralPane" type="button" role="tab" aria-controls="deviceGeneralPane" aria-selected="true">
                    <?= icon('fa-address-card', 'fa-fw') ?>Geral
                </button>
                <?php /* `fa-sliders` e não `fa-gear`: o `fa-gear` é o ícone da secção "Sistema",
                       * que é um dos separadores lá dentro. O mesmo ícone para o todo e para
                       * uma das partes lia-se como sendo a mesma coisa. */ ?>
                <?php /* `d-flex` e `d-none` juntas: o `d-none` do Bootstrap vem depois na folha
                       * e ganha enquanto lá estiver, e o JS que a tira deixa o `d-flex` a
                       * valer -- sem ele o botão voltava a `inline-block` e o ícone descolava
                       * do texto. */ ?>
                <button class="nav-link text-start d-flex d-none align-items-center gap-2" id="deviceConfigTabBtn" data-bs-toggle="pill" data-bs-target="#deviceConfigPane" type="button" role="tab" aria-controls="deviceConfigPane" aria-selected="false">
                    <?= icon('fa-sliders', 'fa-fw') ?>Configurações
                </button>
            </div>
        </div>
        <div class="col-12 col-lg-10 h-100 overflow-auto">
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
                                <?php /* Só a saída da pergunta de classificação: guardar e eliminar
                                       * estão no rodapé, ao lado do fechar. */ ?>
                                <div class="d-flex justify-content-end gap-2">
                                    <button type="button" class="btn btn-outline-secondary d-none" id="deviceNextBtn"><?= icon('fa-arrow-left', 'me-2') ?>Manter o que estava</button>
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

// Uma zona de acções só. O `me-auto` empurra o destrutivo para longe das outras duas, que é
// o que o separa sem lhe dar uma cor que grite.
$footer = '<button type="button" class="btn btn-outline-danger d-none me-auto" id="deleteDeviceBtn">'
    . icon('fa-trash', 'me-1') . 'Eliminar</button>'
    . '<button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Fechar</button>'
    . '<button id="saveDeviceBtn" type="button" class="btn btn-primary">Guardar dispositivo</button>';

$header = '<div class="modal-device-identity" id="deviceModalIdentity">'
    . '<h5 class="modal-title mb-0" id="deviceModalLabel">Editar dispositivo</h5>'
    . '</div>';

// `h-100` no conteúdo para a altura ser a do ecrã e não a do separador aberto, senão a caixa
// mudava de tamanho a cada troca. `overflow-hidden` no corpo para o rolamento que o
// `modal-dialog-scrollable` lhe põe se transferir para a coluna do conteúdo.
render_modal('deviceModal', 'Editar dispositivo', $body, $footer, 'modal-xl modal-fullscreen-md-down modal-dialog-scrollable', $header, 'overflow-hidden', 'h-100');
