<?php

declare(strict_types=1);

/**
 * A moldura de um modal.
 *
 * O `$headerHtml` é a identidade do que se está a editar, quando existe: um modal que diz
 * só «Editar dispositivo» obriga a ler o campo do IMEI, a meio do formulário, para saber
 * qual. Quando é passado, substitui o título — não se acrescenta a ele.
 */
function render_modal(
    string $id,
    string $title,
    string $body,
    string $footer = '',
    string $size = '',
    string $headerHtml = ''
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
            <div class="modal-content">
                <div class="modal-header">
                    <?= $titleMarkup ?>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
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
