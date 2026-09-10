                <aside id="deviceColumn" class="col-12 col-lg-4 d-flex flex-column gap-3">
                    <div class="card">
                        <div class="card-body">
                            <div class="d-flex justify-content-between align-items-center gap-2 flex-wrap">
                                <span class="section-label">Dispositivo</span>
                                <div class="d-flex align-items-center gap-2">
                                        <button id="openDeviceSelectorBtn" class="btn btn-sm btn-primary" type="button">
                                            <i class="fa-solid fa-list me-1"></i>
                                            Escolher
                                        </button>
                                    <button id="addDeviceBtn" class="btn btn-sm btn-outline-secondary" type="button" title="Adicionar dispositivo" aria-label="Adicionar dispositivo"><?= icon('fa-plus') ?></button>
                                </div>
                            </div>
                            <div id="deviceSelectionEmptyState" class="text-center text-secondary py-5">
                                <?= icon('fa-tablet-screen-button', 'fs-1 opacity-25') ?>
                                <h1 class="h5 mt-3">Selecione um dispositivo</h1>
                                <p class="small mb-3">Escolha um dispositivo para ver o resumo operacional, pedir dados e analisar a atividade recente.</p>
                                <button id="emptyStateSelectDeviceBtn" class="btn btn-primary" type="button"><?= icon('fa-list', 'me-1') ?>Escolher dispositivo</button>
                            </div>
                            <div id="selectedDevicePanel" class="d-none">
                                <div class="d-flex align-items-start gap-3 pt-2 pt-sm-3">
                                    <div id="selectedDevicePreview" class="selected-device-preview d-flex align-items-center justify-content-center flex-shrink-0 text-center rounded-4"></div>
                                    <div class="min-w-0 flex-grow-1">
                                        <div class="mb-1" id="selectedDeviceBadge"></div>
                                        <h1 class="h4 mb-1 text-break tabular-nums lh-sm" id="selectedDeviceTitle"></h1>
                                        <div id="selectedDeviceMeta" class="text-secondary small"></div>
                                    </div>
                                </div>
                                <dl id="selectedDeviceFacts" class="selected-device-facts row g-2 g-sm-3 mb-0 border-top mt-2 pt-2 mt-sm-3 pt-sm-3"></dl>
                                <div class="border-top mt-2 pt-2 mt-sm-3 pt-sm-3 d-flex justify-content-end">
                                    <button id="selectedDeviceEditBtn" class="btn btn-sm btn-outline-secondary" type="button"><?= icon('fa-pen', 'me-1') ?>Editar</button>
                                </div>
                            </div>
                        </div>
                    </div>
                    <?php /* `card-flush-sm`: em telefone a moldura de fora desaparece, porque o
                           * Bootstrap não tem `border-sm` nem `bg-sm-*`. */ ?>
                    <?php /* O cartão dos mosaicos absorve o resto da coluna: o da identidade
                           * tem a altura dos factos que mostra. */ ?>
                    <div class="card card-flush-sm flex-fill" id="requestCardsCard">
                        <div class="card-body p-0 p-sm-3">
                            <div class="d-grid telemetry-card-grid gap-2 gap-sm-3" id="requestGrid"></div>
                        </div>
                    </div>
                    <div class="card d-none" id="ncsEventSection">
                        <div class="card-body">
                            <?= section_header('Eventos NCS recentes') ?>
                            <div class="d-grid telemetry-card-grid gap-3" id="ncsEventGrid"></div>
                        </div>
                    </div>
                </aside>
