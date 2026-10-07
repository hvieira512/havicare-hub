                <?php
                $activityPanels = [
                    [
                        'column' => 'telemetryColumn',
                        'title' => 'Leituras',
                        'tabCountId' => 'telemetryTabCount',
                        'countId' => 'telemetryCount',
                        'pager' => 'telemetryPager',
                        'loadMore' => 'telemetryLoadMore',
                        'list' => 'telemetryList',
                        'spacing' => '',
                    ],
                    [
                        'column' => 'downlinkColumn',
                        'title' => 'Pedidos',
                        'tabCountId' => 'downlinkTabCount',
                        'countId' => 'downlinkRequestCount',
                        'pager' => 'downlinkPager',
                        'loadMore' => 'downlinkLoadMore',
                        'list' => 'downlinkRequests',
                        'spacing' => 'border-start-xl ps-xl-3',
                    ],
                ];
                ?>
                <section id="detailColumn" class="col-12 col-lg-8">
                    <div class="card h-100">
                        <div class="card-body d-flex flex-column min-h-0">
                            <div id="deviceDetail" class="d-none device-detail-open">
                                <div>
                                    <div class="d-flex align-items-center gap-2">
                                        <?= search_input('detailSearch', 'Procurar', 'flex-grow-1') ?>
                                        <?= filter_toggle_button('detailFiltersCollapse', 'detailFilterCount', 'flex-shrink-0') ?>
                                    </div>
                                    <div id="detailActiveFiltersRow" class="d-flex flex-wrap align-items-center gap-2 mt-2 d-none">
                                        <div id="detailActiveFilters" class="d-flex flex-wrap gap-2"></div>
                                        <button id="clearDetailFiltersBtn" class="btn btn-link btn-sm p-0 text-decoration-none text-secondary small d-none" type="button">Limpar</button>
                                    </div>
                                    <div class="collapse" id="detailFiltersCollapse">
                                        <div id="detailRangePresets" class="d-flex flex-wrap gap-2 pt-3">
                                            <?php foreach (['today' => 'Hoje', '7d' => '7 dias', '30d' => '30 dias'] as $range => $label) : ?>
                                            <button class="btn btn-sm btn-outline-secondary rounded-pill" type="button" data-detail-range="<?= h($range) ?>" aria-pressed="false"><?= h($label) ?></button>
                                            <?php endforeach; ?>
                                            <button class="btn btn-sm btn-outline-secondary rounded-pill" type="button" data-bs-toggle="collapse" data-bs-target="#detailFilterDates" aria-expanded="false" aria-controls="detailFilterDates">Datas&hellip;</button>
                                            <label for="detailFilterType" class="visually-hidden">Tipo</label>
                                            <select id="detailFilterType" class="form-select form-select-sm w-auto mw-100 ms-auto">
                                                <option value="all">Todos os tipos</option>
                                            </select>
                                        </div>
                                        <div class="collapse" id="detailFilterDates">
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
                                                    <button id="applyDetailFiltersBtn" class="btn btn-sm btn-primary"><?= icon('fa-check', 'me-1') ?>Aplicar</button>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                                <div class="d-flex flex-column flex-fill min-h-0">
                                    <section class="card-section mt-3 pt-3 border-top flex-shrink-0">
                                        <div id="connectionHistory"></div>
                                    </section>
                                    <div class="card-section activity-section mt-3 pt-lg-3 d-flex flex-column flex-grow-1 min-h-0">
                                        <div class="nav nav-pills activity-tabs d-xl-none flex-nowrap gap-2 mb-3" id="activityTabs" role="tablist">
                                            <?php foreach ($activityPanels as $index => $panel) : ?>
                                            <button class="nav-link<?= $index === 0 ? ' active' : '' ?> flex-fill d-flex align-items-center justify-content-center gap-2" id="<?= $panel['column'] ?>Tab" data-bs-toggle="pill" data-bs-target="#<?= $panel['column'] ?>" type="button" role="tab" aria-controls="<?= $panel['column'] ?>" aria-selected="<?= $index === 0 ? 'true' : 'false' ?>"><?= h($panel['title']) ?><span class="badge text-bg-secondary rounded-pill" id="<?= $panel['tabCountId'] ?>"></span></button>
                                            <?php endforeach; ?>
                                        </div>
                                        <div class="tab-content activity-tab-content row g-0 flex-grow-1 min-h-0">
                                            <?php foreach ($activityPanels as $index => $panel) : ?>
                                            <div id="<?= $panel['column'] ?>" class="tab-pane<?= $index === 0 ? ' show active' : '' ?> col-12 col-xl-6 flex-column min-h-0 <?= $panel['spacing'] ?>" role="tabpanel">
                                                <div class="d-none d-lg-flex flex-column flex-xl-row justify-content-xl-between align-items-xl-center gap-2 mb-2">
                                                    <div class="d-none d-xl-block"><?= section_header($panel['title'], $panel['countId'], '') ?></div>
                                                    <?= pagination_component($panel['pager'], '', true) ?>
                                                </div>
                                                <div id="<?= $panel['list'] ?>" class="flex-grow-1"></div>
                                                <div id="<?= $panel['loadMore'] ?>" class="d-grid d-lg-none"></div>
                                            </div>
                                            <?php endforeach; ?>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </section>
