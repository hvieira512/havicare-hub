<?php

declare(strict_types=1);

/**
 * O modal de escolha de dispositivo, em duas colunas.
 *
 * Os cinco selects viviam dentro de um `collapse`: para saber o que se podia filtrar era
 * preciso abrir, e para mudar dois filtros era preciso abrir, mudar e fechar outra vez para
 * ver a lista. Num modal com largura de sobra à esquerda, esconder os filtros não pagava
 * nada — passam a uma coluna de 4/12 sempre visível, e a lista fica nas outras 8.
 *
 * Todos os filtros aceitam vários valores, menos o estado: escolher "ligados e desligados"
 * é escolher todos, que já é a terceira opção. É por isso que os selects saem — um select
 * nativo não faz escolha múltipla de forma utilizável.
 */

ob_start();
?>
<div class="row g-0 device-selector-body">
    <?php
    /**
     * A coluna dos filtros rola por si. Com seis grupos de escolha múltipla ela é mais alta
     * que o modal, e prender as duas colunas ao mesmo scroll obrigava a descer a lista de
     * dispositivos para chegar ao último filtro.
     */
    ?>
    <?php
    /**
     * Abaixo de lg a coluna não cabe, e os filtros voltam a ser uma folha atrás de um botão
     * — e aí o botão com o contador faz sentido, porque aí eles estão de facto escondidos.
     * Sem isto, um telefone abria o modal com o primeiro ecrã todo de filtros e a lista de
     * dispositivos por baixo do fundo.
     *
     * `d-lg-block` força a coluna a estar visível a partir de lg, seja qual for o estado do
     * `collapse` — é o mesmo padrão da barra de navegação do Bootstrap.
     */
    ?>
    <div class="col-12 d-lg-none mb-3">
        <button class="btn btn-sm btn-outline-secondary d-inline-flex align-items-center gap-2" type="button"
            data-bs-toggle="collapse" data-bs-target="#deviceFilterPanel" aria-expanded="false" aria-controls="deviceFilterPanel">
            <?= icon('fa-sliders') ?>Filtros
            <span id="deviceFilterCountMobile" class="count-chip count-chip-strong d-none"></span>
        </button>
    </div>
    <aside id="deviceFilterPanel" class="col-12 col-lg-4 device-filter-column collapse d-lg-block">
        <div class="d-flex align-items-center justify-content-between gap-2 mb-3">
            <div class="d-flex align-items-center gap-2">
                <span class="section-label">Filtros</span>
                <span id="deviceFilterCount" class="count-chip count-chip-strong d-none"></span>
            </div>
            <?php /* Uma acção que desfaz o trabalho de seis filtros é um controlo, e não uma nota de pé de página. */ ?>
            <button id="clearDeviceFiltersBtn" class="btn btn-sm btn-outline-secondary d-none" type="button">
                <?= icon('fa-filter-circle-xmark', 'me-1') ?>Limpar
            </button>
        </div>

        <div class="filter-group">
            <span class="section-label d-block mb-2">Estado</span>
            <div class="btn-group w-100" role="group" aria-label="Estado de ligação">
                <input type="radio" class="btn-check" name="deviceOnlineFilter" id="deviceOnlineAll" value="all" autocomplete="off" checked>
                <label class="btn btn-sm btn-outline-secondary" for="deviceOnlineAll">Todos</label>
                <input type="radio" class="btn-check" name="deviceOnlineFilter" id="deviceOnlineOn" value="online" autocomplete="off">
                <label class="btn btn-sm btn-outline-secondary" for="deviceOnlineOn">Ligados</label>
                <input type="radio" class="btn-check" name="deviceOnlineFilter" id="deviceOnlineOff" value="offline" autocomplete="off">
                <label class="btn btn-sm btn-outline-secondary" for="deviceOnlineOff">Desligados</label>
            </div>
        </div>

        <?php
        /**
         * O tipo usa a mesma grelha de mosaicos que o assistente de "Adicionar dispositivo",
         * a aceitar vários. Escolher o tipo de dispositivo passa a ser o mesmo gesto nos dois
         * sítios, em vez de ser uma lista num e um mosaico no outro.
         */
        ?>
        <div class="filter-group">
            <div class="d-flex align-items-center gap-2 mb-2">
                <span class="section-label">Tipo</span>
                <span id="deviceTypeFilterCount" class="count-chip d-none"></span>
            </div>
            <div id="deviceTypeFilter" class="device-type-grid"></div>
        </div>

        <div class="filter-group">
            <div class="d-flex align-items-center gap-2 mb-2">
                <span class="section-label">Fornecedor</span>
                <span id="deviceSupplierFilterCount" class="count-chip d-none"></span>
            </div>
            <div id="deviceSupplierFilter" class="filter-list"></div>
        </div>

        <div class="filter-group">
            <div class="d-flex align-items-center gap-2 mb-2">
                <span class="section-label">Modelo</span>
                <span id="deviceModelFilterCount" class="count-chip d-none"></span>
            </div>
            <?php /* Só o modelo leva pesquisa: nos cinco tipos seria um campo para procurar entre cinco palavras. */ ?>
            <div class="input-group input-group-sm mb-2">
                <span class="input-group-text"><?= icon('fa-magnifying-glass') ?></span>
                <input id="deviceModelFilterSearch" type="search" class="form-control" placeholder="Procurar modelo">
            </div>
            <div id="deviceModelFilter" class="filter-list"></div>
        </div>

        <?php
        /**
         * A empresa e a licença são um filtro só, em árvore, porque o domínio é uma árvore:
         * uma licença pertence a uma empresa, e um dispositivo tem as duas ou nenhuma. Como
         * duas listas independentes, escolher {A, B} e {1, 2} trazia também A com a licença
         * 2 — o filtro prometia uma coisa e devolvia outra.
         */
        ?>
        <div class="filter-group">
            <div class="d-flex align-items-center gap-2 mb-2">
                <span class="section-label">Licença</span>
                <span id="deviceLicenseFilterCount" class="count-chip d-none"></span>
            </div>
            <div id="deviceLicenseFilter" class="filter-list"></div>
        </div>
    </aside>

    <section class="col-12 col-lg-8 device-list-column">
        <div class="input-group input-group-sm mb-3">
            <span class="input-group-text"><?= icon('fa-magnifying-glass') ?></span>
            <input id="deviceListSearch" type="search" class="form-control" placeholder="Procurar IMEI, fornecedor ou modelo">
        </div>
        <div id="deviceList" class="device-card-list"></div>
        <div class="d-flex justify-content-between align-items-center gap-2 mt-3 flex-wrap">
            <?= pagination_component('deviceListPagination') ?>
            <div class="d-flex align-items-center gap-2 ms-auto">
                <label for="deviceListLimit" class="section-label mb-0">Por página</label>
                <select id="deviceListLimit" class="form-select form-select-sm w-auto">
                    <option value="5">5</option>
                    <option value="10">10</option>
                    <option value="15">15</option>
                    <option value="20">20</option>
                    <option value="30">30</option>
                    <option value="50">50</option>
                </select>
            </div>
        </div>
    </section>
</div>
<?php
$body = (string) ob_get_clean();

ob_start();
?>
<button type="button" class="btn btn-outline-primary" id="openAddDeviceFromSelectorBtn"><?= icon('fa-plus', 'me-1') ?>Adicionar dispositivo</button>
<button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Fechar</button>
<?php
$footer = (string) ob_get_clean();

ob_start();
?>
<div class="flex-grow-1 min-width-0">
    <h2 class="modal-title h5 mb-0">Escolher dispositivo</h2>
    <?php
    /**
     * A contagem é do total e não do filtrado, e é isso que a torna gratuita: não muda
     * quando se filtra, logo não obriga a perguntar ao servidor a cada caixa que se marca.
     */
    ?>
    <div id="deviceSelectorSummary" class="small text-secondary"></div>
</div>
<?php
$headerHtml = (string) ob_get_clean();

/**
 * `modal-fullscreen-lg-down` e não `md-down`: é a `lg` que as duas colunas deixam de
 * funcionar, porque a 768px a coluna dos filtros fica com 256px. O ponto de rutura fica
 * onde o desenho parte, e não onde partia o desenho anterior.
 */
render_modal(
    'deviceSelectorModal',
    'Escolher dispositivo',
    $body,
    $footer,
    'modal-xl modal-fullscreen-lg-down',
    $headerHtml
);
