<?php

declare(strict_types=1);

ob_start();
?>
<form id="modelForm" class="row g-4 align-items-stretch mb-4">
    <div class="col-lg-5">
        <?= showcase_preview('modelPreview') ?>
    </div>
    <div class="col-lg-7">
        <div class="vstack gap-3 h-100">
            <div>
                <div class="form-label">Fornecedor</div>
                <div id="modelSupplierButtons" class="btn-group flex-wrap" role="group"></div>
            </div>
            <div class="row g-3">
                <div class="col-md-6">
                    <label for="modelInternalModel" class="form-label">Modelo interno</label>
                    <input type="text" class="form-control" id="modelInternalModel" placeholder="Identificador interno" required>
                </div>
                <div class="col-md-6">
                    <label for="modelCommercialName" class="form-label">Nome comercial</label>
                    <input type="text" class="form-control" id="modelCommercialName" placeholder="Nome visível" required>
                </div>
            </div>
            <div>
                <div class="form-label">Tipo de dispositivo</div>
                <div id="modelDeviceTypeButtons" class="btn-group flex-wrap" role="group"></div>
            </div>
            <div>
                <label for="modelImage" class="form-label">Imagem</label>
                <input type="file" class="form-control" id="modelImage" accept="image/*">
            </div>
            <div>
                <div class="d-flex justify-content-between align-items-center mb-2">
                    <div class="form-label mb-0">Pedidos disponíveis</div>
                    <span id="modelRequestSummary" class="small text-secondary"></span>
                </div>
                <div id="modelRequestCapabilities" class="border rounded bg-body-tertiary p-3 vstack gap-2"></div>
            </div>
            <div class="d-flex justify-content-end gap-2 mt-auto">
                <button id="resetModelBtn" type="button" class="btn btn-outline-secondary">Cancelar</button>
                <button id="saveModelBtn" type="button" class="btn btn-primary"><?= icon('fa-floppy-disk', 'me-1') ?>Guardar</button>
            </div>
        </div>
    </div>
</form>
<div class="table-responsive">
    <table class="table table-sm align-middle">
        <thead><tr><th>Imagem</th><th>Fornecedor</th><th>Nome comercial</th><th>Modelo interno</th><th>Tipo</th><th></th></tr></thead>
        <tbody id="modelListBody"></tbody>
    </table>
</div>
<?php
$body = (string) ob_get_clean();

ob_start();
?>
<button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Fechar</button>
<?php
$footer = (string) ob_get_clean();

render_modal('modelModal', 'Modelos', $body, $footer, 'modal-xl');
