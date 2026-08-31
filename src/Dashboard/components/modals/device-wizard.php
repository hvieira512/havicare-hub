<?php

declare(strict_types=1);

ob_start();
?>
<div class="d-flex flex-column gap-4">
    <div class="wizard-trail" id="wizardTrail" role="progressbar" aria-valuemin="1" aria-valuemax="2" aria-valuenow="1"></div>

    <div class="wizard-stage">
        <div class="wizard-ask" id="wizardAsk"></div>
        <div class="wizard-art d-none" id="wizardArt"></div>
    </div>

    <div class="alert alert-danger py-2 px-3 small mb-0 d-none" id="wizardError" role="alert"></div>
</div>
<?php
$body = (string) ob_get_clean();

$footer = '<button type="button" class="btn btn-outline-secondary" id="wizardBackBtn">'
    . icon('fa-arrow-left', 'me-2') . 'Anterior</button>'
    . '<button type="button" class="btn btn-primary" id="wizardNextBtn">Seguinte'
    . icon('fa-arrow-right', 'ms-2') . '</button>';

render_modal('deviceWizardModal', 'Adicionar dispositivo', $body, $footer, 'modal-lg modal-fullscreen-md-down');
