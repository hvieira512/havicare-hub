                <?php /* A coluna direita: o detalhe do dispositivo -- filtros, ligações, telemetria e pedidos. */ ?>
                <section id="detailColumn" class="col-12 col-lg-8">
                    <div class="card h-100">
                        <div class="card-body d-flex flex-column min-h-0">
                            <div id="deviceDetail" class="d-none device-detail-open">
                                <div id="detailFiltersPanel">
                                    <div class="d-flex align-items-center gap-2">
                                        <?= search_input('detailSearch', 'Procurar na atividade', 'flex-grow-1') ?>
                                        <?= filter_toggle_button('detailFiltersCollapse', 'detailFilterCount', 'flex-shrink-0') ?>
                                    </div>
                                    <div id="detailActiveFiltersRow" class="d-flex flex-wrap align-items-center gap-2 mt-2 d-none">
                                        <div id="detailActiveFilters" class="d-flex flex-wrap gap-2"></div>
                                        <button id="clearDetailFiltersBtn" class="btn btn-link btn-sm p-0 text-decoration-none text-secondary small d-none" type="button">Limpar</button>
                                    </div>
                                    <div class="collapse" id="detailFiltersCollapse">
                                        <div class="row g-2 align-items-end pt-3">
                                            <div class="col-auto">
                                                <label for="detailFilterFrom" class="section-label d-block mb-1">De</label>
                                                <input type="datetime-local" id="detailFilterFrom" class="form-control form-control-sm">
                                            </div>
                                            <div class="col-auto">
                                                <label for="detailFilterTo" class="section-label d-block mb-1">Até</label>
                                                <input type="datetime-local" id="detailFilterTo" class="form-control form-control-sm">
                                            </div>
                                            <div class="col-auto">
                                                <label for="detailFilterType" class="section-label d-block mb-1">Tipo</label>
                                                <select id="detailFilterType" class="form-select form-select-sm">
                                                    <option value="all">Todos</option>
                                                </select>
                                            </div>
                                            <div class="col-auto">
                                                <button id="applyDetailFiltersBtn" class="btn btn-sm btn-primary"><?= icon('fa-check', 'me-1') ?>Aplicar</button>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                                <div class="device-detail-stack d-flex flex-column">
                                    <section id="connectionSection" class="card-section flex-shrink-0">
                                        <?= section_header('Ligações ao servidor') ?>
                                        <div id="connectionTimeline"></div>
                                    </section>
                                    <div class="card-section row g-0 flex-grow-1 min-h-0">
                                        <?php /* `min-h-0` nas duas colunas: um item de flex não encolhe abaixo do
                                               * conteúdo, e sem isto a lista empurrava a coluna, a coluna empurrava
                                               * o cartão e o `overflow-auto` da lista nunca tinha o que rolar. */ ?>
                                        <?php /* O `col-xl-6` e o `pe-xl-4` saem e voltam pelo
                                               * `renderDownlinkRequests`: sem pedidos, os
                                               * eventos ficam com a linha toda. */ ?>
                                        <div id="telemetryColumn" class="col-12 col-xl-6 d-flex flex-column min-h-0 pe-xl-4">
                                            <?= section_header('Eventos recebidos', 'telemetryCount', true) ?>
                                            <?php /* O paginador fica entre o título e a lista: em baixo era empurrado
                                                   * para o fundo da coluna pelo `flex-grow-1` da lista, e numa página
                                                   * com poucas linhas ficava a metros do conteúdo que pagina.
                                                   *
                                                   * Sem resumo: o total já está na pastilha ao lado do título. */ ?>
                                            <?= pagination_component('telemetryPager', 'mb-2', false) ?>
                                            <div id="telemetryList" class="activity-list flex-grow-1 min-h-0 overflow-auto"></div>
                                        </div>
                                        <div id="downlinkColumn" class="col-12 col-xl-6 d-flex flex-column min-h-0 border-start-xl ps-xl-4 mt-4 mt-xl-0">
                                            <?= section_header('Pedidos ao dispositivo', 'downlinkRequestCount', true) ?>
                                            <?= pagination_component('downlinkPager', 'mb-2', false) ?>
                                            <div id="downlinkRequests" class="activity-list flex-grow-1 min-h-0 overflow-auto"></div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </section>
