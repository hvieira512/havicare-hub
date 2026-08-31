<?php

declare(strict_types=1);

function render_modal(
    string $id,
    string $title,
    string $body,
    string $footer = '',
    string $size = '',
    string $headerHtml = '',
    // Classes do corpo: `p-0` para quem prefere que o conteúdo traga o seu próprio padding.
    string $bodyClass = '',
    // Classes do `modal-content`. Serve para o `h-100`: com `modal-dialog-scrollable` o
    // diálogo já mede a altura toda, mas o Bootstrap dá ao conteúdo `max-height: 100%` e não
    // `height`, portanto ele encolhe até ao que lá está dentro. Um separador curto e outro
    // comprido davam dois modais de alturas diferentes, e trocar de separador fazia a caixa
    // saltar debaixo do rato.
    string $contentClass = ''
): void {
    // Só o ecrã inteiro em todas as larguras é que dispensa o centrado. O
    // `modal-fullscreen-md-down` contém a mesma string mas só é ecrã inteiro em telefone,
    // e a comparação por substring tirava-lhe o centrado também em ecrã largo.
    $isAlwaysFullscreen = in_array('modal-fullscreen', explode(' ', $size), true);
    $dialogClass = trim('modal-dialog ' . ($isAlwaysFullscreen ? '' : 'modal-dialog-centered ') . $size);
    $titleMarkup = $headerHtml !== ''
        ? $headerHtml
        : '<h5 class="modal-title" id="' . h($id) . 'Label">' . h($title) . '</h5>';
    ?>
    <div class="modal fade" id="<?= h($id) ?>" tabindex="-1">
        <div class="<?= h($dialogClass) ?>">
            <div class="<?= h(trim('modal-content ' . $contentClass)) ?>">
                <div class="modal-header">
                    <?= $titleMarkup ?>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="<?= h(trim('modal-body ' . $bodyClass)) ?>">
                    <?= $body ?>
                </div>
                <div class="modal-footer">
                    <?= $footer ?>
                </div>
            </div>
        </div>
    </div>
    <?php
}
