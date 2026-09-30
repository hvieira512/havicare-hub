<?php

function render_modal(
    string $id,
    string $body,
    string $title = '',
    string $footer = '',
    string $size = '',
    ?string $fullscreenBelow = null,
    bool $scrollable = false,
    bool $staticBackdrop = false,
    string $headerHtml = '',
    string $bodyClass = '',
    string $contentClass = '',
    string $footerClass = ''
): void {
    $dialog = array_filter([
        'modal-dialog',
        'modal-dialog-centered',
        $scrollable ? 'modal-dialog-scrollable' : '',
        $size !== '' ? "modal-{$size}" : '',
        $fullscreenBelow !== null ? "modal-fullscreen-{$fullscreenBelow}-down" : '',
    ]);

    $backdrop = $staticBackdrop ? ' data-bs-backdrop="static" data-bs-keyboard="false"' : '';
    ?>
    <div class="modal fade" id="<?= h($id) ?>" tabindex="-1" aria-labelledby="<?= h($id) ?>Label"<?= $backdrop ?>>
        <div class="<?= h(implode(' ', $dialog)) ?>">
            <div class="<?= h(trim('modal-content ' . $contentClass)) ?>">
                <div class="modal-header">
                    <?= $headerHtml !== ''
                        ? $headerHtml
                        : '<h5 class="modal-title" id="' . h($id) . 'Label">' . h($title) . '</h5>' ?>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Fechar"></button>
                </div>
                <div class="<?= h(trim('modal-body ' . $bodyClass)) ?>">
                    <?= $body ?>
                </div>
                <?php if ($footer !== '') : ?>
                <div class="<?= h(trim('modal-footer ' . $footerClass)) ?>">
                    <?= $footer ?>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
    <?php
}
