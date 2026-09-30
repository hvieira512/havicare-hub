        <div id="deviceBand" class="device-band d-none d-lg-none">
            <div class="container-fluid d-flex align-items-center gap-2 pb-2">
                <button id="deviceBandSelectBtn" class="device-band-identity d-flex align-items-center gap-2 text-start min-w-0 flex-grow-1" type="button" aria-label="Escolher outro dispositivo" title="Escolher outro dispositivo">
                    <span id="deviceBandThumb" class="device-band-thumb d-flex align-items-center justify-content-center flex-shrink-0 rounded-2"></span>
                    <span class="min-w-0 flex-grow-1">
                        <span id="deviceBandTitle" class="device-band-title d-block fw-semibold text-truncate tabular-nums"></span>
                        <span class="device-band-meta d-flex align-items-center gap-2">
                            <span id="deviceBandDot" class="device-band-dot rounded-circle flex-shrink-0" role="img"></span>
                            <span id="deviceBandMeta" class="text-truncate"></span>
                        </span>
                    </span>
                    <?= icon('fa-chevron-down', 'device-band-caret flex-shrink-0') ?>
                </button>
                <button id="deviceBandEditBtn" class="btn btn-dark device-band-btn flex-shrink-0" type="button" aria-label="Editar dispositivo e configurações" title="Editar dispositivo e configurações"><?= icon('fa-pen') ?></button>
            </div>
        </div>
        <div id="deviceTabs" class="nav device-tabs flex-nowrap d-none d-lg-none" role="tablist">
            <button id="deviceTabTelemetry" class="nav-link active flex-fill" type="button" role="tab" aria-selected="true">Telemetria</button>
            <button id="deviceTabReadings" class="nav-link flex-fill" type="button" role="tab" aria-selected="false" data-bs-target="#telemetryColumn" aria-controls="telemetryColumn">Leituras<span id="deviceTabReadingsCount" class="device-tab-count"></span></button>
            <button id="deviceTabRequests" class="nav-link flex-fill" type="button" role="tab" aria-selected="false" data-bs-target="#downlinkColumn" aria-controls="downlinkColumn">Pedidos<span id="deviceTabRequestsCount" class="device-tab-count"></span></button>
        </div>
