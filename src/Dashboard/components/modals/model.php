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
                <button id="saveModelBtn" type="button" class="btn btn-primary"><?= icon('fa-floppy-disk', 'me-1') ?>Guardar</button>
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
<?php
$body = (string) ob_get_clean();

ob_start();
?>
<button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Fechar</button>
<?php
$footer = (string) ob_get_clean();

render_modal('modelModal', 'Modelos', $body, $footer, 'modal-xl');

