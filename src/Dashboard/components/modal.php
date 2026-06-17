<?php

declare(strict_types=1);

function render_modal(string $id, string $title, string $body, string $footer = '', string $size = ''): void
{
    $isFullscreen = str_contains($size, 'modal-fullscreen');
    $dialogClass = trim('modal-dialog ' . ($isFullscreen ? '' : 'modal-dialog-centered ') . $size);
    ?>
    <div class="modal fade" id="<?= h($id) ?>" tabindex="-1">
        <div class="<?= h($dialogClass) ?>">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="<?= h($id) ?>Label"><?= h($title) ?></h5>
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
