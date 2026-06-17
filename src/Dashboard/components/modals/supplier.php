<?php

declare(strict_types=1);

ob_start();
?>
<form id="supplierForm" class="row g-2 mb-3 p-2 border rounded bg-body-tertiary">
    <div class="col-md-8">
        <input type="text" class="form-control form-control-sm" id="supplierName" placeholder="Nome do fornecedor" required>
    </div>
    <div class="col-md-4">
        <button id="saveSupplierBtn" type="button" class="btn btn-primary btn-sm w-100"><?= icon('fa-plus', 'me-1') ?>Adicionar</button>
    </div>
</form>
<table class="table table-sm">
    <thead><tr><th>Nome</th><th>Modelos</th><th>Estado</th><th></th></tr></thead>
    <tbody id="supplierListBody"></tbody>
</table>
<?php
$body = (string) ob_get_clean();

ob_start();
?>
<button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Fechar</button>
<?php
$footer = (string) ob_get_clean();

render_modal('supplierModal', 'Fornecedores', $body, $footer);

