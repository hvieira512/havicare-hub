<?php

declare(strict_types=1);

function h(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function icon(string $name, string $class = ''): string
{
    return '<i class="fa-solid ' . h($name) . ($class !== '' ? ' ' . h($class) : '') . '"></i>';
}

function section_header(string $title, ?string $counterId = null): string
{
    return '<div class="d-flex justify-content-between align-items-center mb-2">'
        . '<h2 class="h6 mb-0">' . h($title) . '</h2>'
        . ($counterId !== null ? '<span class="small text-secondary" id="' . h($counterId) . '"></span>' : '')
        . '</div>';
}

function modal_shell(string $id, string $title, string $body, string $footer, string $size = ''): string
{
    $dialogClass = trim('modal-dialog modal-dialog-centered ' . $size);

    return <<<HTML
        <div class="modal fade" id="{$id}" tabindex="-1">
            <div class="{$dialogClass}">
                <div class="modal-content">
                    <div class="modal-header">
                        <h5 class="modal-title" id="{$id}Label">{$title}</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body">
                        {$body}
                    </div>
                    <div class="modal-footer">
                        {$footer}
                    </div>
                </div>
            </div>
        </div>
HTML;
}

function showcase_preview(string $id): string
{
    return '<div id="' . h($id) . '" class="showcase-preview border rounded bg-body-tertiary d-flex align-items-center justify-content-center p-4"></div>';
}

function empty_panel(string $text): string
{
    return '<div class="text-secondary border rounded bg-body-tertiary p-3">' . h($text) . '</div>';
}

function dashboard_device_modal(): string
{
    $body = <<<HTML
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
                                {preview}
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
HTML;
    $body = str_replace('{preview}', showcase_preview('devicePreview'), $body);
    $footer = '<button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>'
        . '<button id="saveDeviceBtn" type="button" class="btn btn-primary">Guardar dispositivo</button>';

    return modal_shell('deviceModal', 'Dispositivo', $body, $footer, 'modal-xl modal-fullscreen-sm-down');
}

function dashboard_supplier_modal(): string
{
    $body = <<<HTML
        <form id="supplierForm" class="row g-2 mb-3 p-2 border rounded bg-body-tertiary">
            <div class="col-md-8">
                <input type="text" class="form-control form-control-sm" id="supplierName" placeholder="Nome do fornecedor" required>
            </div>
            <div class="col-md-4">
                <button id="saveSupplierBtn" type="button" class="btn btn-primary btn-sm w-100">{$GLOBALS['plusIcon']}Adicionar</button>
            </div>
        </form>
        <table class="table table-sm">
            <thead><tr><th>Nome</th><th>Modelos</th><th>Estado</th><th></th></tr></thead>
            <tbody id="supplierListBody"></tbody>
        </table>
HTML;
    $footer = '<button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Fechar</button>';

    return modal_shell('supplierModal', 'Fornecedores', $body, $footer);
}

function dashboard_model_modal(): string
{
    $body = <<<HTML
        <form id="modelForm" class="row g-4 align-items-stretch mb-4">
            <div class="col-lg-5">
                {preview}
            </div>
            <div class="col-lg-7">
                <div class="vstack gap-3 h-100">
                    <div>
                        <div class="form-label">Fornecedor</div>
                        <div id="modelSupplierButtons" class="btn-group flex-wrap" role="group"></div>
                    </div>
                    <div>
                        <label for="modelModel" class="form-label">Modelo</label>
                        <input type="text" class="form-control" id="modelModel" placeholder="Modelo" required>
                    </div>
                    <div>
                        <div class="form-label">Protocolo</div>
                        <div id="modelProtocolText" class="border rounded bg-body-tertiary px-3 py-2 text-secondary">-</div>
                    </div>
                    <div>
                        <label for="modelImage" class="form-label">Imagem</label>
                        <input type="file" class="form-control" id="modelImage" accept="image/*">
                    </div>
                    <div class="d-flex justify-content-end gap-2 mt-auto">
                        <button id="resetModelBtn" type="button" class="btn btn-outline-secondary">Cancelar</button>
                        <button id="saveModelBtn" type="button" class="btn btn-primary">{$GLOBALS['saveIcon']}Guardar</button>
                    </div>
                </div>
            </div>
        </form>
        <div class="table-responsive">
            <table class="table table-sm align-middle">
                <thead><tr><th>Imagem</th><th>Fornecedor</th><th>Modelo</th><th>Protocolo</th><th></th></tr></thead>
                <tbody id="modelListBody"></tbody>
            </table>
        </div>
HTML;
    $body = str_replace('{preview}', showcase_preview('modelPreview'), $body);
    $footer = '<button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Fechar</button>';

    return modal_shell('modelModal', 'Modelos', $body, $footer, 'modal-xl');
}

$GLOBALS['plusIcon'] = icon('fa-plus', 'me-1');
$GLOBALS['saveIcon'] = icon('fa-floppy-disk', 'me-1');
