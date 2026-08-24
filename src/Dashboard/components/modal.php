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
    $isFullscreen = str_contains($size, 'modal-fullscreen');
    $dialogClass = trim('modal-dialog ' . ($isFullscreen ? '' : 'modal-dialog-centered ') . $size);
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
