<?php

ob_start();
?>
<div class="d-flex flex-column gap-4">
    <div class="d-flex flex-column gap-2">
        <div id="wizardProgress"></div>
        <div class="wizard-trail d-flex align-items-center flex-wrap gap-2" id="wizardTrail"></div>
    </div>

    <div class="wizard-stage d-grid gap-4 align-items-start">
        <div class="wizard-ask" id="wizardAsk"></div>
        <div class="wizard-art d-none border rounded-3 bg-body-tertiary p-3 gap-2" id="wizardArt"></div>
    </div>

    <div class="alert alert-danger py-2 px-3 small mb-0 d-none" id="wizardError" role="alert"></div>
</div>
<?php
$body = (string) ob_get_clean();

$header = '<h5 class="modal-title" id="deviceWizardModalLabel">Adicionar dispositivo</h5>'
    . '<span class="ms-auto me-2 text-secondary text-nowrap" id="wizardStepCount"></span>';

$footer = '<button type="button" class="btn btn-outline-secondary" id="wizardBackBtn"></button>'
    . '<button type="button" class="btn btn-primary" id="wizardNextBtn"></button>';

render_modal(
    id: 'deviceWizardModal',
    title: 'Adicionar dispositivo',
    body: $body,
    footer: $footer,
    size: 'lg',
    fullscreenBelow: 'md',
    staticBackdrop: true,
    headerHtml: $header,
);
