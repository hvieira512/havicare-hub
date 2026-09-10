<?php

/**
 * A casca de um modal. O `$headerHtml` substitui o título quando o cabeçalho é mais do que
 * uma linha de texto.
 */
function render_modal(
    string $id,
    string $title,
    string $body,
    string $footer = '',
    string $size = '',
    ?string $fullscreenBelow = null,
    bool $scrollable = false,
    bool $centered = true,
    bool $staticBackdrop = false,
    string $headerHtml = '',
    string $bodyClass = '',
    string $contentClass = ''
): void {
    $dialog = array_filter([
        'modal-dialog',
        $centered ? 'modal-dialog-centered' : '',
        $scrollable ? 'modal-dialog-scrollable' : '',
        $size !== '' ? "modal-{$size}" : '',
        $fullscreenBelow !== null ? "modal-fullscreen-{$fullscreenBelow}-down" : '',
    ]);

    // O Bootstrap lê estes dois do próprio elemento ao criar a instância; sem eles, um clique
    // fora ou um Escape fecham a caixa e levam o que estiver preenchido lá dentro.
    $backdrop = $staticBackdrop ? ' data-bs-backdrop="static" data-bs-keyboard="false"' : '';
    ?>
    <div class="modal fade" id="<?= h($id) ?>" tabindex="-1"<?= $backdrop ?>>
        <div class="<?= h(implode(' ', $dialog)) ?>">
            <div class="<?= h(trim('modal-content ' . $contentClass)) ?>">
                <div class="modal-header">
                    <?= $headerHtml !== ''
                        ? $headerHtml
                        : '<h5 class="modal-title" id="' . h($id) . 'Label">' . h($title) . '</h5>' ?>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="<?= h(trim('modal-body ' . $bodyClass)) ?>">
                    <?= $body ?>
                </div>
                <?php if ($footer !== '') : ?>
                <div class="modal-footer">
                    <?= $footer ?>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
    <?php
}
