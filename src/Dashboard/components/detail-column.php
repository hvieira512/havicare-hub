                <?php
                $activityPanels = [
                    [
                        'column' => 'telemetryColumn',
                        'title' => 'Eventos recebidos',
                        'countId' => 'telemetryCount',
                        'pager' => 'telemetryPager',
                        'list' => 'telemetryList',
                        'spacing' => 'pe-xl-4',
                    ],
                    [
                        'column' => 'downlinkColumn',
                        'title' => 'Pedidos ao dispositivo',
                        'countId' => 'downlinkRequestCount',
                        'pager' => 'downlinkPager',
                        'list' => 'downlinkRequests',
                        'spacing' => 'border-start-xl ps-xl-4 mt-4 mt-xl-0',
                    ],
                ];
                ?>
                <section id="detailColumn" class="col-12 col-lg-8">
                    <div class="card h-100">
                        <div class="card-body d-flex flex-column min-h-0">
                            <div id="deviceDetail" class="d-none device-detail-open">
                                <div>
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
                                                <label for="detailFilterFrom" class="section-label">De</label>
                                                <input type="datetime-local" id="detailFilterFrom" class="form-control form-control-sm">
                                            </div>
                                            <div class="col-auto">
                                                <label for="detailFilterTo" class="section-label">Até</label>
                                                <input type="datetime-local" id="detailFilterTo" class="form-control form-control-sm">
                                            </div>
                                            <div class="col-auto">
                                                <label for="detailFilterType" class="section-label">Tipo</label>
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
                                <div class="d-flex flex-column flex-fill min-h-0">
                                    <section id="connectionSection" class="card-section mt-3 pt-3 border-top flex-shrink-0">
                                        <?= section_header('Ligações ao servidor') ?>
                                        <div id="connectionTimeline"></div>
                                    </section>
                                    <div class="card-section mt-3 pt-3 border-top row g-0 flex-grow-1 min-h-0">
                                        <?php foreach ($activityPanels as $panel) : ?>
                                        <div id="<?= $panel['column'] ?>" class="col-12 col-xl-6 d-flex flex-column min-h-0 <?= $panel['spacing'] ?>">
                                            <div class="d-flex flex-column flex-sm-row justify-content-sm-between align-items-sm-center gap-2 mb-2">
                                                <?= section_header($panel['title'], $panel['countId'], '') ?>
                                                <?= pagination_component($panel['pager'], '', false) ?>
                                            </div>
                                            <div id="<?= $panel['list'] ?>" class="activity-list flex-grow-1 min-h-0 overflow-auto"></div>
                                        </div>
                                        <?php endforeach; ?>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </section>
